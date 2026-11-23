<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{ApiException, Authz, Clock, Db};

/** بلاغات SOS: استقبال idempotent، دورة حياة، إجراءات، تصعيد بالخادم (cron). لا حذف لأي بلاغ وصل. */
final class EmergencyService
{
    public const OPEN = ['new', 'acknowledged', 'in_progress', 'awaiting_update'];
    private const TRANSITIONS = [
        'new' => ['acknowledged', 'false_alarm'],
        'acknowledged' => ['in_progress', 'awaiting_update', 'resolved', 'false_alarm'],
        'in_progress' => ['awaiting_update', 'resolved', 'false_alarm'],
        'awaiting_update' => ['in_progress', 'resolved', 'false_alarm'],
        'resolved' => ['closed', 'in_progress'],
        'closed' => [], 'false_alarm' => [],
    ];
    private Db $db;
    public function __construct(private App $app) { $this->db = $app->db; }

    public function get(int $id): array
    {
        $i = $this->db->one('SELECT * FROM emergency_incidents WHERE id=?', [$id]);
        if (!$i) { throw new ApiException(404, 'not_found', 'البلاغ غير موجود'); }
        return $i;
    }

    public function getScoped(array $user, int $id): array
    {
        Authz::require($user, 'emergencies.view');
        $i = $this->get($id);
        Authz::requireScope($user, $i);
        return $i;
    }

    /** SOS عاجل: لا حقول إلزامية سوى المعرف. received_at = وقت وصوله فعلًا للخادم. */
    public function create(int $driverId, array $in, string $channel = 'internet'): array
    {
        $eid = (string)($in['client_event_id'] ?? '');
        if ($eid === '' || strlen($eid) > 64) { throw new ApiException(422, 'validation_failed', 'client_event_id مطلوب'); }
        $existing = $this->db->one('SELECT * FROM emergency_incidents WHERE client_event_id=?', [$eid]);
        if ($existing) { return ['incident' => $existing, 'duplicate' => true]; }
        $driver = $this->db->one('SELECT d.*, u.full_name FROM drivers d JOIN users u ON u.id=d.user_id WHERE d.id=?', [$driverId]);
        $typeId = null;
        if (!empty($in['type'])) { $typeId = $this->db->val('SELECT id FROM emergency_types WHERE code=?', [$in['type']]) ?: null; }
        $trip = $this->db->one("SELECT id FROM trips WHERE driver_id=? AND status='active'", [$driverId]);
        $seg = $trip ? $this->db->one("SELECT vehicle_id FROM vehicle_segments WHERE trip_id=? AND status='open'", [$trip['id']]) : null;
        $missionId = !empty($in['mission_id']) ? (int)$in['mission_id'] : null;
        if ($missionId && !$this->db->val('SELECT 1 FROM missions WHERE id=? AND driver_id=?', [$missionId, $driverId])) { $missionId = null; }
        $lat = isset($in['lat']) && is_numeric($in['lat']) && abs((float)$in['lat']) <= 90 ? (float)$in['lat'] : null;
        $lon = isset($in['lon']) && is_numeric($in['lon']) && abs((float)$in['lon']) <= 180 ? (float)$in['lon'] : null;
        if ($lat === null || $lon === null) {   // آخر موقع معروف للرحلة كخيار احتياطي، مع إظهار قدمه
            $tl = $trip ? $this->db->one('SELECT last_lat, last_lon, last_point_at, last_accuracy_m FROM trips WHERE id=?', [$trip['id']]) : null;
            if ($tl && $tl['last_lat'] !== null) { $lat = (float)$tl['last_lat']; $lon = (float)$tl['last_lon']; $in['location_at'] = $tl['last_point_at']; $in['accuracy'] = $tl['last_accuracy_m']; $in['location_is_last_known'] = true; }
            else { $lat = $lon = null; }
        }
        $now = Clock::nowStr();
        $row = [
            'incident_no' => 'TMP' . bin2hex(random_bytes(6)), 'client_event_id' => $eid, 'driver_id' => $driverId, 'vehicle_id' => $seg['vehicle_id'] ?? null,
            'mission_id' => $missionId, 'trip_id' => $trip['id'] ?? null, 'type_id' => $typeId, 'status' => 'new',
            'created_at_client' => !empty($in['created_at']) ? Clock::fmt(min(Clock::parse((string)$in['created_at']), Clock::now())) : $now, 'received_at' => $now,
            'lat' => $lat, 'lon' => $lon, 'accuracy_m' => $in['accuracy'] ?? null, 'location_at' => !empty($in['location_at']) ? Clock::fmt(Clock::parse((string)$in['location_at'])) : null,
            'location_is_last_known' => !empty($in['location_is_last_known']) ? 1 : 0, 'notes' => isset($in['notes']) ? mb_substr((string)$in['notes'], 0, 2000) : null,
            'channel' => $channel, 'device_id' => $in['device_id'] ?? null, 'team' => $driver['team'], 'region' => $driver['region'], 'fleet' => $driver['fleet'],
        ];
        try {
            $id = $this->db->tx(function () use ($row) {
                $id = $this->db->insert('emergency_incidents', $row);
                $this->db->update('emergency_incidents', ['incident_no' => sprintf('SOS%s-%05d', gmdate('ymd'), $id)], 'id=?', [$id]);
                return $id;
            });
        } catch (\PDOException $e) {
            if (Db::isDuplicate($e)) { return ['incident' => $this->db->one('SELECT * FROM emergency_incidents WHERE client_event_id=?', [$eid]), 'duplicate' => true]; }
            throw $e;
        }
        $this->app->audit->log((int)$driver['user_id'], 'emergency.received', 'emergency', $id, ['channel' => $channel, 'client_created' => $row['created_at_client']]);
        $inc = $this->get($id);
        (new NotificationService($this->app))->notifyPermission('emergencies.acknowledge', 'sos_new', 'بلاغ طوارئ جديد ' . $inc['incident_no'], $driver['full_name'], ['incident_id' => $id], $inc);
        return ['incident' => $inc, 'duplicate' => false];
    }

