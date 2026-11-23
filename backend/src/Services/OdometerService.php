<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{ApiException, Authz, Clock, Db};

/** قراءات العداد لكل مرحلة مركبة (Vehicle Segment): بداية/نهاية، تبديل المركبة، إغلاق الرحلة. */
final class OdometerService
{
    private Db $db;
    public function __construct(private App $app) { $this->db = $app->db; }

    private function openSegment(int $driverId, ?int $segmentId): array
    {
        $seg = $segmentId
            ? $this->db->one('SELECT s.* FROM vehicle_segments s JOIN trips t ON t.id=s.trip_id WHERE s.id=? AND t.driver_id=?', [$segmentId, $driverId])
            : $this->db->one("SELECT s.* FROM vehicle_segments s JOIN trips t ON t.id=s.trip_id WHERE t.driver_id=? AND t.status='active' AND s.status='open'", [$driverId]);
        if (!$seg) { throw new ApiException(404, 'segment_not_found', 'لا توجد مرحلة مركبة مفتوحة'); }
        return $seg;
    }

    private function photoRequired(array $seg): bool
    {
        if ($this->app->settings->bool('odometer.photo_required')) { return true; }
        if ($this->db->val('SELECT require_odometer_photo FROM vehicles WHERE id=?', [$seg['vehicle_id']])) { return true; }
        return (bool)$this->db->val('SELECT 1 FROM trip_missions tm JOIN missions m ON m.id=tm.mission_id WHERE tm.trip_id=? AND m.odometer_photo_required=1 LIMIT 1', [$seg['trip_id']]);
    }

    /** قراءة من السائق. تخفض عن السابق → ترفض (الاستثناء بيد الإدارة فقط). */
    public function record(int $driverId, ?int $segmentId, string $kind, float $value, int $measuredAt, ?int $enteredAt, string $eventId, ?int $overrideUserId = null, ?string $overrideReason = null): array
    {
        if (!in_array($kind, ['start', 'end'], true)) { throw new ApiException(422, 'validation_failed', 'نوع القراءة غير صالح'); }
        if ($value < 0 || $value > 99999999) { throw new ApiException(422, 'validation_failed', 'قيمة العداد غير منطقية'); }
        return $this->db->tx(function () use ($driverId, $segmentId, $kind, $value, $measuredAt, $enteredAt, $eventId, $overrideUserId, $overrideReason) {
            $seg = $this->openSegment($driverId, $segmentId);
            $existing = $this->db->one('SELECT * FROM vehicle_odometer_readings WHERE client_event_id=?', [$eventId]);
            if ($existing) { return ['reading_id' => (int)$existing['id'], 'duplicate' => true]; }
            if ($this->db->val('SELECT 1 FROM vehicle_odometer_readings WHERE segment_id=? AND kind=?', [$seg['id'], $kind])) {
                throw new ApiException(409, 'reading_exists', 'قراءة ' . ($kind === 'start' ? 'البداية' : 'النهاية') . ' مسجلة لهذه المرحلة');
            }
            $last = $this->db->val('SELECT last_odometer FROM vehicles WHERE id=?', [$seg['vehicle_id']]);
            $floor = $kind === 'start' ? $last : ($seg['start_odometer'] ?? $last);
            $exception = null;
            if ($floor !== null && $value < (float)$floor) {
                if (!$overrideUserId || !$overrideReason) {
                    throw new ApiException(409, 'odometer_decrease', 'القراءة أقل من السابقة (' . $floor . ')؛ يلزم استثناء إداري موثق', ['previous' => (float)$floor]);
                }
                $exception = $overrideReason;
            }
            $photoReq = $this->photoRequired($seg);
            $rid = $this->db->insert('vehicle_odometer_readings', [
                'segment_id' => $seg['id'], 'vehicle_id' => $seg['vehicle_id'], 'kind' => $kind, 'value' => $value,
                'measured_at' => Clock::fmt($measuredAt), 'entered_at' => Clock::fmt($enteredAt ?? $measuredAt), 'synced_at' => Clock::nowStr(),
                'client_event_id' => $eventId, 'decrease_exception_reason' => $exception, 'exception_by' => $exception ? $overrideUserId : null,
                'photo_status' => $photoReq ? 'pending' : 'not_required',
            ]);
            if ($kind === 'start') {
                $this->db->update('vehicle_segments', ['start_odometer' => $value, 'odometer_pending' => 0], 'id=?', [$seg['id']]);
            } else {
                $this->db->update('vehicle_segments', ['end_odometer' => $value], 'id=?', [$seg['id']]);
            }
            if ($exception) { $this->app->audit->log($overrideUserId, 'odometer.decrease_exception', 'vehicle', $seg['vehicle_id'], ['segment' => $seg['id'], 'value' => $value, 'reason' => $exception]); }
            return ['reading_id' => $rid, 'duplicate' => false, 'segment_id' => (int)$seg['id'], 'photo_status' => $photoReq ? 'pending' : 'not_required'];
        });
    }

