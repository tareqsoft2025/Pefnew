<?php
declare(strict_types=1);
namespace Tests;

use App\Core\Clock;
use App\Services\{CronService, MissionService, TripAnalyzer, TripService};

final class TrackingTest extends TestCase
{
    private function startedTrip(): array
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d['driver_id'], $v);
        $tok = $this->loginDriver($d);
        $this->sync($tok, [$this->ev('mission.accept', ['mission_id' => $m['id'], 'client_trip_id' => 'T'])]);
        return [$admin, $d, $v, $m, $tok];
    }

    public function testGpsBatchIdempotentAndDistanceNotDoubled(): void
    {
        [, , , , $tok] = $this->startedTrip();
        $pts = [$this->pt('a', 0, 24.7000, 46.7), $this->pt('b', 60, 24.7090, 46.7), $this->pt('c', 120, 24.7180, 46.7)];
        $r1 = $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => $pts], $tok)['json'];
        $r2 = $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => $pts], $tok)['json'];
        $this->assertSame([3, 0], [$r1['accepted'], $r1['duplicates']]);
        $this->assertSame([0, 3], [$r2['accepted'], $r2['duplicates']]);
        $this->assertSame(3, (int)self::$db->val('SELECT COUNT(*) FROM gps_points'));
        $dist = (int)self::$db->val('SELECT distance_gps_m FROM trips');
        $this->assertEqualsWithDelta(2000, $dist, 40);   // 0.018° ≈ 2 كم
    }

    public function testOutOfOrderOfflineBatchGivesSameResult(): void
    {
        [, , , , $tok] = $this->startedTrip();
        $a = [$this->pt('a', 0, 24.700, 46.7), $this->pt('b', 60, 24.709, 46.7)];
        $b = [$this->pt('c', 120, 24.718, 46.7), $this->pt('d', 180, 24.727, 46.7)];
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => $b], $tok);   // الأحدث أولًا
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => $a], $tok);
        $this->assertEqualsWithDelta(3000, (int)self::$db->val('SELECT distance_gps_m FROM trips'), 60);
        $this->assertSame('24.727000', self::$db->val('SELECT last_lat FROM trips'));
    }

    public function testRejectsInvalidAndFuturePoints(): void
    {
        [, , , , $tok] = $this->startedTrip();
        $r = $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => [['id' => 'x', 'ts' => gmdate('c', self::T0 + 99999), 'lat' => 24.7, 'lon' => 46.7], ['id' => 'y', 'ts' => gmdate('c'), 'lat' => 200, 'lon' => 1]]], $tok)['json'];
        $this->assertSame(0, $r['accepted']);
        $this->assertCount(2, $r['rejected']);
    }

    public function testStopDetectedOnceAndExplainable(): void
    {
        [, $d, , , $tok] = $this->startedTrip();
        $pts = [$this->pt('m1', 0, 24.700, 46.700, 10, 12), $this->pt('m2', 30, 24.701, 46.700, 10, 12)];
        for ($i = 0; $i < 13; $i++) { $pts[] = $this->pt("s$i", 60 + $i * 60, 24.7011, 46.7001, 10, 0); }   // 12 دقيقة ثبات
        $pts[] = $this->pt("go", 900, 24.710, 46.700, 10, 12);
        Clock::advance(1000);   // النقاط تُرفع لاحقًا (لا نقاط من المستقبل)
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => $pts], $tok);
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => $pts], $tok);   // إعادة إرسال
        $stops = self::$db->all('SELECT * FROM trip_stops');
        $this->assertCount(1, $stops);
        $this->assertSame('ended', $stops[0]['status']);
        $this->assertGreaterThan(400, (int)$stops[0]['duration_s']);
        // تنبيه prompt و alert بوقت الحدث الأصلي
        $al = self::$db->all('SELECT level, event_time FROM stop_alerts ORDER BY level');
        $this->assertCount(2, $al);
        $this->assertLessThan(Clock::now(), strtotime($al[0]['event_time'] . ' UTC') + 1);
        // تفسير التوقف idempotent
        $r = $this->sync($tok, [$this->ev('stop.explain', ['stop_id' => $stops[0]['id'], 'reason' => 'traffic', 'notes' => 'ازدحام'], 'ex1'), $this->ev('stop.explain', ['stop_id' => $stops[0]['id'], 'reason' => 'traffic'], 'ex1')]);
        $this->assertSame('applied', $r['results'][0]['status']);
        $this->assertSame('duplicate', $r['results'][1]['status']);
        $this->assertSame(1, (int)self::$db->val('SELECT COUNT(*) FROM stop_explanations'));
    }

    public function testStopLinkedToAllActiveMissionsButStoredOnce(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m1 = $this->sentMission($admin, $d['driver_id'], $v); $m2 = $this->sentMission($admin, $d['driver_id'], $v, ['title' => 'x']);
        $tok = $this->loginDriver($d);
        $this->sync($tok, [$this->ev('mission.accept', ['mission_id' => $m1['id'], 'client_trip_id' => 'T']), $this->ev('mission.accept', ['mission_id' => $m2['id'], 'client_trip_id' => 'T'])]);
        $pts = []; for ($i = 0; $i < 6; $i++) { $pts[] = $this->pt("s$i", $i * 60, 24.7, 46.7, 10, 0); }
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => $pts], $tok);
        $this->assertSame(1, (int)self::$db->val('SELECT COUNT(*) FROM trip_stops'));
        $this->assertSame(2, (int)self::$db->val('SELECT COUNT(*) FROM trip_stop_missions'));
    }

    public function testLowAccuracyPointsIgnoredForStops(): void
    {
        $pts = [['t' => 0, 'lat' => 24.7, 'lon' => 46.7, 'acc' => 10, 'speed' => 0], ['t' => 120, 'lat' => 24.8, 'lon' => 46.8, 'acc' => 500, 'speed' => 0], ['t' => 200, 'lat' => 24.7, 'lon' => 46.7, 'acc' => 10, 'speed' => 0]];
        $res = TripAnalyzer::analyze($pts, ['max_accuracy' => 60, 'stop_radius' => 40, 'stop_record_s' => 180, 'dev_distance' => 300, 'dev_duration' => 120]);
        $this->assertCount(1, $res['stops']);   // قفزة الدقة المنخفضة لم تقطع التوقف
        $this->assertSame(0, $res['distance_m']);
    }

    public function testRouteDeviationDetectedWithDurationAndAccuracy(): void
    {
        $route = [[24.700, 46.700], [24.700, 46.800]];   // شرقًا
        $cfg = ['max_accuracy' => 60, 'stop_radius' => 40, 'stop_record_s' => 180, 'dev_distance' => 300, 'dev_duration' => 120];
        $on = fn($t, $lon) => ['t' => $t, 'lat' => 24.7001, 'lon' => $lon, 'acc' => 10, 'speed' => 10];
        $off = fn($t, $lon) => ['t' => $t, 'lat' => 24.7100, 'lon' => $lon, 'acc' => 10, 'speed' => 10];   // ~1.1 كم شمالًا
        // انحراف لحظي (<120ث) لا يُسجل
        $short = TripAnalyzer::analyze([$on(0, 46.71), $off(30, 46.72), $off(60, 46.73), $on(90, 46.74)], $cfg, [$route]);
        $this->assertCount(0, $short['deviations']);
        // انحراف طويل يُسجل
        $long = TripAnalyzer::analyze([$on(0, 46.71), $off(30, 46.72), $off(100, 46.73), $off(200, 46.74), $on(260, 46.75)], $cfg, [$route]);
        $this->assertCount(1, $long['deviations']);
        $this->assertGreaterThan(1000, $long['deviations'][0]['max_distance_m']);
        // دقة سيئة (قراءة بعيدة غير موثوقة) لا تُنشئ انحرافًا
        $noisy = TripAnalyzer::analyze([$on(0, 46.71), ['t' => 30, 'lat' => 24.71, 'lon' => 46.72, 'acc' => 400, 'speed' => 10], ['t' => 200, 'lat' => 24.71, 'lon' => 46.73, 'acc' => 400, 'speed' => 10], $on(260, 46.75)], $cfg, [$route]);
        $this->assertCount(0, $noisy['deviations']);
    }

    public function testDeviationPersistedAndNotifiedThroughIngest(): void
    {
        [$admin, $d, , $m, $tok] = $this->startedTrip();
        $ms = new MissionService($this->app);
        $r = $ms->proposeRoute($admin, (int)$m['id'], 'manual', [[24.700, 46.700], [24.700, 46.800]]);
        $ms->approveRoute($admin, (int)$r['id']);
        $pts = [$this->pt('a', 0, 24.7001, 46.71, 10, 10), $this->pt('b', 30, 24.71, 46.72, 10, 10), $this->pt('c', 100, 24.71, 46.73, 10, 10), $this->pt('d', 200, 24.71, 46.74, 10, 10)];
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => $pts], $tok);
        $this->assertSame(1, (int)self::$db->val('SELECT COUNT(*) FROM route_deviations'));
        $this->assertSame('open', self::$db->val('SELECT status FROM route_deviations'));
        $this->assertGreaterThan(0, (int)self::$db->val("SELECT COUNT(*) FROM notifications WHERE type='route_deviation' AND user_id=?", [$d['user_id']]));
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => $pts], $tok);
        $this->assertSame(1, (int)self::$db->val('SELECT COUNT(*) FROM route_deviations'));   // لا تكرار
    }

    public function testLiveMapNeverShowsStalePositionAsLive(): void
    {
        [$admin, , , , $tok] = $this->startedTrip();
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => [$this->pt('a', 0, 24.7, 46.7)]], $tok);
        $this->adminSession($admin);
        $live = $this->api('GET', '/api/v1/admin/live')['json']['data'][0];
        $this->assertTrue($live['is_live']);
        Clock::advance(900);   // انقطاع 15 دقيقة
        $live = $this->api('GET', '/api/v1/admin/live')['json']['data'][0];
        $this->assertFalse($live['is_live']);
        $this->assertSame('offline', $live['connection']);
        $this->assertNotNull($live['position_at']);          // آخر موقع وتوقيته ظاهران
        $this->assertGreaterThanOrEqual(900, $live['position_age_s']);
    }

    public function testHistoryViewIsScopedAndAudited(): void
    {
        [$admin, , , , $tok] = $this->startedTrip();
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => [$this->pt('a', 0, 24.7, 46.7)]], $tok);
        $tripId = (int)self::$db->val('SELECT id FROM trips');
        $blocked = $this->makeUser('fu2', ['follow_up'], [['team', 'Z']]);
        $this->adminSession($blocked);
        // السائق بلا فريق؛ المستخدم مقيد بفريق Z ⇒ خارج النطاق
        $this->assertSame(403, $this->api('GET', "/api/v1/admin/trips/$tripId/track")['status']);
        $this->session->destroy(); $this->adminSession($admin);
        $this->assertSame(200, $this->api('GET', "/api/v1/admin/trips/$tripId/track")['status']);
        $this->assertSame(1, (int)self::$db->val("SELECT COUNT(*) FROM audit_logs WHERE action='tracking.view_history'"));
    }

    public function testRetentionPurgesOldPointsOnlyForClosedTrips(): void
    {
        [, , , , $tok] = $this->startedTrip();
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => [$this->pt('a', 0, 24.7, 46.7)]], $tok);
        Clock::advance(86400 * 400);
        (new CronService($this->app))->run();
        $this->assertSame(1, (int)self::$db->val('SELECT COUNT(*) FROM gps_points'));   // الرحلة ما زالت نشطة
        self::$db->exec("UPDATE trips SET status='closed', ended_at=?", [Clock::fmt(Clock::now() - 86400 * 300)]);
        (new CronService($this->app))->run();
        $this->assertSame(0, (int)self::$db->val('SELECT COUNT(*) FROM gps_points'));
    }

    public function testStopExplainByDeviceTimeAndPendingList(): void
    {
        [, , , , $tok] = $this->startedTrip();
        $pts = []; for ($i = 0; $i < 8; $i++) { $pts[] = $this->pt("s$i", $i * 60, 24.7, 46.7, 10, 0); }
        Clock::advance(1000);
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => $pts], $tok);
        $pending = $this->api('GET', '/api/v1/trips/current/pending', [], $tok)['json'];
        $this->assertCount(1, $pending['stops']);
        $r = $this->sync($tok, [$this->ev('stop.explain', ['started_at' => gmdate('c', self::T0 + 10), 'reason' => 'rest'])]);
        $this->assertSame('applied', $r['results'][0]['status']);
        $this->assertCount(0, $this->api('GET', '/api/v1/trips/current/pending', [], $tok)['json']['stops']);
        // توقف لم يصل بعد ⇒ retry لا رفض نهائي
        $r = $this->sync($tok, [$this->ev('stop.explain', ['started_at' => gmdate('c', self::T0 - 99999), 'reason' => 'rest'])]);
        $this->assertSame('retry', $r['results'][0]['status']);
    }
}
