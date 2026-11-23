<?php
declare(strict_types=1);
namespace App\Http;

use App\App;
use App\Core\{ApiException, Authz, Clock, Env, Validator};
use App\Services\{AttachmentService, AuthService, EmergencyService, MissionService, OdometerService, QueryService, SmsService, SyncService, TripService, NotificationService};

/** REST /api/v1: واجهة تطبيق السائق + واجهة JSON للإدارة (بجلسة + CSRF). */
final class ApiController
{
    private App $app;
    public function __construct(private Kernel $k) { $this->app = $k->app; }

    public function routes(Router $r): void
    {
        $d = ['auth' => 'driver']; $a = fn(string $perm) => ['auth' => 'admin', 'perm' => $perm];
        $p = '/api/v1';
        // ---- مصادقة ----
        $r->post("$p/auth/login", fn($q) => $this->login($q));
        $r->post("$p/auth/refresh", fn($q) => Response::json((new AuthService($this->app))->refresh((string)($q->body['refresh_token'] ?? ''))));
        $r->post("$p/auth/logout", function ($q) { (new AuthService($this->app))->revokeToken($q->attrs['token']); return Response::json(['ok' => true]); }, $d);
        $r->post("$p/auth/change-password", function ($q) {
            $s = new AuthService($this->app);
            $s->verifyCredentials($q->attrs['user']['username'], (string)($q->body['current_password'] ?? ''), null, $q->ip);
            $s->changePassword((int)$q->attrs['user']['id'], (string)($q->body['new_password'] ?? ''));
            return Response::json(['ok' => true, 'reauth_required' => true]);
        }, $d);
        // ---- تطبيق السائق ----
        $r->get("$p/me", fn($q) => Response::json(['user' => ['id' => $q->attrs['user']['id'], 'full_name' => $q->attrs['user']['full_name'], 'username' => $q->attrs['user']['username'],
            'phone' => $q->attrs['user']['phone']], 'driver_id' => (int)$q->attrs['driver']['id'], 'server_time' => Clock::nowStr()]), $d);
        $r->get("$p/config", fn($q) => $this->config($q), $d);
        $r->get("$p/missions", fn($q) => $this->driverMissions($q), $d);
        $r->get("$p/missions/{id}", function ($q, $x) {
            $m = $this->app->db->one('SELECT * FROM missions WHERE id=? AND driver_id=?', [(int)$x['id'], $q->attrs['driver']['id']]);
            if (!$m || $m['status'] === 'draft') { throw new ApiException(404, 'not_found', 'المهمة غير موجودة'); }
            return Response::json((new MissionService($this->app))->driverPayload($m));
        }, $d);
        $r->post("$p/sync/events", function ($q) {
            $ev = $q->body['events'] ?? null;
            if (!is_array($ev)) { throw new ApiException(422, 'validation_failed', 'events مطلوبة'); }
            $res = (new SyncService($this->app))->process($q->attrs['user'], (int)$q->attrs['driver']['id'], $ev, $q->attrs['device_id']);
            return Response::json(['results' => $res, 'trip' => (new TripService($this->app))->tripState((int)$q->attrs['driver']['id']), 'server_time' => Clock::nowStr()]);
        }, $d);
        $r->post("$p/gps/batch", function ($q) {
            $res = (new TripService($this->app))->ingestGps((int)$q->attrs['driver']['id'], (string)($q->body['client_trip_id'] ?? ''), (array)($q->body['points'] ?? []));
            return Response::json($res + ['tracking_required' => (new TripService($this->app))->activeMissionCount($res['trip_id']) > 0, 'server_time' => Clock::nowStr()]);
        }, $d);
        $r->get("$p/trips/current", fn($q) => Response::json(['trip' => (new TripService($this->app))->tripState((int)$q->attrs['driver']['id'])]), $d);
        $r->post("$p/attachments", fn($q) => $this->upload($q), $d);
        $r->get("$p/attachments/{id}/file", fn($q, $x) => $this->serveFile($q, (int)$x['id'], true), $d);
        $r->get("$p/notifications", function ($q) {
            $rows = $this->app->db->all('SELECT id, type, title, body, data, read_at, created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 100', [$q->attrs['user']['id']]);
            foreach ($rows as &$n) { $n['data'] = $n['data'] ? json_decode($n['data'], true) : null; }
            return Response::json(['data' => $rows]);
        }, $d);
        $r->post("$p/notifications/{id}/read", function ($q, $x) { $this->app->db->exec('UPDATE notifications SET read_at=? WHERE id=? AND user_id=? AND read_at IS NULL', [Clock::nowStr(), (int)$x['id'], $q->attrs['user']['id']]); return Response::json(['ok' => true]); }, $d);
        $r->post("$p/devices/push-token", function ($q) {
            $t = (string)($q->body['push_token'] ?? '');
            $this->app->db->exec('UPDATE api_tokens SET push_token=? WHERE user_id=? AND device_id=? AND revoked_at IS NULL', [$t !== '' ? mb_substr($t, 0, 512) : null, $q->attrs['user']['id'], $q->attrs['device_id']]);
            return Response::json(['ok' => true]);
        }, $d);
        $r->get("$p/emergencies/{id}", fn($q, $x) => Response::json((new EmergencyService($this->app))->driverView((int)$q->attrs['driver']['id'], (int)$x['id'])), $d);
        $r->get("$p/history", fn($q) => $this->history($q), $d);
        // ---- ويب هوك (أسرار من البيئة) ----
        $r->post("$p/webhooks/sms-dlr", fn($q) => $this->smsDlr($q));
        $r->post("$p/webhooks/sms-inbound", fn($q) => $this->smsInbound($q));
        // ---- واجهة الإدارة JSON ----
        $r->get("$p/admin/live", fn($q) => Response::json(['data' => (new QueryService($this->app))->live($q->attrs['user']), 'server_time' => Clock::nowStr(), 'fresh_after_s' => $this->app->settings->int('map.live_fresh_s')]), $a('tracking.view_live'));
        $r->get("$p/admin/dashboard", fn($q) => Response::json((new QueryService($this->app))->dashboard($q->attrs['user'])), $a('dashboard.view'));
        $r->get("$p/admin/trips/{id}/track", fn($q, $x) => Response::json((new QueryService($this->app))->track($q->attrs['user'], (int)$x['id'])), $a('tracking.view_history'));
        $r->get("$p/admin/missions", function ($q) {
            return Response::json((new QueryService($this->app))->missions($q->attrs['user'], $q->query, (int)($q->query['page'] ?? 1), (int)($q->query['per_page'] ?? 25)));
        }, $a('missions.view'));
        $r->post("$p/admin/missions", fn($q) => Response::json((new MissionService($this->app))->create($q->attrs['user'], $q->body), 201), $a('missions.create'));
        $r->get("$p/admin/missions/{id}", function ($q, $x) {
            $m = (new MissionService($this->app))->getScoped($q->attrs['user'], (int)$x['id']);
            $db = $this->app->db;
            return Response::json(['mission' => $m, 'events' => $db->all('SELECT * FROM mission_events WHERE mission_id=? ORDER BY occurred_at, id', [$m['id']]),
                'routes' => $db->all('SELECT id, version, source, status, distance_m, created_at, approved_at FROM mission_routes WHERE mission_id=? ORDER BY version', [$m['id']]),
                'vehicle_history' => $db->all('SELECT * FROM vehicle_assignment_history WHERE mission_id=? ORDER BY id', [$m['id']]),
                'sms' => $db->all('SELECT id, requested_at, provider, provider_accepted_at, delivered_at, failed_at, error, app_received_at FROM sms_messages WHERE mission_id=?', [$m['id']])]);
        }, $a('missions.view'));
        $r->put("$p/admin/missions/{id}", fn($q, $x) => Response::json((new MissionService($this->app))->update($q->attrs['user'], (int)$x['id'], $q->body)), $a('missions.update'));
        $r->post("$p/admin/missions/{id}/assign", function ($q, $x) {
            $b = $q->body;
            return Response::json((new MissionService($this->app))->assign($q->attrs['user'], (int)$x['id'], isset($b['driver_id']) ? (int)$b['driver_id'] : null, isset($b['vehicle_id']) ? (int)$b['vehicle_id'] : null, $b['reason'] ?? null, $b['conflict_exception_reason'] ?? null));
        }, $a('missions.assign'));
        $r->post("$p/admin/missions/{id}/send", fn($q, $x) => Response::json((new MissionService($this->app))->send($q->attrs['user'], (int)$x['id'])), $a('missions.assign'));
        $r->post("$p/admin/missions/{id}/cancel", fn($q, $x) => Response::json((new MissionService($this->app))->cancel($q->attrs['user'], (int)$x['id'], (string)($q->body['reason'] ?? ''))), $a('missions.cancel'));
        $r->post("$p/admin/missions/{id}/routes", function ($q, $x) {
            $b = $q->body;
            return Response::json((new MissionService($this->app))->proposeRoute($q->attrs['user'], (int)$x['id'], ($b['source'] ?? 'auto') === 'manual' ? 'manual' : 'auto', $b['geometry'] ?? null, (array)($b['waypoints'] ?? [])), 201);
        }, $a('missions.update_route'));
        $r->post("$p/admin/routes/{id}/approve", fn($q, $x) => Response::json((new MissionService($this->app))->approveRoute($q->attrs['user'], (int)$x['id'])), $a('missions.update_route'));
        $r->get("$p/admin/emergencies", fn($q) => Response::json((new QueryService($this->app))->emergencies($q->attrs['user'], $q->query, (int)($q->query['page'] ?? 1), (int)($q->query['per_page'] ?? 25))), $a('emergencies.view'));
        $r->get("$p/admin/emergencies/{id}", function ($q, $x) {
            $i = (new EmergencyService($this->app))->getScoped($q->attrs['user'], (int)$x['id']); $db = $this->app->db;
            return Response::json(['incident' => $i, 'actions' => $db->all('SELECT * FROM emergency_actions WHERE incident_id=? ORDER BY id', [$i['id']]),
                'updates' => $db->all('SELECT * FROM emergency_updates WHERE incident_id=? ORDER BY id', [$i['id']]), 'escalations' => $db->all('SELECT * FROM emergency_escalations WHERE incident_id=? ORDER BY level', [$i['id']])]);
        }, $a('emergencies.view'));
        $r->post("$p/admin/emergencies/{id}/acknowledge", fn($q, $x) => Response::json((new EmergencyService($this->app))->acknowledge($q->attrs['user'], (int)$x['id'])), $a('emergencies.acknowledge'));
        $r->post("$p/admin/emergencies/{id}/assign", fn($q, $x) => Response::json((new EmergencyService($this->app))->assign($q->attrs['user'], (int)$x['id'], (int)($q->body['assignee_user_id'] ?? 0))), $a('emergencies.assign'));
        $r->post("$p/admin/emergencies/{id}/actions", function ($q, $x) { (new EmergencyService($this->app))->addAction($q->attrs['user'], (int)$x['id'], (string)($q->body['type'] ?? 'note'), (string)($q->body['details'] ?? '')); return Response::json(['ok' => true], 201); }, $a('emergencies.acknowledge'));
        $r->post("$p/admin/emergencies/{id}/updates", function ($q, $x) { (new EmergencyService($this->app))->getScoped($q->attrs['user'], (int)$x['id']); (new EmergencyService($this->app))->addUpdate($q->attrs['user'], (int)$x['id'], (string)($q->body['message'] ?? '')); return Response::json(['ok' => true], 201); }, $a('emergencies.acknowledge'));
        $r->post("$p/admin/emergencies/{id}/status", fn($q, $x) => Response::json((new EmergencyService($this->app))->setStatus($q->attrs['user'], (int)$x['id'], (string)($q->body['status'] ?? ''), $q->body['note'] ?? null)), $a('emergencies.acknowledge'));
        $r->post("$p/admin/emergencies/{id}/close", fn($q, $x) => Response::json((new EmergencyService($this->app))->close($q->attrs['user'], (int)$x['id'], (string)($q->body['reason'] ?? ''), !empty($q->body['false_alarm']))), $a('emergencies.close'));
        $r->post("$p/admin/segments/{id}/odometer", function ($q, $x) {
            $b = $q->body;
            return Response::json((new OdometerService($this->app))->adminRecord($q->attrs['user'], (int)$x['id'], (string)($b['kind'] ?? ''), (float)($b['value'] ?? -1), (string)($b['measured_at'] ?? Clock::nowStr()), $b['reason'] ?? null), 201);
        }, $a('odometer.override'));
        $r->get("$p/admin/attachments/{id}/file", fn($q, $x) => $this->serveFile($q, (int)$x['id'], false), ['auth' => 'admin']);
        $r->post("$p/admin/deviations/{id}/review", function ($q, $x) {
            Authz::require($q->attrs['user'], 'stops.review');
            $d = $this->app->db->one('SELECT v.id, dr.team, dr.region, dr.fleet FROM route_deviations v JOIN trips t ON t.id=v.trip_id JOIN drivers dr ON dr.id=t.driver_id WHERE v.id=?', [(int)$x['id']]);
            if (!$d) { throw new ApiException(404, 'not_found', 'غير موجود'); }
            Authz::requireScope($q->attrs['user'], $d);
            $st = ($q->body['status'] ?? '') === 'flagged' ? 'flagged' : 'accepted';
            $this->app->db->update('route_deviations', ['review_status' => $st, 'reviewed_by' => $q->attrs['user']['id']], 'id=?', [(int)$x['id']]);
            $this->app->audit->log($q->attrs['user']['id'], 'deviation.review', 'deviation', (int)$x['id'], ['status' => $st]);
            return Response::json(['ok' => true]);
        }, ['auth' => 'admin']);
    }