    private function move(array $inc, string $to, ?int $actor, ?string $note = null): void
    {
        if (!in_array($to, self::TRANSITIONS[$inc['status']], true)) {
            throw new ApiException(409, 'invalid_transition', "لا يمكن الانتقال من {$inc['status']} إلى $to");
        }
        $this->db->update('emergency_incidents', ['status' => $to], 'id=?', [$inc['id']]);
        $this->db->insert('emergency_actions', ['incident_id' => $inc['id'], 'actor_user_id' => $actor ?? 0, 'action_type' => 'status_change', 'details' => "{$inc['status']} → $to" . ($note ? " ($note)" : ''), 'created_at' => Clock::nowStr()]);
    }

    public function acknowledge(array $user, int $id): array
    {
        Authz::require($user, 'emergencies.acknowledge');
        $inc = $this->getScoped($user, $id);
        $this->db->tx(function () use ($inc, $user) {
            $this->move($inc, 'acknowledged', (int)$user['id']);
            $this->db->update('emergency_incidents', ['acknowledged_by' => $user['id'], 'acknowledged_at' => Clock::nowStr()], 'id=?', [$inc['id']]);
        });
        $this->app->audit->log($user['id'], 'emergency.acknowledge', 'emergency', $id);
        $this->addUpdate($user, $id, 'تم استلام بلاغك وجارٍ التعامل معه');
        return $this->get($id);
    }

