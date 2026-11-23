<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{Authz, Clock};

/** استعلامات القراءة للوحة الإدارة، كلها مقيّدة بنطاق المستخدم. */
final class QueryService
{
    public function __construct(private App $app) {}

    /** المركبات على الخريطة: لا يُعرض موقع قديم كمباشر (is_live + age). */
    public function live(array $user): array
    {
        [$sc, $sp] = Authz::scopeSql($user, 'd');
        $fresh = $this->app->settings->int('map.live_fresh_s');
        $rows = $this->app->db->all("SELECT t.id AS trip_id, t.last_lat, t.last_lon, t.last_point_at, t.last_received_at, t.last_accuracy_m, t.distance_gps_m, t.started_at,
              d.id AS driver_id, u.full_name AS driver_name, u.phone, v.id AS vehicle_id, v.internal_no, v.plate_no,
              (SELECT g.speed_mps FROM gps_points g WHERE g.trip_id=t.id ORDER BY g.captured_at DESC LIMIT 1) AS speed_mps,
              (SELECT COUNT(*) FROM trip_stops s WHERE s.trip_id=t.id AND s.status='ongoing') AS ongoing_stop,
              (SELECT COUNT(*) FROM trip_missions tm JOIN missions m ON m.id=tm.mission_id WHERE tm.trip_id=t.id AND m.status NOT IN ('completed','cancelled')) AS active_missions,
              (SELECT COUNT(*) FROM route_deviations rd WHERE rd.trip_id=t.id AND rd.status='open') AS open_deviation
            FROM trips t JOIN drivers d ON d.id=t.driver_id JOIN users u ON u.id=d.user_id
            LEFT JOIN vehicle_segments seg ON seg.trip_id=t.id AND seg.status='open' LEFT JOIN vehicles v ON v.id=seg.vehicle_id
            WHERE t.status='active' AND $sc ORDER BY u.full_name", $sp);
        $now = Clock::now(); $out = [];
        foreach ($rows as $r) {
            $recv = $r['last_received_at'] ? Clock::parse($r['last_received_at']) : null;
            $posAt = $r['last_point_at'] ? Clock::parse($r['last_point_at']) : null;
            $online = $recv !== null && $now - $recv <= $fresh;
            $out[] = ['trip_id' => (int)$r['trip_id'], 'driver_id' => (int)$r['driver_id'], 'driver_name' => $r['driver_name'], 'phone' => $r['phone'],
                'vehicle' => $r['vehicle_id'] ? ['id' => (int)$r['vehicle_id'], 'internal_no' => $r['internal_no'], 'plate_no' => $r['plate_no']] : null,
                'lat' => $r['last_lat'] !== null ? (float)$r['last_lat'] : null, 'lon' => $r['last_lon'] !== null ? (float)$r['last_lon'] : null,
                'position_at' => $r['last_point_at'], 'position_age_s' => $posAt ? $now - $posAt : null, 'last_contact_at' => $r['last_received_at'],
                'is_live' => $online && $posAt !== null && $now - $posAt <= $fresh,
                'connection' => $online ? 'online' : 'offline',
                'motion' => !$online ? 'unknown' : ((int)$r['ongoing_stop'] > 0 ? 'stopped' : 'moving'),
                'speed_mps' => $r['speed_mps'] !== null ? (float)$r['speed_mps'] : null, 'accuracy_m' => $r['last_accuracy_m'],
                'active_missions' => (int)$r['active_missions'], 'open_deviation' => (int)$r['open_deviation'] > 0, 'distance_gps_m' => (int)$r['distance_gps_m']];
        }
        return $out;
    }

    public function dashboard(array $user): array
    {
        $live = $this->live($user);
        [$vs, $vp] = Authz::scopeSql($user, 'v');
        [$ms, $mp] = Authz::scopeSql($user, 'm');
        [$es, $ep] = Authz::scopeSql($user, 'e');
        $db = $this->app->db;
        return [
            'vehicles_total' => (int)$db->val("SELECT COUNT(*) FROM vehicles v WHERE $vs", $vp),
            'moving' => count(array_filter($live, fn($l) => $l['motion'] === 'moving')),
            'stopped' => count(array_filter($live, fn($l) => $l['motion'] === 'stopped')),
            'offline' => count(array_filter($live, fn($l) => $l['connection'] === 'offline')),
            'active_missions' => (int)$db->val("SELECT COUNT(*) FROM missions m WHERE m.status IN ('accepted','active','arrived','completion_pending') AND $ms", $mp),
            'pending_missions' => (int)$db->val("SELECT COUNT(*) FROM missions m WHERE m.status IN ('sent','delivered') AND $ms", $mp),
            'open_sos' => (int)$db->val("SELECT COUNT(*) FROM emergency_incidents e WHERE e.status IN ('new','acknowledged','in_progress','awaiting_update') AND $es", $ep),
            'unacked_sos' => (int)$db->val("SELECT COUNT(*) FROM emergency_incidents e WHERE e.status='new' AND $es", $ep),
            'open_deviations' => (int)$db->val("SELECT COUNT(*) FROM route_deviations rd JOIN trips t ON t.id=rd.trip_id JOIN drivers m ON m.id=t.driver_id WHERE rd.status='open' AND $ms", $mp),
            'unexplained_stops' => (int)$db->val("SELECT COUNT(*) FROM trip_stops s JOIN trips t ON t.id=s.trip_id JOIN drivers m ON m.id=t.driver_id WHERE s.duration_s >= ? AND NOT EXISTS (SELECT 1 FROM stop_explanations x WHERE x.stop_id=s.id) AND $ms", [$this->app->settings->int('stop.alert_after_s'), ...$mp]),
        ];
    }

    /** @return array{data:array,meta:array} */
    public function missions(array $user, array $f, int $page, int $per): array
    {
        [$sc, $sp] = Authz::scopeSql($user, 'm');
        $w = [$sc]; $p = $sp;
        if (!empty($f['status'])) { $w[] = 'm.status=?'; $p[] = $f['status']; }
        if (!empty($f['driver_id'])) { $w[] = 'm.driver_id=?'; $p[] = (int)$f['driver_id']; }
        if (!empty($f['vehicle_id'])) { $w[] = 'm.vehicle_id=?'; $p[] = (int)$f['vehicle_id']; }
        if (!empty($f['priority'])) { $w[] = 'm.priority=?'; $p[] = $f['priority']; }
        if (!empty($f['from'])) { $w[] = 'm.scheduled_start>=?'; $p[] = Clock::fmt(Clock::parse($f['from'])); }
        if (!empty($f['to'])) { $w[] = 'm.scheduled_start<=?'; $p[] = Clock::fmt(Clock::parse($f['to'])); }
        if (!empty($f['q'])) { $w[] = '(m.title LIKE ? OR m.mission_no LIKE ?)'; $like = '%' . addcslashes((string)$f['q'], '%_\\') . '%'; array_push($p, $like, $like); }
        $where = implode(' AND ', $w);
        return $this->paged("SELECT m.*, u.full_name AS driver_name, v.internal_no, v.plate_no FROM missions m LEFT JOIN drivers d ON d.id=m.driver_id LEFT JOIN users u ON u.id=d.user_id LEFT JOIN vehicles v ON v.id=m.vehicle_id WHERE $where ORDER BY m.scheduled_start DESC, m.id DESC",
            "SELECT COUNT(*) FROM missions m WHERE $where", $p, $page, $per);
    }

    public function paged(string $sql, string $countSql, array $p, int $page, int $per): array
    {
        $per = max(1, min(100, $per)); $page = max(1, $page);
        $total = (int)$this->app->db->val($countSql, $p);
        $rows = $this->app->db->all($sql . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p);
        return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'pages' => (int)ceil($total / $per)]];
    }

    public function emergencies(array $user, array $f, int $page, int $per): array
    {
        [$sc, $sp] = Authz::scopeSql($user, 'e');
        $w = [$sc]; $p = $sp;
        if (!empty($f['status'])) { if ($f['status'] === 'open') { $w[] = "e.status IN ('new','acknowledged','in_progress','awaiting_update')"; } else { $w[] = 'e.status=?'; $p[] = $f['status']; } }
        $where = implode(' AND ', $w);
        return $this->paged("SELECT e.*, t.name_ar AS type_name, u.full_name AS driver_name, v.plate_no FROM emergency_incidents e JOIN drivers d ON d.id=e.driver_id JOIN users u ON u.id=d.user_id
            LEFT JOIN emergency_types t ON t.id=e.type_id LEFT JOIN vehicles v ON v.id=e.vehicle_id WHERE $where ORDER BY (e.status='new') DESC, e.received_at DESC",
            "SELECT COUNT(*) FROM emergency_incidents e WHERE $where", $p, $page, $per);
    }

    /** مسار الرحلة التاريخي (يتطلب صلاحية وفحص نطاق السائق) ويُسجل الاطلاع. */
    public function track(array $user, int $tripId): array
    {
        Authz::require($user, 'tracking.view_history');
        $t = $this->app->db->one('SELECT t.*, d.team, d.region, d.fleet, d.id AS did FROM trips t JOIN drivers d ON d.id=t.driver_id WHERE t.id=?', [$tripId]);
        if (!$t) { throw new \App\Core\ApiException(404, 'not_found', 'الرحلة غير موجودة'); }
        Authz::requireScope($user, $t);
        $this->app->audit->log((int)$user['id'], 'tracking.view_history', 'trip', $tripId);
        return [
            'trip' => ['id' => (int)$t['id'], 'status' => $t['status'], 'started_at' => $t['started_at'], 'ended_at' => $t['ended_at'], 'distance_gps_m' => (int)$t['distance_gps_m']],
            'points' => array_map(fn($r) => [(float)$r['lat'], (float)$r['lon'], $r['captured_at']], $this->app->db->all('SELECT lat, lon, captured_at FROM gps_points WHERE trip_id=? ORDER BY captured_at', [$tripId])),
            'stops' => $this->app->db->all('SELECT s.id, s.started_at, s.ended_at, s.duration_s, s.lat, s.lon, r.name_ar AS reason, x.notes FROM trip_stops s LEFT JOIN stop_explanations x ON x.stop_id=s.id LEFT JOIN stop_reasons r ON r.id=x.reason_id WHERE s.trip_id=? ORDER BY s.started_at', [$tripId]),
            'deviations' => $this->app->db->all('SELECT id, started_at, ended_at, max_distance_m, lat, lon, reason, review_status FROM route_deviations WHERE trip_id=? ORDER BY started_at', [$tripId]),
            'approved_routes' => array_map(fn($r) => json_decode($r['geometry'], true), $this->app->db->all("SELECT r.geometry FROM mission_routes r JOIN trip_missions tm ON tm.mission_id=r.mission_id WHERE tm.trip_id=? AND r.status='approved'", [$tripId])),
        ];
    }
}
