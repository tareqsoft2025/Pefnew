<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{Clock, Db};

/** SMS تنبيه فقط للمهام غير المؤكد وصولها. الحالات الأربع مفصولة في sms_messages. */
final class SmsService
{
    private Db $db;
    public function __construct(private App $app) { $this->db = $app->db; }

    /** يُستدعى من cron: مهام مرسلة ولم تصل للتطبيق بعد المهلة. @return int عدد الرسائل المطلوبة */
    public function dispatchPending(): int
    {
        if (!$this->app->settings->bool('mission.unconfirmed_sms_enabled')) { return 0; }
        $timeout = $this->app->settings->int('sms.undelivered_timeout_s');
        $cut = Clock::fmt(Clock::now() - $timeout);
        $n = 0;
        $rows = $this->db->all("SELECT m.id, m.mission_no, m.version, u.id AS uid, u.phone FROM missions m JOIN drivers d ON d.id=m.driver_id JOIN users u ON u.id=d.user_id
            JOIN mission_events e ON e.mission_id=m.id AND e.to_status='sent' AND e.id=(SELECT MAX(id) FROM mission_events WHERE mission_id=m.id AND to_status='sent')
            WHERE m.status='sent' AND e.recorded_at <= ? AND u.phone IS NOT NULL AND u.phone<>''
              AND NOT EXISTS (SELECT 1 FROM sms_messages s WHERE s.mission_id=m.id AND s.mission_version IS NOT NULL AND s.requested_at >= e.recorded_at)", [$cut]);
        foreach ($rows as $r) {
            $body = 'تنبيه: لديك تكليف جديد (' . $r['mission_no'] . '). افتح التطبيق عند توفر الإنترنت للمزامنة. هذه الرسالة ليست قبولًا للمهمة.';
            $id = $this->db->insert('sms_messages', ['purpose' => 'mission_alert', 'user_id' => $r['uid'], 'mission_id' => $r['id'], 'mission_version' => $r['version'],
                'to_number' => $r['phone'], 'body' => $body, 'requested_at' => Clock::nowStr(), 'provider' => $this->app->sms->name()]);
            $res = $this->app->sms->send($r['phone'], $body);
            if ($res['ok']) { $this->db->update('sms_messages', ['provider_accepted_at' => Clock::nowStr(), 'provider_message_id' => $res['message_id']], 'id=?', [$id]); }
            else { $this->db->update('sms_messages', ['failed_at' => Clock::nowStr(), 'error' => $res['error']], 'id=?', [$id]); }
            $n++;
        }
        return $n;
    }

    /** تقرير تسليم من المزود (webhook). */
    public function deliveryReport(string $providerMessageId, bool $delivered, ?string $error = null): void
    {
        if ($delivered) { $this->db->exec('UPDATE sms_messages SET delivered_at=? WHERE provider_message_id=?', [Clock::nowStr(), $providerMessageId]); }
        else { $this->db->exec('UPDATE sms_messages SET failed_at=?, error=? WHERE provider_message_id=?', [Clock::nowStr(), $error, $providerMessageId]); }
    }
}