    private function login(Request $q): Response
    {
        $v = Validator::validate($q->body, ['username' => 'required|string|max:64', 'password' => 'required|string|max:200', 'device_id' => 'required|string|max:100', 'device_label' => 'string|max:160', 'push_token' => 'string|max:512', 'mfa_code' => 'string|max:10']);
        return Response::json((new AuthService($this->app))->apiLogin($v['username'], $v['password'], $v['device_id'], $v['device_label'] ?? null, $v['push_token'] ?? null, $q->ip, $v['mfa_code'] ?? null));
    }

    private function config(Request $q): Response
    {
        $s = $this->app->settings;
        return Response::json([
            'server_time' => Clock::nowStr(),
            'gps' => ['poll_interval_s' => $s->int('gps.poll_interval_s'), 'max_accuracy_m' => $s->int('gps.max_accuracy_m'), 'batch_max' => 500],
            'stop' => ['record_after_s' => $s->int('stop.record_after_s'), 'prompt_after_s' => $s->int('stop.prompt_after_s'), 'alert_after_s' => $s->int('stop.alert_after_s'), 'radius_m' => $s->int('stop.radius_m')],
            'arrival' => ['min_readings' => $s->int('arrival.min_readings')],
            'features' => ['inspection' => $s->bool('feature.inspection'), 'inspection_mandatory' => $s->bool('feature.inspection_mandatory'), 'fuel' => $s->bool('feature.fuel')],
            'odometer' => ['photo_required' => $s->bool('odometer.photo_required')],
            'map' => ['tile_url' => $s->get('map.tile_url')],
            'stop_reasons' => $this->app->db->all('SELECT code, name_ar FROM stop_reasons ORDER BY id'),
            'emergency_types' => $this->app->db->all('SELECT code, name_ar FROM emergency_types ORDER BY id'),
            'sos_fallback_number' => Env::get('SOS_FALLBACK_SMS_NUMBER'),
        ]);
    }

