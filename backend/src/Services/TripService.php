<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{ApiException, Clock, Db};

final class TripService
{
    private Db $db;
    public function __construct(private App $app) { $this->db = $app->db; }

    public function resolve(int $driverId, string $clientTripId): ?array
    {
        $t = $this->db->one('SELECT * FROM trips WHERE driver_id=? AND client_trip_id=?', [$driverId, $clientTripId]);
        if ($t) { return $t; }
        $tid = $this->db->val('SELECT trip_id FROM trip_aliases WHERE driver_id=? AND client_trip_id=?', [$driverId, $clientTripId]);
        return $tid ? $this->db->one('SELECT * FROM trips WHERE id=?', [$tid]) : null;
    }

    /** يربط المهمة المقبولة برحلة السائق المفتوحة (أو ينشئ رحلة) ويفتح مرحلة المركبة الأولى. التتبع لا ينتظر العداد. */
    public function attachMission(int $driverId, int $missionId, int $at, ?string $clientTripId): array
    {
        return $this->db->tx(function () use ($driverId, $missionId, $at, $clientTripId) {
            $mission = $this->db->one('SELECT * FROM missions WHERE id=?', [$missionId]);
            $trip = $this->db->one("SELECT * FROM trips WHERE driver_id=? AND status='active' FOR UPDATE", [$driverId]);
            if (!$trip && $clientTripId) {
                $trip = $this->resolve($driverId, $clientTripId);
                if ($trip && $trip['status'] === 'closed') { $trip = null; $clientTripId = null; }
            }
            if (!$trip) {
                $cid = $clientTripId ?: 'srv-' . bin2hex(random_bytes(8));
                $tid = $this->db->insert('trips', ['driver_id' => $driverId, 'client_trip_id' => $cid, 'status' => 'active',
                    'started_at' => Clock::fmt($at), 'created_at' => Clock::nowStr()]);
                $trip = $this->db->one('SELECT * FROM trips WHERE id=?', [$tid]);
                if ($mission['vehicle_id']) {
                    $this->db->insert('vehicle_segments', ['trip_id' => $tid, 'vehicle_id' => $mission['vehicle_id'], 'seq' => 1, 'status' => 'open', 'started_at' => Clock::fmt($at), 'odometer_pending' => 1]);
                }
            } elseif ($clientTripId && $trip['client_trip_id'] !== $clientTripId) {
                $this->db->q('INSERT IGNORE INTO trip_aliases (driver_id, client_trip_id, trip_id) VALUES (?,?,?)', [$driverId, $clientTripId, $trip['id']]);
            }
            $this->db->q('INSERT IGNORE INTO trip_missions (trip_id, mission_id, attached_at) VALUES (?,?,?)', [$trip['id'], $missionId, Clock::fmt($at)]);
            $seg = $this->db->one("SELECT * FROM vehicle_segments WHERE trip_id=? AND status='open'", [$trip['id']]);
            if ($seg && $mission['vehicle_id'] && (int)$seg['vehicle_id'] !== (int)$mission['vehicle_id']) {
                (new NotificationService($this->app))->notifyPermission('missions.assign', 'vehicle_mismatch', 'مركبة المهمة تختلف عن مركبة رحلة السائق الحالية', $mission['mission_no'], ['mission_id' => $missionId], $mission);
            }
            return ['id' => (int)$trip['id'], 'client_trip_id' => $trip['client_trip_id'], 'started_at' => $trip['started_at'],
                'segment_id' => $seg ? (int)$seg['id'] : null, 'segment_vehicle_id' => $seg ? (int)$seg['vehicle_id'] : null];
        });
    }

    public function onMissionTerminal(int $missionId): void
    {
        $this->db->exec('UPDATE trip_missions SET detached_at=? WHERE mission_id=? AND detached_at IS NULL', [Clock::nowStr(), $missionId]);
    }

    public function activeMissionCount(int $tripId): int
    {
        return (int)$this->db->val("SELECT COUNT(*) FROM trip_missions tm JOIN missions m ON m.id=tm.mission_id WHERE tm.trip_id=? AND m.status NOT IN ('completed','cancelled')", [$tripId]);
    }