    /** إدخال إداري (مثلاً استثناء انخفاض). */
    public function adminRecord(array $user, int $segmentId, string $kind, float $value, string $measuredAt, ?string $reason): array
    {
        Authz::require($user, 'odometer.override');
        $seg = $this->db->one('SELECT s.*, t.driver_id FROM vehicle_segments s JOIN trips t ON t.id=s.trip_id WHERE s.id=?', [$segmentId]);
        if (!$seg) { throw new ApiException(404, 'not_found', 'المرحلة غير موجودة'); }
        $veh = $this->db->one('SELECT * FROM vehicles WHERE id=?', [$seg['vehicle_id']]);
        Authz::requireScope($user, $veh);
        if (!$reason) { throw new ApiException(422, 'reason_required', 'سبب الاستثناء مطلوب'); }
        $r = $this->record((int)$seg['driver_id'], $segmentId, $kind, $value, Clock::parse($measuredAt), null, 'admin-' . bin2hex(random_bytes(8)), (int)$user['id'], $reason);
        $this->app->audit->log($user['id'], 'odometer.admin_record', 'segment', $segmentId, ['kind' => $kind, 'value' => $value, 'reason' => $reason]);
        return $r;
    }

    public function refreshReview(int $segmentId): void
    {
        $s = $this->db->one('SELECT * FROM vehicle_segments WHERE id=?', [$segmentId]);
        if (!$s || $s['start_odometer'] === null || $s['end_odometer'] === null) { return; }
        $odo = (int)round(((float)$s['end_odometer'] - (float)$s['start_odometer']) * 1000);
        $thr = (float)$this->app->settings->get('odometer.review_threshold_pct');
        $ref = max($odo, (int)$s['gps_distance_m'], 1);
        // مجرد علامة مراجعة؛ ليست مخالفة تلقائية
        $flag = ($odo > 0 || (int)$s['gps_distance_m'] > 0) && abs($odo - (int)$s['gps_distance_m']) / $ref * 100 > $thr ? 1 : 0;
        $this->db->update('vehicle_segments', ['odometer_distance_m' => $odo, 'review_flag' => $flag], 'id=?', [$segmentId]);
    }

    private function closeSegment(array $seg, float $endValue, int $measuredAt, string $eventId, ?string $reason = null): void
    {
        if ($seg['end_odometer'] === null) {
            $this->record((int)$this->db->val('SELECT driver_id FROM trips WHERE id=?', [$seg['trip_id']]), (int)$seg['id'], 'end', $endValue, $measuredAt, null, $eventId);
        }
        $this->db->update('vehicle_segments', ['status' => 'closed', 'ended_at' => Clock::fmt($measuredAt)], 'id=?', [$seg['id']]);
        $end = $this->db->val('SELECT end_odometer FROM vehicle_segments WHERE id=?', [$seg['id']]);
        $this->db->update('vehicles', ['last_odometer' => $end], 'id=?', [$seg['vehicle_id']]);
    }