    public function assign(array $user, int $id, int $assigneeUserId): array
    {
        Authz::require($user, 'emergencies.assign');
        $inc = $this->getScoped($user, $id);
        if (in_array($inc['status'], ['closed', 'false_alarm'], true)) { throw new ApiException(409, 'incident_closed', 'البلاغ مغلق'); }
        $target = $this->app->authz->loadUser($assigneeUserId);
        if (!$target || !Authz::can($target, 'emergencies.view')) { throw new ApiException(422, 'invalid_assignee', 'المستخدم لا يملك صلاحية متابعة الطوارئ'); }
        $this->db->insert('emergency_assignments', ['incident_id' => $id, 'assignee_user_id' => $assigneeUserId, 'assigned_by' => $user['id'], 'assigned_at' => Clock::nowStr()]);
        $this->db->update('emergency_incidents', ['followup_user_id' => $assigneeUserId], 'id=?', [$id]);
        $this->app->audit->log($user['id'], 'emergency.assign', 'emergency', $id, ['assignee' => $assigneeUserId]);
        (new NotificationService($this->app))->notify($assigneeUserId, 'sos_assigned', 'أُسند إليك بلاغ ' . $inc['incident_no'], null, ['incident_id' => $id]);
        return $this->get($id);
    }

    public function addAction(array $user, int $id, string $type, string $details): void
    {
        Authz::require($user, 'emergencies.acknowledge');
        $this->getScoped($user, $id);
        if (!in_array($type, ['call', 'message', 'dispatch', 'note', 'other'], true)) { throw new ApiException(422, 'validation_failed', 'نوع إجراء غير صالح'); }
        if (trim($details) === '') { throw new ApiException(422, 'validation_failed', 'تفاصيل الإجراء مطلوبة'); }
        $this->db->insert('emergency_actions', ['incident_id' => $id, 'actor_user_id' => $user['id'], 'action_type' => $type, 'details' => mb_substr($details, 0, 2000), 'created_at' => Clock::nowStr()]);
        $this->app->audit->log($user['id'], 'emergency.action', 'emergency', $id, ['type' => $type]);
    }

    public function addUpdate(array $user, int $id, string $message, bool $visibleToDriver = true): void
    {
        $this->db->insert('emergency_updates', ['incident_id' => $id, 'author_user_id' => $user['id'], 'message' => mb_substr($message, 0, 1000), 'visible_to_driver' => $visibleToDriver ? 1 : 0, 'created_at' => Clock::nowStr()]);
        if ($visibleToDriver) {
            $inc = $this->get($id);
            $uid = $this->db->val('SELECT user_id FROM drivers WHERE id=?', [$inc['driver_id']]);
            (new NotificationService($this->app))->notify((int)$uid, 'sos_update', 'تحديث على بلاغ الطوارئ', $message, ['incident_id' => $id]);
        }
    }

    public function setStatus(array $user, int $id, string $to, ?string $note = null): array
    {
        Authz::require($user, 'emergencies.acknowledge');
        $inc = $this->getScoped($user, $id);
        if (in_array($to, ['resolved', 'false_alarm'], true) && !Authz::can($user, 'emergencies.close') && $to === 'false_alarm') { throw new ApiException(403, 'forbidden', 'لا تملك الصلاحية المطلوبة'); }
        $this->db->tx(fn() => $this->move($inc, $to, (int)$user['id'], $note));
        $this->app->audit->log($user['id'], 'emergency.status', 'emergency', $id, ['to' => $to, 'note' => $note]);
        return $this->get($id);
    }

    public function close(array $user, int $id, string $reason, bool $falseAlarm = false): array
    {
        Authz::require($user, 'emergencies.close');
        $inc = $this->getScoped($user, $id);
        if (trim($reason) === '') { throw new ApiException(422, 'reason_required', 'سبب الإغلاق إلزامي'); }
        $this->db->tx(function () use ($inc, $user, $reason, $falseAlarm) {
            if ($falseAlarm) { $this->move($inc, 'false_alarm', (int)$user['id'], $reason); }
            else {
                if ($inc['status'] !== 'resolved') { $this->move($inc, 'resolved', (int)$user['id'], 'تمت المعالجة'); $inc['status'] = 'resolved'; }
                $this->move($inc, 'closed', (int)$user['id'], $reason);
            }
            $this->db->update('emergency_incidents', ['closed_at' => Clock::nowStr(), 'close_reason' => mb_substr($reason, 0, 500)], 'id=?', [$inc['id']]);
        });
        $this->app->audit->log($user['id'], 'emergency.close', 'emergency', $id, ['reason' => $reason, 'false_alarm' => $falseAlarm]);
        $this->addUpdate($user, $id, $falseAlarm ? 'أُغلق البلاغ كإنذار خاطئ' : 'تم إغلاق البلاغ: ' . $reason);
        return $this->get($id);
    }

