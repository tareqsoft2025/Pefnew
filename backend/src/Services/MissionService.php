<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{ApiException, Authz, Clock, Db, Validator};

/** آلة حالات المهمة + الإسناد + المسارات. كل التحولات تمر عبر transition(). */
final class MissionService
{
    public const TRANSITIONS = [
        'draft' => ['sent', 'cancelled'],
        'sent' => ['delivered', 'accepted', 'rejected', 'cancelled'],
        'delivered' => ['accepted', 'rejected', 'cancelled'],
        'rejected' => ['draft', 'sent', 'cancelled'],
        'accepted' => ['active', 'cancelled'],
        'active' => ['arrived', 'cancelled'],
        'arrived' => ['completion_pending', 'completed', 'cancelled'],
        'completion_pending' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];
    public const TERMINAL = ['completed', 'cancelled'];
    public const LIVE_FOR_CONFLICT = ['sent', 'delivered', 'accepted', 'active', 'arrived', 'completion_pending'];

    private Db $db;
    public function __construct(private App $app) { $this->db = $app->db; }

    public function get(int $id, bool $lock = false): array
    {
        $m = $this->db->one('SELECT * FROM missions WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if (!$m) { throw new ApiException(404, 'not_found', 'المهمة غير موجودة'); }
        return $m;
    }

    /** مهمة مع تحقق النطاق للمستخدم الإداري. */
    public function getScoped(array $user, int $id): array
    {
        $m = $this->get($id);
        Authz::requireScope($user, $m);
        return $m;
    }

    public function create(array $user, array $in): array
    {
        Authz::require($user, 'missions.create');
        $d = Validator::validate($in, [
            'type' => 'string|max:48', 'title' => 'required|string|max:200', 'description' => 'string|max:5000',
            'priority' => 'in:low,normal,high,urgent', 'scheduled_start' => 'required|datetime', 'scheduled_end' => 'datetime',
            'origin_name' => 'string|max:200', 'origin_lat' => 'lat', 'origin_lon' => 'lon',
            'dest_name' => 'string|max:200', 'dest_lat' => 'lat', 'dest_lon' => 'lon',
            'arrival_radius_m' => 'int|min:20', 'party_info' => 'string|max:2000', 'instructions' => 'string|max:5000',
            'proof_policy' => 'in:optional,photo_required', 'odometer_photo_required' => 'bool',
            'team' => 'string|max:64', 'region' => 'string|max:64', 'fleet' => 'string|max:64',
        ]);
        foreach (['origin', 'dest'] as $p) {
            if ((isset($d["{$p}_lat"]) xor isset($d["{$p}_lon"]))) { throw new ApiException(422, 'validation_failed', 'الإحداثيات ناقصة', ["{$p}_lat" => 'مطلوب مع خط الطول']); }
        }
        $start = Clock::parse($d['scheduled_start']);
        $end = isset($d['scheduled_end']) ? Clock::parse($d['scheduled_end']) : null;
        if ($end !== null && $end < $start) { throw new ApiException(422, 'validation_failed', 'نهاية المهمة قبل بدايتها', ['scheduled_end' => 'قبل البداية']); }
        $now = Clock::nowStr();
        $row = [
            'mission_no' => 'TMP' . bin2hex(random_bytes(6)), 'type' => $d['type'] ?? 'transport', 'title' => $d['title'],
            'description' => $d['description'] ?? null, 'priority' => $d['priority'] ?? 'normal', 'status' => 'draft',
            'scheduled_start' => Clock::fmt($start), 'scheduled_end' => $end ? Clock::fmt($end) : null,
            'origin_name' => $d['origin_name'] ?? null, 'origin_lat' => $d['origin_lat'] ?? null, 'origin_lon' => $d['origin_lon'] ?? null,
            'dest_name' => $d['dest_name'] ?? null, 'dest_lat' => $d['dest_lat'] ?? null, 'dest_lon' => $d['dest_lon'] ?? null,
            'arrival_radius_m' => (int)($d['arrival_radius_m'] ?? 150), 'party_info' => $d['party_info'] ?? null,
            'instructions' => $d['instructions'] ?? null, 'proof_policy' => $d['proof_policy'] ?? 'optional',
            'odometer_photo_required' => in_array($d['odometer_photo_required'] ?? 0, [1, '1', true, 'true'], true) ? 1 : 0,
            'team' => $d['team'] ?? null, 'region' => $d['region'] ?? null, 'fleet' => $d['fleet'] ?? null,
            'created_by' => $user['id'], 'created_at' => $now, 'updated_at' => $now,
        ];
        Authz::requireScope($user, $row);
        $id = $this->db->tx(function () use ($row, $user, $d, $in) {
            $id = $this->db->insert('missions', $row);
            $this->db->update('missions', ['mission_no' => sprintf('M%s-%05d', gmdate('ymd'), $id)], 'id=?', [$id]);
            $this->db->insert('mission_events', ['mission_id' => $id, 'from_status' => null, 'to_status' => 'draft', 'actor_user_id' => $user['id'], 'occurred_at' => Clock::nowStr(), 'recorded_at' => Clock::nowStr()]);
            $this->replaceStops($id, $in['stops'] ?? []);
            return $id;
        });
        $this->app->audit->log($user['id'], 'mission.create', 'mission', $id);
        return $this->get($id);
    }

    public function update(array $user, int $id, array $in): array
    {
        Authz::require($user, 'missions.update');
        $m = $this->getScoped($user, $id);
        if (in_array($m['status'], self::TERMINAL, true)) { throw new ApiException(409, 'mission_closed', 'لا يمكن تعديل مهمة منتهية'); }
        $d = Validator::validate($in, [
            'title' => 'string|max:200', 'description' => 'string|max:5000', 'priority' => 'in:low,normal,high,urgent',
            'scheduled_start' => 'datetime', 'scheduled_end' => 'datetime', 'origin_name' => 'string|max:200', 'origin_lat' => 'lat', 'origin_lon' => 'lon',
            'dest_name' => 'string|max:200', 'dest_lat' => 'lat', 'dest_lon' => 'lon', 'arrival_radius_m' => 'int|min:20',
            'party_info' => 'string|max:2000', 'instructions' => 'string|max:5000', 'proof_policy' => 'in:optional,photo_required',
            'type' => 'string|max:48', 'odometer_photo_required' => 'bool',
        ]);
        foreach (['scheduled_start', 'scheduled_end'] as $f) { if (!empty($d[$f])) { $d[$f] = Clock::fmt(Clock::parse($d[$f])); } }
        if (isset($d['odometer_photo_required'])) { $d['odometer_photo_required'] = in_array($d['odometer_photo_required'], [1, '1', true, 'true'], true) ? 1 : 0; }
        $this->db->tx(function () use ($d, $id, $in) {
            if ($d) { $this->db->update('missions', $d + ['version' => $this->db->val('SELECT version+1 FROM missions WHERE id=?', [$id]), 'updated_at' => Clock::nowStr()], 'id=?', [$id]); }
            if (array_key_exists('stops', $in)) { $this->replaceStops($id, $in['stops']); $this->db->exec('UPDATE missions SET version=version+1 WHERE id=?', [$id]); }
        });
        $this->app->audit->log($user['id'], 'mission.update', 'mission', $id, ['fields' => array_keys($d)]);
        // إشعار السائق بتغيير مهمة مرسلة
        $m2 = $this->get($id);
        if ($m2['driver_id'] && !in_array($m2['status'], ['draft'], true)) { $this->notifyDriver($m2, 'mission_updated', 'تم تعديل مهمة', $m2['title']); }
        return $m2;
    }

    private function replaceStops(int $id, array $stops): void
    {
        $this->db->exec('DELETE FROM mission_stops WHERE mission_id=?', [$id]);
        $seq = 1;
        foreach ($stops as $s) {
            $v = Validator::validate((array)$s, ['name' => 'required|string|max:200', 'lat' => 'required|lat', 'lon' => 'required|lon']);
            $this->db->insert('mission_stops', ['mission_id' => $id, 'seq' => $seq++, 'name' => $v['name'], 'lat' => $v['lat'], 'lon' => $v['lon']]);
        }
    }

    /** تحول حالة واحد يُسجل في mission_events؛ occurredAt = وقت الحدث الأصلي (قد يكون offline). */
    public function transition(int $id, string $to, ?int $actorUserId, ?string $note = null, ?int $occurredAt = null, array $extra = []): array
    {
        return $this->db->tx(function () use ($id, $to, $actorUserId, $note, $occurredAt, $extra) {
            $m = $this->get($id, true);
            if (!in_array($to, self::TRANSITIONS[$m['status']], true)) {
                throw new ApiException(409, 'invalid_transition', "لا يمكن الانتقال من {$m['status']} إلى $to", ['from' => $m['status'], 'to' => $to]);
            }
            $this->db->update('missions', ['status' => $to, 'version' => (int)$m['version'] + 1, 'updated_at' => Clock::nowStr()] + $extra, 'id=?', [$id]);
            $this->db->insert('mission_events', ['mission_id' => $id, 'from_status' => $m['status'], 'to_status' => $to, 'actor_user_id' => $actorUserId,
                'note' => $note, 'occurred_at' => Clock::fmt($occurredAt ?? Clock::now()), 'recorded_at' => Clock::nowStr()]);
            return $this->get($id);
        });
    }

    // ---------------- الإسناد ----------------

    /** كشف تعارض المركبة: مهمة أخرى حية بنفس المركبة وسائق مختلف وزمن متداخل. */
    public function vehicleConflicts(int $vehicleId, ?int $driverId, string $start, ?string $end, ?int $excludeMissionId): array
    {
        $s = Clock::parse($start);
        $e = $end ? Clock::parse($end) : $s + 4 * 3600;
        $rows = $this->db->all("SELECT id, mission_no, driver_id, scheduled_start, scheduled_end, status FROM missions
            WHERE vehicle_id=? AND status IN ('" . implode("','", self::LIVE_FOR_CONFLICT) . "') AND id<>?", [$vehicleId, $excludeMissionId ?? 0]);
        $out = [];
        foreach ($rows as $r) {
            if ((int)$r['driver_id'] === $driverId) { continue; }
            $rs = Clock::parse($r['scheduled_start']);
            $re = $r['scheduled_end'] ? Clock::parse($r['scheduled_end']) : $rs + 4 * 3600;
            if ($rs < $e && $re > $s) { $out[] = $r; }
        }
        return $out;
    }

    public function assign(array $user, int $id, ?int $driverId, ?int $vehicleId, ?string $reason, ?string $conflictException = null): array
    {
        Authz::require($user, 'missions.assign');
        $res = $this->db->tx(function () use ($user, $id, $driverId, $vehicleId, $reason, $conflictException) {
            $m = $this->get($id, true);
            Authz::requireScope($user, $m);
            if (in_array($m['status'], self::TERMINAL, true)) { throw new ApiException(409, 'mission_closed', 'مهمة منتهية'); }
            $driverChanged = $driverId !== null && (int)$m['driver_id'] !== $driverId;
            $vehicleChanged = $vehicleId !== null && (int)$m['vehicle_id'] !== $vehicleId;
            if ($driverChanged && in_array($m['status'], ['accepted', 'active', 'arrived', 'completion_pending'], true)) {
                throw new ApiException(409, 'driver_locked', 'لا يمكن تغيير السائق بعد القبول؛ ألغِ المهمة أو أعد إسنادها');
            }
            if (($driverChanged || $vehicleChanged) && ($m['driver_id'] || $m['vehicle_id']) && !$reason && $m['status'] !== 'draft') {
                throw new ApiException(422, 'reason_required', 'سبب تغيير الإسناد مطلوب');
            }
            $newDriver = $driverId ?? ($m['driver_id'] ? (int)$m['driver_id'] : null);
            $newVehicle = $vehicleId ?? ($m['vehicle_id'] ? (int)$m['vehicle_id'] : null);
            $upd = [];
            if ($driverChanged) {
                $d = $this->db->one('SELECT d.*, u.is_active FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.id=?', [$driverId]);
                if (!$d || !(int)$d['is_active']) { throw new ApiException(422, 'invalid_driver', 'السائق غير موجود أو معطل'); }
                Authz::requireScope($user, $d);
                $upd['driver_id'] = $driverId;
            }
            if ($vehicleChanged) {
                $v = $this->db->one('SELECT * FROM vehicles WHERE id=?', [$vehicleId]);
                if (!$v || $v['status'] !== 'operational') { throw new ApiException(422, 'invalid_vehicle', 'المركبة غير موجودة أو غير صالحة للتشغيل'); }
                Authz::requireScope($user, $v);
                $upd['vehicle_id'] = $vehicleId;
            }
            if ($newVehicle && ($driverChanged || $vehicleChanged)) {
                $conf = $this->vehicleConflicts($newVehicle, $newDriver, $m['scheduled_start'], $m['scheduled_end'], $id);
                if ($conf && !$conflictException) {
                    throw new ApiException(409, 'vehicle_conflict', 'المركبة مسندة لسائق آخر في الوقت نفسه', ['conflicts' => $conf]);
                }
                if ($conf) { $this->app->audit->log($user['id'], 'mission.vehicle_conflict_exception', 'mission', $id, ['reason' => $conflictException, 'conflicts' => array_column($conf, 'mission_no')]); }
            }
            $now = Clock::nowStr();
            if ($driverChanged) {
                $this->db->exec('UPDATE mission_assignments SET ended_at=? WHERE mission_id=? AND ended_at IS NULL', [$now, $id]);
                $this->db->insert('mission_assignments', ['mission_id' => $id, 'driver_id' => $driverId, 'assigned_by' => $user['id'], 'reason' => $reason, 'assigned_at' => $now]);
            }
            if ($vehicleChanged) {
                $this->db->exec('UPDATE vehicle_assignment_history SET ended_at=? WHERE mission_id=? AND ended_at IS NULL', [$now, $id]);
                $this->db->insert('vehicle_assignment_history', ['mission_id' => $id, 'vehicle_id' => $vehicleId, 'driver_id' => $newDriver, 'assigned_by' => $user['id'],
                    'reason' => $reason, 'conflict_exception_reason' => $conflictException, 'assigned_at' => $now]);
            }
            $oldDriver = $m['driver_id'];
            if ($upd) {
                // السائق الجديد يعيد المهمة المرسلة إلى sent ليصله إشعار جديد
                if ($driverChanged && in_array($m['status'], ['delivered', 'rejected'], true)) { $upd['status'] = 'sent'; $upd['rejection_reason'] = null; }
                $this->db->update('missions', $upd + ['version' => (int)$m['version'] + 1, 'updated_at' => $now], 'id=?', [$id]);
                if (isset($upd['status'])) { $this->db->insert('mission_events', ['mission_id' => $id, 'from_status' => $m['status'], 'to_status' => 'sent', 'actor_user_id' => $user['id'], 'note' => 'إعادة إسناد', 'occurred_at' => $now, 'recorded_at' => $now]); }
            }
            return [$oldDriver, $driverChanged, $vehicleChanged];
        });
        [$oldDriver, $driverChanged, $vehicleChanged] = $res;
        $this->app->audit->log($user['id'], 'mission.assign', 'mission', $id, ['driver_id' => $driverId, 'vehicle_id' => $vehicleId, 'reason' => $reason]);
        $m = $this->get($id);
        if ($driverChanged && $oldDriver) { $this->notifyDriver(['driver_id' => $oldDriver, 'id' => $id], 'mission_unassigned', 'سُحبت منك مهمة', $m['title']); }
        if (($driverChanged || $vehicleChanged) && $m['driver_id'] && in_array($m['status'], ['sent', 'delivered', 'accepted', 'active'], true)) {
            $this->notifyDriver($m, $driverChanged ? 'mission_assigned' : 'vehicle_changed', $driverChanged ? 'تم تكليفك بمهمة' : 'تغيّرت مركبة المهمة', $m['title']);
        }
        return $m;
    }

    /** إرسال المهمة للسائق: تتطلب سائقًا ومركبة. */
    public function send(array $user, int $id): array
    {
        Authz::require($user, 'missions.assign');
        $m = $this->getScoped($user, $id);
        if (!$m['driver_id'] || !$m['vehicle_id']) { throw new ApiException(422, 'assignment_incomplete', 'يجب إسناد سائق ومركبة قبل الإرسال'); }
        $m = $this->transition($id, 'sent', $user['id']);
        $this->app->audit->log($user['id'], 'mission.send', 'mission', $id);
        $this->notifyDriver($m, 'mission_assigned', 'تم تكليفك بمهمة جديدة', $m['title']);
        return $m;
    }

    public function cancel(array $user, int $id, string $reason): array
    {
        Authz::require($user, 'missions.cancel');
        $m = $this->getScoped($user, $id);
        if (trim($reason) === '') { throw new ApiException(422, 'reason_required', 'سبب الإلغاء مطلوب'); }
        $m2 = $this->transition($id, 'cancelled', $user['id'], $reason, null, ['cancel_reason' => mb_substr($reason, 0, 500)]);
        $this->app->audit->log($user['id'], 'mission.cancel', 'mission', $id, ['reason' => $reason]);
        if ($m['driver_id']) { $this->notifyDriver($m2, 'mission_cancelled', 'أُلغيت مهمة', $m2['title']); }
        (new TripService($this->app))->onMissionTerminal($id);
        return $m2;
    }

    // ---------------- ردود السائق ----------------

    public function markDelivered(int $id, int $driverId, ?int $at = null): void
    {
        $m = $this->get($id);
        if ((int)$m['driver_id'] !== $driverId) { throw new ApiException(403, 'forbidden', 'المهمة ليست للسائق'); }
        if ($m['status'] === 'sent') {
            $this->transition($id, 'delivered', null, 'وصلت للتطبيق', $at, ['delivered_at' => Clock::fmt($at ?? Clock::now())]);
        }
        $this->db->exec('UPDATE sms_messages SET app_received_at=? WHERE mission_id=? AND app_received_at IS NULL', [Clock::nowStr(), $id]);
    }

    public function accept(int $id, int $driverId, ?int $at, ?string $clientTripId): array
    {
        $m = $this->get($id);
        if ((int)$m['driver_id'] !== $driverId) { throw new ApiException(403, 'forbidden', 'المهمة ليست للسائق'); }
        $this->guardFresh($m);
        $at ??= Clock::now();
        if ($m['status'] === 'sent') { $this->transition($id, 'delivered', null, 'وصلت للتطبيق', $at, ['delivered_at' => Clock::fmt($at)]); }
        $this->transition($id, 'accepted', null, null, $at, ['accepted_at' => Clock::fmt($at)]);
        $this->db->insert('mission_responses', ['mission_id' => $id, 'driver_id' => $driverId, 'response' => 'accepted', 'responded_at' => Clock::fmt($at), 'recorded_at' => Clock::nowStr()]);
        $trips = new TripService($this->app);
        $trip = $trips->attachMission($driverId, $id, $at, $clientTripId);
        $m2 = $this->transition($id, 'active', null, 'بدء التتبع تلقائيًا', $at);
        $this->notifyManagers($m2, 'mission_accepted', 'قبل السائق المهمة ' . $m2['mission_no']);
        return ['mission' => $m2, 'trip' => $trip];
    }

    public function reject(int $id, int $driverId, string $reason, ?int $at): array
    {
        $m = $this->get($id);
        if ((int)$m['driver_id'] !== $driverId) { throw new ApiException(403, 'forbidden', 'المهمة ليست للسائق'); }
        if (trim($reason) === '') { throw new ApiException(422, 'reason_required', 'سبب الرفض إلزامي'); }
        $this->guardFresh($m);
        if ($m['status'] === 'sent') { $this->transition($id, 'delivered', null, 'وصلت للتطبيق', $at); }
        $m2 = $this->transition($id, 'rejected', null, $reason, $at, ['rejection_reason' => mb_substr($reason, 0, 500)]);
        $this->db->insert('mission_responses', ['mission_id' => $id, 'driver_id' => $driverId, 'response' => 'rejected', 'reason' => mb_substr($reason, 0, 500), 'responded_at' => Clock::fmt($at ?? Clock::now()), 'recorded_at' => Clock::nowStr()]);
        $this->notifyManagers($m2, 'mission_rejected', 'رفض السائق المهمة ' . $m2['mission_no'], $reason);
        return $m2;
    }

    /** تعارض: ألغاها المدير أثناء انقطاع السائق أو أُعيد إسنادها. */
    private function guardFresh(array $m): void
    {
        if (in_array($m['status'], ['cancelled', 'completed'], true)) {
            throw new ApiException(409, 'mission_conflict', 'المهمة ' . ($m['status'] === 'cancelled' ? 'ألغيت' : 'انتهت') . ' من الإدارة', ['status' => $m['status'], 'version' => (int)$m['version']]);
        }
        if (!in_array($m['status'], ['sent', 'delivered'], true)) {
            throw new ApiException(409, 'mission_conflict', 'حالة المهمة لا تسمح بالإجراء', ['status' => $m['status'], 'version' => (int)$m['version']]);
        }
    }

    public function arrive(int $id, int $driverId, ?int $at): array
    {
        $m = $this->get($id);
        if ((int)$m['driver_id'] !== $driverId) { throw new ApiException(403, 'forbidden', 'المهمة ليست للسائق'); }
        if ($m['status'] === 'cancelled') { throw new ApiException(409, 'mission_conflict', 'المهمة ألغيت من الإدارة', ['status' => 'cancelled']); }
        return $this->transition($id, 'arrived', null, 'تأكيد السائق للوصول', $at, ['arrived_at' => Clock::fmt($at ?? Clock::now())]);
    }

    /** إنهاء من السائق: إن اشترطت السياسة صورة ولم تصل → إنهاء معلق. لا إنهاء تلقائي بـ GPS. */
    public function complete(int $id, int $driverId, ?string $notes, ?int $at): array
    {
        $m = $this->get($id);
        if ((int)$m['driver_id'] !== $driverId) { throw new ApiException(403, 'forbidden', 'المهمة ليست للسائق'); }
        if ($m['status'] === 'cancelled') { throw new ApiException(409, 'mission_conflict', 'المهمة ألغيت من الإدارة', ['status' => 'cancelled']); }
        $at ??= Clock::now();
        $extra = ['completion_notes' => $notes !== null ? mb_substr($notes, 0, 2000) : null, 'completed_at' => Clock::fmt($at)];
        $proofs = (int)$this->db->val("SELECT COUNT(*) FROM mission_attachments WHERE owner_type='mission_proof' AND owner_id=?", [$id]);
        if ($m['status'] === 'completion_pending') {
            if ($m['proof_policy'] === 'photo_required' && $proofs === 0) { return $m; }
            $m2 = $this->transition($id, 'completed', null, 'وصل الإثبات', $at, $extra);
        } elseif ($m['proof_policy'] === 'photo_required' && $proofs === 0) {
            $m2 = $this->transition($id, 'completion_pending', null, 'بانتظار صورة الإثبات', $at, ['completion_notes' => $extra['completion_notes']]);
        } else {
            $m2 = $this->transition($id, 'completed', null, null, $at, $extra);
        }
        if ($m2['status'] === 'completed') { (new TripService($this->app))->onMissionTerminal($id); }
        return $m2;
    }

    /** يُستدعى بعد رفع صورة إثبات: يُكمل إنهاءً معلقًا. */
    public function onProofUploaded(int $missionId): void
    {
        $m = $this->get($missionId);
        if ($m['status'] === 'completion_pending') {
            $this->transition($missionId, 'completed', null, 'وصل الإثبات', null, ['completed_at' => $m['completed_at'] ?? Clock::nowStr()]);
            (new TripService($this->app))->onMissionTerminal($missionId);
        }
    }

    // ---------------- المسارات ----------------

    public function proposeRoute(array $user, int $missionId, string $source, ?array $geometry, array $waypoints = []): array
    {
        Authz::require($user, 'missions.update_route');
        $m = $this->getScoped($user, $missionId);
        if ($source === 'auto') {
            if ($m['origin_lat'] === null || $m['dest_lat'] === null) { throw new ApiException(422, 'validation_failed', 'نقطتا البداية والوجهة مطلوبتان للمسار الآلي'); }
            $pts = [[(float)$m['origin_lat'], (float)$m['origin_lon']]];
            foreach ($this->db->all('SELECT lat,lon FROM mission_stops WHERE mission_id=? ORDER BY seq', [$missionId]) as $s) { $pts[] = [(float)$s['lat'], (float)$s['lon']]; }
            foreach ($waypoints as $w) { $pts[] = [(float)$w['lat'], (float)$w['lon']]; }
            $pts[] = [(float)$m['dest_lat'], (float)$m['dest_lon']];
            try { $r = $this->app->routing->route($pts); }
            catch (\Throwable $e) { throw new ApiException(502, 'routing_unavailable', 'تعذر الاتصال بمزود المسارات'); }
            $geometry = $r['geometry']; $dist = $r['distance_m']; $dur = $r['duration_s']; $provider = $this->app->routing->name();
        } else {
            if (!$geometry || count($geometry) < 2) { throw new ApiException(422, 'validation_failed', 'مسار يدوي يتطلب نقطتين على الأقل'); }
            $dist = (int)array_sum(array_map(fn($i) => \App\Core\Geo::haversine($geometry[$i - 1][0], $geometry[$i - 1][1], $geometry[$i][0], $geometry[$i][1]), range(1, count($geometry) - 1)));
            $dur = null; $provider = null;
        }
        $rid = $this->db->tx(function () use ($missionId, $source, $provider, $geometry, $dist, $dur, $user, $waypoints) {
            $ver = (int)$this->db->val('SELECT COALESCE(MAX(version),0)+1 FROM mission_routes WHERE mission_id=?', [$missionId]);
            $rid = $this->db->insert('mission_routes', ['mission_id' => $missionId, 'version' => $ver, 'source' => $source, 'provider' => $provider,
                'geometry' => json_encode($geometry), 'distance_m' => $dist, 'duration_s' => $dur, 'created_by' => $user['id'], 'created_at' => Clock::nowStr()]);
            $seq = 1;
            foreach ($waypoints as $w) { $this->db->insert('route_waypoints', ['route_id' => $rid, 'seq' => $seq++, 'lat' => $w['lat'], 'lon' => $w['lon'], 'label' => $w['label'] ?? null]); }
            return $rid;
        });
        $this->app->audit->log($user['id'], 'route.propose', 'mission', $missionId, ['route_id' => $rid, 'source' => $source]);
        return $this->db->one('SELECT * FROM mission_routes WHERE id=?', [$rid]);
    }

    public function approveRoute(array $user, int $routeId): array
    {
        Authz::require($user, 'missions.update_route');
        $r = $this->db->one('SELECT * FROM mission_routes WHERE id=?', [$routeId]);
        if (!$r) { throw new ApiException(404, 'not_found', 'المسار غير موجود'); }
        $m = $this->getScoped($user, (int)$r['mission_id']);
        $this->db->tx(function () use ($r, $user) {
            $this->db->exec("UPDATE mission_routes SET status='superseded' WHERE mission_id=? AND status='approved'", [$r['mission_id']]);
            $this->db->update('mission_routes', ['status' => 'approved', 'approved_by' => $user['id'], 'approved_at' => Clock::nowStr()], 'id=?', [$r['id']]);
            $this->db->exec('UPDATE missions SET version=version+1, updated_at=? WHERE id=?', [Clock::nowStr(), $r['mission_id']]);
        });
        $this->app->audit->log($user['id'], 'route.approve', 'mission', $m['id'], ['route_id' => $routeId, 'version' => $r['version']]);
        if ($m['driver_id'] && !in_array($m['status'], ['draft', 'completed', 'cancelled'], true)) { $this->notifyDriver($m, 'route_updated', 'تم اعتماد مسار المهمة', $m['title']); }
        return $this->db->one('SELECT * FROM mission_routes WHERE id=?', [$routeId]);
    }

    public function approvedRoute(int $missionId): ?array
    {
        $r = $this->db->one("SELECT id, version, geometry, distance_m, duration_s FROM mission_routes WHERE mission_id=? AND status='approved' ORDER BY version DESC LIMIT 1", [$missionId]);
        if ($r) { $r['geometry'] = json_decode($r['geometry'], true); }
        return $r;
    }

    // ---------------- تمثيل للتطبيق ----------------

    public function driverPayload(array $m): array
    {
        $stops = $this->db->all('SELECT seq,name,lat,lon FROM mission_stops WHERE mission_id=? ORDER BY seq', [$m['id']]);
        return [
            'id' => (int)$m['id'], 'mission_no' => $m['mission_no'], 'type' => $m['type'], 'title' => $m['title'], 'description' => $m['description'],
            'priority' => $m['priority'], 'status' => $m['status'], 'version' => (int)$m['version'],
            'scheduled_start' => $m['scheduled_start'], 'scheduled_end' => $m['scheduled_end'],
            'origin' => ['name' => $m['origin_name'], 'lat' => $m['origin_lat'] !== null ? (float)$m['origin_lat'] : null, 'lon' => $m['origin_lon'] !== null ? (float)$m['origin_lon'] : null],
            'destination' => ['name' => $m['dest_name'], 'lat' => $m['dest_lat'] !== null ? (float)$m['dest_lat'] : null, 'lon' => $m['dest_lon'] !== null ? (float)$m['dest_lon'] : null],
            'arrival_radius_m' => (int)$m['arrival_radius_m'], 'stops' => $stops, 'party_info' => $m['party_info'], 'instructions' => $m['instructions'],
            'proof_policy' => $m['proof_policy'], 'odometer_photo_required' => (bool)$m['odometer_photo_required'],
            'vehicle' => $m['vehicle_id'] ? $this->db->one('SELECT id, internal_no, plate_no, vehicle_type, model, require_odometer_photo FROM vehicles WHERE id=?', [$m['vehicle_id']]) : null,
            'route' => $this->approvedRoute((int)$m['id']), 'rejection_reason' => $m['rejection_reason'], 'cancel_reason' => $m['cancel_reason'],
        ];
    }

    private function notifyDriver(array $m, string $type, string $title, ?string $body): void
    {
        $uid = $this->db->val('SELECT user_id FROM drivers WHERE id=?', [$m['driver_id']]);
        if ($uid) { (new NotificationService($this->app))->notify((int)$uid, $type, $title, $body, ['mission_id' => (int)$m['id']]); }
    }

    private function notifyManagers(array $m, string $type, string $title, ?string $body = null): void
    {
        (new NotificationService($this->app))->notifyPermission('missions.assign', $type, $title, $body, ['mission_id' => (int)$m['id']], $m);
    }
}
