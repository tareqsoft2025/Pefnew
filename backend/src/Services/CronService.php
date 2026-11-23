<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\Clock;

/** مهام cron الدورية (كل دقيقة على الاستضافة المشتركة: * * * * * php bin/cron.php). */
final class CronService
{
    public function __construct(private App $app) {}

    public function run(): array
    {
        $db = $this->app->db;
        $out = ['escalations' => (new EmergencyService($this->app))->escalate(), 'sms' => (new SmsService($this->app))->dispatchPending()];
        // قراءة عداد بداية معلقة بعد 10 دقائق من القبول: تنبيه المسؤول مرة واحدة
        $n = 0;
        foreach ($db->all("SELECT s.id, s.trip_id, d.team, d.region, d.fleet, u.full_name FROM vehicle_segments s JOIN trips t ON t.id=s.trip_id JOIN drivers d ON d.id=t.driver_id JOIN users u ON u.id=d.user_id
            WHERE s.status='open' AND s.odometer_pending=1 AND s.pending_notified=0 AND s.started_at <= ?", [Clock::fmt(Clock::now() - 600)]) as $s) {
            (new NotificationService($this->app))->notifyPermission('stops.review', 'odometer_pending', 'قراءة عداد البداية معلقة', $s['full_name'], ['segment_id' => (int)$s['id']], $s);
            $db->update('vehicle_segments', ['pending_notified' => 1], 'id=?', [$s['id']]);
            $n++;
        }
        $out['odometer_pending_alerts'] = $n;
        // تقييم تنبيهات التوقف للرحلات النشطة حتى لو لم تصل نقاط جديدة (مرور الوقت)
        foreach ($db->all("SELECT id FROM trips WHERE status='active' AND last_received_at >= ?", [Clock::fmt(Clock::now() - 3600)]) as $t) { (new TripService($this->app))->evaluateStopAlerts((int)$t['id']); }
        // سياسة الاحتفاظ
        $gpsDays = $this->app->settings->int('retention.gps_days');
        $out['purged_gps'] = $gpsDays > 0 ? $db->exec("DELETE g FROM gps_points g JOIN trips t ON t.id=g.trip_id WHERE t.status='closed' AND t.ended_at < ?", [Clock::fmt(Clock::now() - $gpsDays * 86400)]) : 0;
        $db->exec('DELETE FROM rate_limits WHERE window_start < ?', [Clock::now() - 86400]);
        $db->exec('DELETE FROM api_tokens WHERE refresh_expires_at < ?', [Clock::fmt(Clock::now() - 86400 * 7)]);
        return $out;
    }
}