    /** تصعيد: يُنفذ من cron. يعمل فقط على بلاغات وصلت للخادم ولم يؤكد استلامها. */
    public function escalate(): int
    {
        $timeout = $this->app->settings->int('emergency.ack_timeout_s');
        $interval = max(60, $this->app->settings->int('emergency.escalation_interval_s'));
        $max = $this->app->settings->int('emergency.max_escalation_level');
        $count = 0; $now = Clock::now();
        foreach ($this->db->all("SELECT * FROM emergency_incidents WHERE status='new'") as $inc) {
            $age = $now - Clock::parse($inc['received_at']);
            $nextLevel = (int)$inc['escalation_level'] + 1;
            if ($nextLevel > $max || $age < $timeout + ($nextLevel - 1) * $interval) { continue; }
            $notified = $this->escalationTargets($inc, $nextLevel);
            $ns = new NotificationService($this->app);
            foreach ($notified as $uid) { $ns->notify($uid, 'sos_escalated', "تصعيد: بلاغ {$inc['incident_no']} غير مستلم", 'المستوى ' . $nextLevel . ' — مضى ' . intdiv($age, 60) . ' دقيقة', ['incident_id' => (int)$inc['id']]); }
            $this->db->tx(function () use ($inc, $nextLevel, $notified, $age) {
                $this->db->insert('emergency_escalations', ['incident_id' => $inc['id'], 'level' => $nextLevel, 'notified_user_id' => $notified[0] ?? null,
                    'reason' => 'لم يُؤكد الاستلام خلال ' . intdiv($age, 60) . ' دقيقة من وصول البلاغ', 'escalated_at' => Clock::nowStr()]);
                $this->db->update('emergency_incidents', ['escalation_level' => $nextLevel], 'id=?', [$inc['id']]);
            });
            $this->app->audit->log(null, 'emergency.escalate', 'emergency', $inc['id'], ['level' => $nextLevel, 'notified' => $notified]);
            $count++;
        }
        return $count;
    }

    private function escalationTargets(array $inc, int $level): array
    {
        $ids = [];
        $alt = $this->app->settings->get('emergency.alternate_user_id');
        if ($alt !== '' && $this->db->val('SELECT 1 FROM users WHERE id=? AND is_active=1', [(int)$alt])) { $ids[] = (int)$alt; }
        $perm = $level >= 2 ? 'emergencies.close' : 'emergencies.acknowledge';
        foreach ($this->db->all("SELECT DISTINCT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id LEFT JOIN role_permissions rp ON rp.role_id=r.id LEFT JOIN permissions p ON p.id=rp.permission_id
            JOIN users u ON u.id=ur.user_id AND u.is_active=1 WHERE r.code='system_admin' OR p.code=?", [$perm]) as $r) {
            $u = $this->app->authz->loadUser((int)$r['user_id']);
            if ($u && Authz::inScope($u, $inc)) { $ids[] = (int)$r['user_id']; }
        }
        return array_values(array_unique($ids));
    }

    /** ما يراه السائق عن بلاغه. */
    public function driverView(int $driverId, int $id): array
    {
        $inc = $this->get($id);
        if ((int)$inc['driver_id'] !== $driverId) { throw new ApiException(404, 'not_found', 'البلاغ غير موجود'); }
        return ['id' => (int)$inc['id'], 'incident_no' => $inc['incident_no'], 'status' => $inc['status'], 'received_at' => $inc['received_at'], 'acknowledged_at' => $inc['acknowledged_at'],
            'updates' => $this->db->all('SELECT id, message, created_at FROM emergency_updates WHERE incident_id=? AND visible_to_driver=1 ORDER BY id', [$id])];
    }
}