    /** مهام السائق فقط (غير المسودة)، مع إشعار «وصلت للتطبيق» ضمنيًا عبر حدث mission.delivered من التطبيق. */
    private function driverMissions(Request $q): Response
    {
        $days = max(1, min(30, (int)($q->query['closed_days'] ?? 3)));
        $rows = $this->app->db->all("SELECT * FROM missions WHERE driver_id=? AND status<>'draft' AND (status NOT IN ('completed','cancelled') OR updated_at >= ?) ORDER BY scheduled_start", [$q->attrs['driver']['id'], Clock::fmt(Clock::now() - $days * 86400)]);
        $ms = new MissionService($this->app);
        return Response::json(['data' => array_map(fn($m) => $ms->driverPayload($m), $rows), 'trip' => (new TripService($this->app))->tripState((int)$q->attrs['driver']['id']), 'server_time' => Clock::nowStr()]);
    }

    private function history(Request $q): Response
    {
        $did = (int)$q->attrs['driver']['id'];
        $db = $this->app->db;
        return Response::json([
            'missions' => $db->all("SELECT id, mission_no, title, status, completed_at, scheduled_start FROM missions WHERE driver_id=? AND status IN ('completed','cancelled','rejected') ORDER BY updated_at DESC LIMIT 100", [$did]),
            'trips' => $db->all("SELECT id, started_at, ended_at, distance_gps_m FROM trips WHERE driver_id=? AND status='closed' ORDER BY id DESC LIMIT 50", [$did]),
            'totals' => $db->one("SELECT COUNT(*) AS trips, COALESCE(SUM(distance_gps_m),0) AS distance_gps_m FROM trips WHERE driver_id=? AND status='closed'", [$did]),
        ]);
    }