    /** استقبال دفعة نقاط idempotent. يعيد عدد الجديد/المكرر/المرفوض. */
    public function ingestGps(int $driverId, string $clientTripId, array $points): array
    {
        $trip = $this->resolve($driverId, $clientTripId);
        if (!$trip) { throw new ApiException(404, 'trip_not_found', 'الرحلة غير معروفة؛ زامن قبول المهمة أولًا'); }
        if (count($points) > 500) { throw new ApiException(413, 'batch_too_large', 'الدفعة أكبر من 500 نقطة'); }
        $now = Clock::now(); $new = 0; $dup = 0; $rejected = []; $latest = null;
        $this->db->tx(function () use ($points, $trip, $now, &$new, &$dup, &$rejected, &$latest) {
            foreach ($points as $i => $p) {
                $id = (string)($p['id'] ?? '');
                $lat = $p['lat'] ?? null; $lon = $p['lon'] ?? null;
                if ($id === '' || strlen($id) > 64 || !is_numeric($lat) || !is_numeric($lon) || abs((float)$lat) > 90 || abs((float)$lon) > 180 || empty($p['ts'])) { $rejected[] = ['id' => $id, 'reason' => 'invalid']; continue; }
                $ts = Clock::parse((string)$p['ts']);
                if ($ts > $now + 600) { $rejected[] = ['id' => $id, 'reason' => 'future_timestamp']; continue; }
                $src = in_array($p['source'] ?? 'fused', ['gps', 'network', 'fused', 'manual'], true) ? ($p['source'] ?? 'fused') : 'fused';
                $st = $this->db->q('INSERT IGNORE INTO gps_points (trip_id, client_point_id, captured_at, lat, lon, accuracy_m, speed_mps, bearing, source, uploaded_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$trip['id'], $id, gmdate('Y-m-d H:i:s', $ts) . '.000', $lat, $lon,
                     $p['accuracy'] ?? null, $p['speed'] ?? null, $p['bearing'] ?? null, $src, Clock::fmt($now)]);
                if ($st->rowCount() === 1) { $new++; if ($latest === null || $ts > $latest['ts']) { $latest = ['ts' => $ts, 'lat' => (float)$lat, 'lon' => (float)$lon, 'acc' => $p['accuracy'] ?? null]; } }
                else { $dup++; }
            }
            if ($latest !== null) {
                $this->db->exec('UPDATE trips SET point_count=point_count+?, last_received_at=? WHERE id=?', [$new, Clock::fmt($now), $trip['id']]);
                if ($trip['last_point_at'] === null || $latest['ts'] >= Clock::parse($trip['last_point_at'])) {
                    $this->db->update('trips', ['last_point_at' => Clock::fmt($latest['ts']), 'last_lat' => $latest['lat'], 'last_lon' => $latest['lon'], 'last_accuracy_m' => $latest['acc']], 'id=?', [$trip['id']]);
                }
            } else {
                $this->db->exec('UPDATE trips SET last_received_at=? WHERE id=?', [Clock::fmt($now), $trip['id']]);   // نبض اتصال حتى بلا نقاط جديدة
            }
        });
        if ($new > 0) { $this->reanalyze((int)$trip['id']); }
        return ['accepted' => $new, 'duplicates' => $dup, 'rejected' => $rejected, 'trip_id' => (int)$trip['id']];
    }

    public function config(): array
    {
        $s = $this->app->settings;
        return ['max_accuracy' => (float)$s->get('gps.max_accuracy_m'), 'stop_radius' => (float)$s->get('stop.radius_m'),
            'stop_record_s' => $s->int('stop.record_after_s'), 'dev_distance' => (float)$s->get('deviation.distance_m'), 'dev_duration' => $s->int('deviation.duration_s')];
    }

    /** إعادة تحليل كاملة وحفظ النتائج (مسافة، توقفات، انحرافات، مراحل) مع تنبيهات بوقت الحدث الأصلي. */
    public function reanalyze(int $tripId): void
    {
        $trip = $this->db->one('SELECT * FROM trips WHERE id=?', [$tripId]);
        $rows = $this->db->all('SELECT UNIX_TIMESTAMP(captured_at) + (MICROSECOND(captured_at)/1000000) AS t, lat, lon, accuracy_m, speed_mps FROM gps_points WHERE trip_id=? ORDER BY captured_at, id', [$tripId]);
        $pts = array_map(fn($r) => ['t' => (int)floor((float)$r['t']), 'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'], 'acc' => $r['accuracy_m'] !== null ? (float)$r['accuracy_m'] : null, 'speed' => $r['speed_mps'] !== null ? (float)$r['speed_mps'] : null], $rows);
        $segs = $this->db->all('SELECT id, started_at, ended_at FROM vehicle_segments WHERE trip_id=?', [$tripId]);
        $segRanges = array_map(fn($s) => ['id' => (int)$s['id'], 'start' => Clock::parse($s['started_at']), 'end' => $s['ended_at'] ? Clock::parse($s['ended_at']) : null], $segs);
        $routes = [];
        foreach ($this->db->all("SELECT r.geometry FROM mission_routes r JOIN trip_missions tm ON tm.mission_id=r.mission_id JOIN missions m ON m.id=r.mission_id
            WHERE tm.trip_id=? AND r.status='approved' AND m.status NOT IN ('completed','cancelled')", [$tripId]) as $r) { $routes[] = json_decode($r['geometry'], true); }
        $res = TripAnalyzer::analyze($pts, $this->config(), $routes, $segRanges);

        $this->db->tx(function () use ($tripId, $trip, $res, $segs) {
            $this->db->update('trips', ['distance_gps_m' => $res['distance_m']], 'id=?', [$tripId]);
            foreach ($segs as $s) {
                $gps = $res['segment_distance_m'][(int)$s['id']] ?? 0;
                $this->db->update('vehicle_segments', ['gps_distance_m' => $gps], 'id=?', [$s['id']]);
                (new OdometerService($this->app))->refreshReview((int)$s['id']);
            }
            $this->reconcileStops($tripId, $res['stops']);
            $this->reconcileDeviations($tripId, $res['deviations']);
        });
        $this->evaluateStopAlerts($tripId);
    }

    private function overlaps(int $s1, ?int $e1, int $s2, ?int $e2): bool
    {
        return $s1 <= ($e2 ?? PHP_INT_MAX) + 60 && $s2 <= ($e1 ?? PHP_INT_MAX) + 60;
    }

    private function reconcileStops(int $tripId, array $new): void
    {
        $old = $this->db->all('SELECT * FROM trip_stops WHERE trip_id=? ORDER BY started_at', [$tripId]);
        $used = [];
        foreach ($new as $n) {
            $match = null;
            foreach ($old as $o) {
                if (isset($used[$o['id']])) { continue; }
                if ($this->overlaps($n['started_at'], $n['ended_at'], Clock::parse($o['started_at']), $o['ended_at'] ? Clock::parse($o['ended_at']) : null)) { $match = $o; break; }
            }
            $vals = ['started_at' => Clock::fmt($n['started_at']), 'ended_at' => $n['ended_at'] ? Clock::fmt($n['ended_at']) : null, 'duration_s' => $n['duration_s'],
                'lat' => $n['lat'], 'lon' => $n['lon'], 'status' => $n['ended_at'] ? 'ended' : 'ongoing'];
            if ($match) { $used[$match['id']] = true; $this->db->update('trip_stops', $vals, 'id=?', [$match['id']]); $sid = (int)$match['id']; }
            else { $sid = $this->db->insert('trip_stops', ['trip_id' => $tripId] + $vals); }
            $this->db->exec('DELETE FROM trip_stop_missions WHERE stop_id=?', [$sid]);
            $this->db->q('INSERT IGNORE INTO trip_stop_missions (stop_id, mission_id) SELECT ?, mission_id FROM trip_missions WHERE trip_id=? AND attached_at <= ? AND (detached_at IS NULL OR detached_at >= ?)',
                [$sid, $tripId, $vals['ended_at'] ?? Clock::nowStr(), $vals['started_at']]);
        }
        foreach ($old as $o) {
            if (!isset($used[$o['id']]) && !$this->db->val('SELECT 1 FROM stop_explanations WHERE stop_id=?', [$o['id']])) { $this->db->exec('DELETE FROM trip_stops WHERE id=?', [$o['id']]); }
        }
    }

    private function reconcileDeviations(int $tripId, array $new): void
    {
        $old = $this->db->all('SELECT * FROM route_deviations WHERE trip_id=? ORDER BY started_at', [$tripId]);
        $used = [];
        foreach ($new as $n) {
            $match = null;
            foreach ($old as $o) {
                if (!isset($used[$o['id']]) && $this->overlaps($n['started_at'], $n['ended_at'], Clock::parse($o['started_at']), $o['ended_at'] ? Clock::parse($o['ended_at']) : null)) { $match = $o; break; }
            }
            $vals = ['started_at' => Clock::fmt($n['started_at']), 'ended_at' => $n['ended_at'] ? Clock::fmt($n['ended_at']) : null, 'max_distance_m' => $n['max_distance_m'],
                'lat' => $n['lat'], 'lon' => $n['lon'], 'status' => $n['ended_at'] ? 'closed' : 'open'];
            if ($match) { $used[$match['id']] = true; $this->db->update('route_deviations', $vals, 'id=?', [$match['id']]); }
            else { $id = $this->db->insert('route_deviations', ['trip_id' => $tripId] + $vals); $this->notifyDeviation($tripId, $id, $n); }
        }
        foreach ($old as $o) {
            if (!isset($used[$o['id']]) && !$o['reason'] && $o['review_status'] === 'pending') { $this->db->exec('DELETE FROM route_deviations WHERE id=?', [$o['id']]); }
        }
    }

    private function notifyDeviation(int $tripId, int $devId, array $n): void
    {
        $trip = $this->db->one('SELECT t.driver_id, d.user_id, d.team, d.region, d.fleet FROM trips t JOIN drivers d ON d.id=t.driver_id WHERE t.id=?', [$tripId]);
        // لا نمنع تغيير المسار في الطوارئ: نسجل الانحراف دون تنبيه عند وجود بلاغ طوارئ مفتوح
        if ($this->db->val("SELECT 1 FROM emergency_incidents WHERE driver_id=? AND status NOT IN ('closed','false_alarm','resolved') LIMIT 1", [$trip['driver_id']])) { return; }
        if ($n['ended_at'] !== null) { return; }   // انحراف قديم انتهى قبل وصول الدفعة: يظهر في المراجعة بلا تنبيه لحظي
        $ns = new NotificationService($this->app);
        $ns->notify((int)$trip['user_id'], 'route_deviation', 'انحراف عن المسار المعتمد', 'يرجى تسجيل السبب إن لزم', ['deviation_id' => $devId, 'trip_id' => $tripId]);
        $ns->notifyPermission('stops.review', 'route_deviation', 'انحراف عن المسار', 'مسافة ' . $n['max_distance_m'] . ' م', ['deviation_id' => $devId], $trip);
    }

    /** تنبيهات التوقف بحسب مدة التوقف؛ event_time = وقت الحدث الأصلي لا وقت المعالجة. */
    public function evaluateStopAlerts(int $tripId): void
    {
        $prompt = $this->app->settings->int('stop.prompt_after_s'); $alert = $this->app->settings->int('stop.alert_after_s');
        $stops = $this->db->all('SELECT * FROM trip_stops WHERE trip_id=?', [$tripId]);
        foreach ($stops as $s) {
            $start = Clock::parse($s['started_at']);
            foreach (['prompt' => $prompt, 'alert' => $alert] as $level => $after) {
                if ((int)$s['duration_s'] < $after) { continue; }
                $st = $this->db->q('INSERT IGNORE INTO stop_alerts (stop_id, level, event_time, created_at) VALUES (?,?,?,?)', [$s['id'], $level, Clock::fmt($start + $after), Clock::nowStr()]);
                if ($st->rowCount() !== 1) { continue; }
                $trip = $this->db->one('SELECT d.user_id, d.team, d.region, d.fleet FROM trips t JOIN drivers d ON d.id=t.driver_id WHERE t.id=?', [$tripId]);
                $ns = new NotificationService($this->app);
                if ($level === 'prompt' && $s['status'] === 'ongoing') {
                    $ns->notify((int)$trip['user_id'], 'stop_prompt', 'توقف طويل', 'اذكر سبب التوقف عندما يكون ذلك آمنًا', ['stop_id' => (int)$s['id']]);
                } elseif ($level === 'alert') {
                    $ns->notifyPermission('stops.review', 'stop_alert', 'توقف مطوّل بلا تفسير', 'المدة ' . intdiv((int)$s['duration_s'], 60) . ' دقيقة', ['stop_id' => (int)$s['id']], $trip);
                }
            }
        }
    }

    /** يحل التوقف بالمعرف، أو بوقت بدايته على الجهاز (للتفسير دون اتصال قبل معرفة معرف الخادم). */
    public function resolveStopId(int $driverId, ?int $stopId, ?int $startedAt): int
    {
        if ($stopId) { return $stopId; }
        if (!$startedAt) { throw new ApiException(422, 'validation_failed', 'stop_id أو started_at مطلوب'); }
        $row = $this->db->one('SELECT s.id FROM trip_stops s JOIN trips t ON t.id=s.trip_id WHERE t.driver_id=? AND s.started_at <= ? AND (s.ended_at IS NULL OR s.ended_at >= ?) ORDER BY ABS(TIMESTAMPDIFF(SECOND, s.started_at, ?)) LIMIT 1',
            [$driverId, Clock::fmt($startedAt + 120), Clock::fmt($startedAt - 120), Clock::fmt($startedAt)]);
        if (!$row) { throw new ApiException(404, 'owner_not_found', 'لم يصل التوقف للخادم بعد؛ سيُعاد الإرسال'); }
        return (int)$row['id'];
    }

    public function explainStop(int $driverId, int $stopId, string $reasonCode, ?string $notes, string $clientEventId, ?int $at): array
    {
        $stop = $this->db->one('SELECT s.*, t.driver_id FROM trip_stops s JOIN trips t ON t.id=s.trip_id WHERE s.id=?', [$stopId]);
        if (!$stop || (int)$stop['driver_id'] !== $driverId) { throw new ApiException(404, 'not_found', 'التوقف غير موجود'); }
        $rid = $this->db->val('SELECT id FROM stop_reasons WHERE code=?', [$reasonCode]);
        if (!$rid) { throw new ApiException(422, 'validation_failed', 'سبب غير معروف', ['reason' => 'invalid']); }
        $this->db->q('INSERT INTO stop_explanations (stop_id, reason_id, notes, client_event_id, explained_at, recorded_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE reason_id=VALUES(reason_id), notes=VALUES(notes)',
            [$stopId, $rid, $notes !== null ? mb_substr($notes, 0, 500) : null, $clientEventId, Clock::fmt($at ?? Clock::now()), Clock::nowStr()]);
        return ['stop_id' => $stopId, 'reason' => $reasonCode];
    }

    public function explainDeviation(int $driverId, int $devId, string $reason): void
    {
        $d = $this->db->one('SELECT v.*, t.driver_id FROM route_deviations v JOIN trips t ON t.id=v.trip_id WHERE v.id=?', [$devId]);
        if (!$d || (int)$d['driver_id'] !== $driverId) { throw new ApiException(404, 'not_found', 'الانحراف غير موجود'); }
        $this->db->update('route_deviations', ['reason' => mb_substr($reason, 0, 500), 'reason_at' => Clock::nowStr()], 'id=?', [$devId]);
    }

    /** توقفات وانحرافات الرحلة الحالية التي تنتظر تفسير السائق. */
    public function pendingExplanations(int $driverId): array
    {
        $t = $this->db->one("SELECT id FROM trips WHERE driver_id=? AND status='active'", [$driverId]);
        if (!$t) { return ['stops' => [], 'deviations' => []]; }
        return [
            'stops' => $this->db->all('SELECT s.id, s.started_at, s.ended_at, s.duration_s, s.status FROM trip_stops s WHERE s.trip_id=? AND NOT EXISTS (SELECT 1 FROM stop_explanations x WHERE x.stop_id=s.id) AND s.duration_s >= ? ORDER BY s.started_at DESC LIMIT 20', [$t['id'], $this->app->settings->int('stop.prompt_after_s')]),
            'deviations' => $this->db->all('SELECT id, started_at, ended_at, max_distance_m, status FROM route_deviations WHERE trip_id=? AND reason IS NULL ORDER BY started_at DESC LIMIT 20', [$t['id']]),
        ];
    }

    /** لقطة حالة الرحلة للتطبيق بعد المزامنة. */
    public function tripState(int $driverId): ?array
    {
        $t = $this->db->one("SELECT * FROM trips WHERE driver_id=? AND status='active'", [$driverId]);
        if (!$t) { return null; }
        $seg = $this->db->one("SELECT id, vehicle_id, start_odometer, odometer_pending FROM vehicle_segments WHERE trip_id=? AND status='open'", [$t['id']]);
        return ['id' => (int)$t['id'], 'client_trip_id' => $t['client_trip_id'], 'started_at' => $t['started_at'], 'distance_gps_m' => (int)$t['distance_gps_m'],
            'active_missions' => $this->activeMissionCount((int)$t['id']), 'tracking_required' => $this->activeMissionCount((int)$t['id']) > 0,
            'segment' => $seg ? ['id' => (int)$seg['id'], 'vehicle_id' => (int)$seg['vehicle_id'], 'start_odometer' => $seg['start_odometer'] !== null ? (float)$seg['start_odometer'] : null, 'odometer_pending' => (bool)$seg['odometer_pending']] : null];
    }
}