    /** إغلاق الرحلة: كل المهام منتهية + قراءة نهاية إلزامية. */
    public function closeTrip(int $driverId, ?string $clientTripId, ?float $endOdometer, ?int $measuredAt, string $eventId): array
    {
        return $this->db->tx(function () use ($driverId, $clientTripId, $endOdometer, $measuredAt, $eventId) {
            $trips = new TripService($this->app);
            $trip = $clientTripId ? $trips->resolve($driverId, $clientTripId) : $this->db->one("SELECT * FROM trips WHERE driver_id=? AND status='active'", [$driverId]);
            if (!$trip) { throw new ApiException(404, 'trip_not_found', 'لا توجد رحلة'); }
            if ($trip['status'] === 'closed') { return ['trip_id' => (int)$trip['id'], 'duplicate' => true]; }
            $active = $trips->activeMissionCount((int)$trip['id']);
            if ($active > 0) { throw new ApiException(409, 'trip_has_active_missions', "لا تزال $active مهمة نشطة؛ التتبع مستمر", ['active_missions' => $active]); }
            $seg = $this->db->one("SELECT * FROM vehicle_segments WHERE trip_id=? AND status='open'", [$trip['id']]);
            $at = $measuredAt ?? Clock::now();
            if ($seg) {
                if ($endOdometer === null && $seg['end_odometer'] === null) { throw new ApiException(422, 'end_odometer_required', 'قراءة عداد النهاية إلزامية لإغلاق الرحلة'); }
                $this->closeSegment($seg, (float)$endOdometer, $at, $eventId);
            }
            $this->db->update('trips', ['status' => 'closed', 'ended_at' => Clock::fmt($at)], 'id=?', [$trip['id']]);
            $trips->reanalyze((int)$trip['id']);
            return ['trip_id' => (int)$trip['id'], 'duplicate' => false];
        });
    }

    /** تبديل المركبة: يغلق المرحلة بقراءة نهاية ويفتح جديدة بقراءة بداية. يجب أن يكون المدير أسند المركبة الجديدة (سجل الاعتماد). */
    public function switchVehicle(int $driverId, int $newVehicleId, float $endOld, float $startNew, int $measuredAt, string $eventId, ?string $reason): array
    {
        return $this->db->tx(function () use ($driverId, $newVehicleId, $endOld, $startNew, $measuredAt, $eventId, $reason) {
            $trip = $this->db->one("SELECT * FROM trips WHERE driver_id=? AND status='active' FOR UPDATE", [$driverId]);
            if (!$trip) { throw new ApiException(404, 'trip_not_found', 'لا توجد رحلة نشطة'); }
            $seg = $this->db->one("SELECT * FROM vehicle_segments WHERE trip_id=? AND status='open'", [$trip['id']]);
            if (!$seg) { throw new ApiException(409, 'no_open_segment', 'لا توجد مرحلة مفتوحة'); }
            if ((int)$seg['vehicle_id'] === $newVehicleId) { throw new ApiException(422, 'same_vehicle', 'المركبة نفسها'); }
            $approval = $this->db->one("SELECT h.* FROM vehicle_assignment_history h JOIN trip_missions tm ON tm.mission_id=h.mission_id
                WHERE tm.trip_id=? AND h.vehicle_id=? AND h.ended_at IS NULL AND h.driver_confirmed_at IS NULL ORDER BY h.id DESC LIMIT 1", [$trip['id'], $newVehicleId]);
            if (!$approval) { throw new ApiException(403, 'switch_not_approved', 'لا يوجد إسناد معتمد من مدير الحركة لهذه المركبة'); }
            if ($endOld < (float)($seg['start_odometer'] ?? 0)) { throw new ApiException(409, 'odometer_decrease', 'قراءة نهاية المركبة القديمة أقل من بدايتها'); }
            $newLast = $this->db->val('SELECT last_odometer FROM vehicles WHERE id=?', [$newVehicleId]);
            if ($newLast !== null && $startNew < (float)$newLast) { throw new ApiException(409, 'odometer_decrease', 'قراءة بداية المركبة الجديدة أقل من سجلها (' . $newLast . ')', ['previous' => (float)$newLast]); }
            $this->closeSegment($seg, $endOld, $measuredAt, $eventId . ':end', $reason);
            $nid = $this->db->insert('vehicle_segments', ['trip_id' => $trip['id'], 'vehicle_id' => $newVehicleId, 'seq' => (int)$seg['seq'] + 1, 'status' => 'open',
                'started_at' => Clock::fmt($measuredAt), 'change_reason' => $reason ?? $approval['reason'], 'approved_by' => $approval['assigned_by'], 'switch_confirmed_at' => Clock::nowStr()]);
            $this->record($driverId, $nid, 'start', $startNew, $measuredAt, null, $eventId . ':start');
            $this->db->update('vehicle_assignment_history', ['driver_confirmed_at' => Clock::nowStr()], 'id=?', [$approval['id']]);
            $this->db->exec('UPDATE trips SET last_received_at=last_received_at WHERE id=?', [$trip['id']]);
            (new TripService($this->app))->reanalyze((int)$trip['id']);
            return ['old_segment_id' => (int)$seg['id'], 'new_segment_id' => $nid];
        });
    }
}