    private function upload(Request $q): Response
    {
        $f = $q->files['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) { throw new ApiException(422, 'file_missing', 'لم يصل ملف صالح'); }
        $res = (new AttachmentService($this->app))->storeForDriver((int)$q->attrs['user']['id'], (int)$q->attrs['driver']['id'], (string)($q->body['owner_type'] ?? ''), (string)($q->body['owner_ref'] ?? ''),
            (string)($q->body['client_file_id'] ?? ''), $f, $q->body['sha256'] ?? null, $q->body['captured_at'] ?? null);
        return Response::json($res + ['received' => true], $res['duplicate'] ? 200 : 201);
    }

    private function serveFile(Request $q, int $id, bool $driver): Response
    {
        $svc = new AttachmentService($this->app);
        $a = $svc->authorizedRecord($q->attrs['user'], $id, $driver);
        $res = Response::download($svc->path($a), $a['mime'], 'attachment-' . $id . '.' . AttachmentService::MIMES[$a['mime']]);
        $res->headers['Content-Disposition'] = 'inline; filename="attachment-' . $id . '.' . AttachmentService::MIMES[$a['mime']] . '"';
        return $res;
    }

    private function hookAuth(Request $q, string $envKey): void
    {
        $secret = Env::get($envKey);
        if (!$secret || !hash_equals($secret, (string)($q->header('x-webhook-secret') ?? ''))) { throw new ApiException(401, 'unauthenticated', 'سر الويب هوك غير صحيح'); }
    }

    private function smsDlr(Request $q): Response
    {
        $this->hookAuth($q, 'SMS_WEBHOOK_SECRET');
        (new SmsService($this->app))->deliveryReport((string)($q->body['message_id'] ?? ''), ($q->body['status'] ?? '') === 'delivered', $q->body['error'] ?? null);
        return Response::json(['ok' => true]);
    }

    /** قناة SOS احتياطية: رسالة نصية بصيغة SOS|event_id|lat|lon|unix_ts|from_phone تصل عبر مزود SMS. التسليم غير مضمون. */
    private function smsInbound(Request $q): Response
    {
        $this->hookAuth($q, 'SMS_WEBHOOK_SECRET');
        $parts = explode('|', trim((string)($q->body['text'] ?? '')));
        $phone = preg_replace('/\D/', '', (string)($q->body['from'] ?? ''));
        if (count($parts) < 5 || $parts[0] !== 'SOS' || strlen($phone) < 6) { throw new ApiException(422, 'validation_failed', 'صيغة غير صالحة'); }
        $driver = $this->app->db->one("SELECT d.id FROM drivers d JOIN users u ON u.id=d.user_id WHERE REPLACE(REPLACE(u.phone,'+',''),' ','') LIKE ?", ['%' . substr($phone, -8)]);
        if (!$driver) { throw new ApiException(404, 'not_found', 'رقم غير معروف'); }
        $r = (new EmergencyService($this->app))->create((int)$driver['id'], ['client_event_id' => $parts[1], 'lat' => $parts[2], 'lon' => $parts[3], 'created_at' => gmdate('c', (int)$parts[4]),
            'location_at' => gmdate('c', (int)$parts[4]), 'location_is_last_known' => true, 'notes' => 'وصل عبر SMS احتياطي'], 'sms_fallback');
        return Response::json(['incident_no' => $r['incident']['incident_no'], 'duplicate' => $r['duplicate']]);
    }
}
