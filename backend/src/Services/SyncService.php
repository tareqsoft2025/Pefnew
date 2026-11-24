<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{ApiException, Clock, Db};

/** معالج أحداث المزامنة من التطبيق: idempotent عبر sync_events، ويعيد نتيجة لكل حدث. */
final class SyncService
{
    private Db $db;
    private const DEPENDENCY_ERRORS = ['trip_not_found', 'segment_not_found', 'owner_not_found'];
    public function __construct(private App $app) { $this->db = $app->db; }

    /** @return array[] نتيجة لكل حدث: status = applied|duplicate|conflict|rejected|retry */
    public function process(array $user, int $driverId, array $events, ?string $deviceId): array
    {
        if (count($events) > 200) { throw new ApiException(413, 'batch_too_large', 'الدفعة أكبر من 200 حدث'); }
        // أولوية: SOS ثم قرارات المهام ثم الباقي؛ الترتيب الزمني محفوظ داخل كل فئة
        $prio = ['sos.create' => 0, 'mission.delivered' => 1, 'mission.accept' => 1, 'mission.reject' => 1, 'mission.arrive' => 1, 'mission.complete' => 1];
        $indexed = [];
        foreach (array_values($events) as $i => $e) { $indexed[] = [$prio[$e['type'] ?? ''] ?? 2, $i, $e]; }
        usort($indexed, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $results = [];
        foreach ($indexed as [, $i, $e]) { $results[$i] = $this->one($user, $driverId, (array)$e, $deviceId); }
        ksort($results);
        return array_values($results);
    }

    private function one(array $user, int $driverId, array $e, ?string $deviceId): array
    {
        $eid = (string)($e['event_id'] ?? ''); $type = (string)($e['type'] ?? '');
        if ($eid === '' || strlen($eid) > 64 || $type === '') { return ['event_id' => $eid, 'status' => 'rejected', 'error' => ['code' => 'validation_failed', 'message' => 'event_id وtype مطلوبان']]; }
        $prev = $this->db->one('SELECT status, result FROM sync_events WHERE user_id=? AND event_id=?', [$user['id'], $eid]);
        if ($prev) { return ['event_id' => $eid, 'status' => 'duplicate', 'original_status' => $prev['status']] + (json_decode((string)$prev['result'], true) ?: []); }
        $p = (array)($e['payload'] ?? []);
        $at = isset($e['client_ts']) ? Clock::parse((string)$e['client_ts']) : Clock::now();
        $at = min($at, Clock::now());
        try {
            $data = $this->dispatch($type, $driverId, $user, $p, $at, $eid, $deviceId);
            $status = 'applied'; $out = ['data' => $data];
        } catch (ApiException $ex) {
            if (in_array($ex->errorCode, self::DEPENDENCY_ERRORS, true)) { return ['event_id' => $eid, 'status' => 'retry', 'error' => ['code' => $ex->errorCode, 'message' => $ex->getMessage()]]; }
            if ($ex->status >= 500) { return ['event_id' => $eid, 'status' => 'retry', 'error' => ['code' => $ex->errorCode, 'message' => $ex->getMessage()]]; }
            $status = $ex->status === 409 ? 'conflict' : 'rejected';
            $out = ['error' => ['code' => $ex->errorCode, 'message' => $ex->getMessage(), 'details' => $ex->details]];
            if ($ex->errorCode === 'mission_conflict' && !empty($p['mission_id'])) {
                $m = $this->db->one('SELECT * FROM missions WHERE id=? AND driver_id=?', [(int)$p['mission_id'], $driverId]);
                if ($m) { $out['server_mission'] = (new MissionService($this->app))->driverPayload($m); }
            }
        }
        $this->db->q('INSERT IGNORE INTO sync_events (user_id, event_id, event_type, device_id, client_ts, status, result, processed_at) VALUES (?,?,?,?,?,?,?,?)',
            [$user['id'], $eid, $type, $deviceId, Clock::fmt($at), $status, json_encode($out, JSON_UNESCAPED_UNICODE), Clock::nowStr()]);
        return ['event_id' => $eid, 'status' => $status] + $out;
    }

    private function dispatch(string $type, int $driverId, array $user, array $p, int $at, string $eid, ?string $deviceId): array
    {
        $missions = new MissionService($this->app);
        $mid = (int)($p['mission_id'] ?? 0);
        $ts = fn(string $k) => isset($p[$k]) ? Clock::parse((string)$p[$k]) : null;
        switch ($type) {
            case 'mission.delivered':
                $missions->markDelivered($mid, $driverId, $at); return ['mission_id' => $mid];
            case 'mission.accept':
                $r = $missions->accept($mid, $driverId, $at, $p['client_trip_id'] ?? null);
                return ['mission' => $missions->driverPayload($r['mission']), 'trip' => $r['trip']];
            case 'mission.reject':
                return ['mission' => $missions->driverPayload($missions->reject($mid, $driverId, (string)($p['reason'] ?? ''), $at))];
            case 'mission.arrive':
                return ['mission' => $missions->driverPayload($missions->arrive($mid, $driverId, $at))];
            case 'mission.complete':
                $m = $missions->complete($mid, $driverId, $p['notes'] ?? null, $at);
                return ['mission' => $missions->driverPayload($m), 'trip' => (new TripService($this->app))->tripState($driverId)];
            case 'odometer.record':
                return (new OdometerService($this->app))->record($driverId, isset($p['segment_id']) ? (int)$p['segment_id'] : null, (string)($p['kind'] ?? ''),
                    (float)($p['value'] ?? -1), $ts('measured_at') ?? $at, $at, $eid);
            case 'vehicle.switch':
                return (new OdometerService($this->app))->switchVehicle($driverId, (int)($p['new_vehicle_id'] ?? 0), (float)($p['end_odometer'] ?? -1), (float)($p['start_odometer'] ?? -1), $ts('measured_at') ?? $at, $eid, $p['reason'] ?? null);
            case 'trip.close':
                return (new OdometerService($this->app))->closeTrip($driverId, $p['client_trip_id'] ?? null, isset($p['end_odometer']) ? (float)$p['end_odometer'] : null, $ts('measured_at') ?? $at, $eid);
            case 'stop.explain':
                $trips = new TripService($this->app);
                return $trips->explainStop($driverId, $trips->resolveStopId($driverId, isset($p['stop_id']) ? (int)$p['stop_id'] : null, isset($p['started_at']) ? Clock::parse((string)$p['started_at']) : null), (string)($p['reason'] ?? ''), $p['notes'] ?? null, $eid, $at);
            case 'deviation.explain':
                (new TripService($this->app))->explainDeviation($driverId, (int)($p['deviation_id'] ?? 0), (string)($p['reason'] ?? '')); return [];
            case 'sos.create':
                $r = (new EmergencyService($this->app))->create($driverId, $p + ['client_event_id' => $p['client_event_id'] ?? $eid, 'created_at' => $p['created_at'] ?? gmdate('c', $at), 'device_id' => $deviceId]);
                return ['incident_id' => (int)$r['incident']['id'], 'incident_no' => $r['incident']['incident_no'], 'received_at' => $r['incident']['received_at'], 'status' => $r['incident']['status']];
            case 'inspection.submit':
                return $this->inspection($driverId, $p, $at, $eid);
            default:
                throw new ApiException(422, 'unknown_event', "نوع حدث غير معروف: $type");
        }
    }

    private function inspection(int $driverId, array $p, int $at, string $eid): array
    {
        if (!$this->app->settings->bool('feature.inspection')) { throw new ApiException(422, 'feature_disabled', 'وحدة الفحص غير مفعّلة'); }
        $vid = (int)($p['vehicle_id'] ?? 0);
        if (!$this->db->val('SELECT 1 FROM vehicles WHERE id=?', [$vid])) { throw new ApiException(422, 'validation_failed', 'مركبة غير معروفة'); }
        $b = fn($k) => isset($p[$k]) ? (int)(bool)$p[$k] : null;
        $id = $this->db->insert('vehicle_inspections', ['trip_id' => $p['trip_id'] ?? null, 'vehicle_id' => $vid, 'driver_id' => $driverId, 'phase' => in_array($p['phase'] ?? '', ['pre', 'post'], true) ? $p['phase'] : 'pre',
            'tires_ok' => $b('tires_ok'), 'lights_ok' => $b('lights_ok'), 'damage_noted' => $b('damage_noted'),
            'fuel_level' => in_array($p['fuel_level'] ?? '', ['empty', 'quarter', 'half', 'three_quarter', 'full'], true) ? $p['fuel_level'] : null,
            'notes' => isset($p['notes']) ? mb_substr((string)$p['notes'], 0, 1000) : null, 'client_event_id' => $eid, 'inspected_at' => Clock::fmt($at), 'recorded_at' => Clock::nowStr()]);
        return ['inspection_id' => $id];
    }
}
