<?php
declare(strict_types=1);
namespace Tests;

use App\Core\{ApiException, Clock};
use App\Services\MissionService;

final class MissionFlowTest extends TestCase
{
    public function testAcceptStartsTripAndTrackingWithoutOdometer(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d['driver_id'], $v);
        $tok = $this->loginDriver($d);
        $list = $this->api('GET', '/api/v1/missions', [], $tok)['json'];
        $this->assertCount(1, $list['data']);
        $r = $this->sync($tok, [$this->ev('mission.accept', ['mission_id' => $m['id'], 'client_trip_id' => 'trip-A'])]);
        $this->assertSame('applied', $r['results'][0]['status']);
        $this->assertSame('active', $r['results'][0]['data']['mission']['status']);
        // التتبع بدأ (رحلة + مرحلة مركبة) والعداد معلق لكن لا يمنع GPS
        $this->assertTrue($r['trip']['tracking_required']);
        $this->assertTrue($r['trip']['segment']['odometer_pending']);
        $g = $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'trip-A', 'points' => [$this->pt('p1', 0, 24.7, 46.7)]], $tok);
        $this->assertSame(1, $g['json']['accepted']);
    }

    public function testRejectNeedsReasonAndManagerCanReassign(): void
    {
        $admin = $this->makeAdmin(); $d1 = $this->makeDriver('drvone'); $d2 = $this->makeDriver('drvtwo'); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d1['driver_id'], $v);
        $t1 = $this->loginDriver($d1);
        $r = $this->sync($t1, [$this->ev('mission.reject', ['mission_id' => $m['id'], 'reason' => ''])]);
        $this->assertSame('rejected', $r['results'][0]['status']);
        $r = $this->sync($t1, [$this->ev('mission.reject', ['mission_id' => $m['id'], 'reason' => 'مريض'])]);
        $this->assertSame('applied', $r['results'][0]['status']);
        $ms = new MissionService($this->app);
        $this->assertSame('rejected', $ms->get((int)$m['id'])['status']);
        $ms->assign($admin, (int)$m['id'], $d2['driver_id'], null, 'إعادة إسناد');
        $this->assertSame('sent', $ms->get((int)$m['id'])['status']);
        // السائق الأول لا يرى المهمة، الثاني يراها
        $this->assertCount(0, $this->api('GET', '/api/v1/missions', [], $t1)['json']['data']);
        $this->assertCount(1, $this->api('GET', '/api/v1/missions', [], $this->loginDriver($d2))['json']['data']);
        $this->assertSame(404, $this->api('GET', '/api/v1/missions/' . $m['id'], [], $t1)['status']);
    }

    public function testDriverCannotTouchOtherDriversMission(): void
    {
        $admin = $this->makeAdmin(); $d1 = $this->makeDriver('drvone'); $d2 = $this->makeDriver('drvtwo'); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d1['driver_id'], $v);
        $r = $this->sync($this->loginDriver($d2), [$this->ev('mission.accept', ['mission_id' => $m['id']])]);
        $this->assertSame('rejected', $r['results'][0]['status']);
        $this->assertSame('forbidden', $r['results'][0]['error']['code']);
    }

    public function testCancelWhileOfflineYieldsConflict(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d['driver_id'], $v);
        (new MissionService($this->app))->cancel($admin, (int)$m['id'], 'تغيّر الخطة');
        $r = $this->sync($this->loginDriver($d), [$this->ev('mission.accept', ['mission_id' => $m['id'], 'client_trip_id' => 't'])]);
        $this->assertSame('conflict', $r['results'][0]['status']);
        $this->assertSame('cancelled', $r['results'][0]['server_mission']['status']);
    }

    public function testSyncEventsAreIdempotent(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d['driver_id'], $v);
        $tok = $this->loginDriver($d);
        $e = $this->ev('mission.accept', ['mission_id' => $m['id'], 'client_trip_id' => 't1'], 'evt-1');
        $this->assertSame('applied', $this->sync($tok, [$e])['results'][0]['status']);
        $second = $this->sync($tok, [$e])['results'][0];
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame(1, (int)self::$db->val('SELECT COUNT(*) FROM mission_responses'));
    }

    public function testVehicleConflictNeedsDocumentedException(): void
    {
        $admin = $this->makeAdmin(); $d1 = $this->makeDriver('drvone'); $d2 = $this->makeDriver('drvtwo'); $v = $this->makeVehicle();
        $this->sentMission($admin, $d1['driver_id'], $v);
        $ms = new MissionService($this->app);
        $m2 = $ms->create($admin, ['title' => 'ثانية', 'scheduled_start' => gmdate('c', self::T0 + 3700)]);
        try { $ms->assign($admin, (int)$m2['id'], $d2['driver_id'], $v, null); $this->fail('expected conflict'); }
        catch (ApiException $e) { $this->assertSame('vehicle_conflict', $e->errorCode); }
        $ms->assign($admin, (int)$m2['id'], $d2['driver_id'], $v, null, 'اجتماع مشترك معتمد');
        $this->assertSame('اجتماع مشترك معتمد', self::$db->val('SELECT conflict_exception_reason FROM vehicle_assignment_history WHERE mission_id=?', [$m2['id']]));
        // نفس السائق على نفس المركبة لعدة مهام: لا تعارض
        $m3 = $ms->create($admin, ['title' => 'ثالثة', 'scheduled_start' => gmdate('c', self::T0 + 3700)]);
        $ms->assign($admin, (int)$m3['id'], $d1['driver_id'], $v, null);
        $this->assertTrue(true);
    }

    public function testMultiMissionTripNotClosedByFirstCompletion(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m1 = $this->sentMission($admin, $d['driver_id'], $v); $m2 = $this->sentMission($admin, $d['driver_id'], $v, ['title' => 'ثانية']);
        $tok = $this->loginDriver($d);
        $this->sync($tok, [$this->ev('mission.accept', ['mission_id' => $m1['id'], 'client_trip_id' => 'T']), $this->ev('mission.accept', ['mission_id' => $m2['id'], 'client_trip_id' => 'T'])]);
        $this->assertSame(1, (int)self::$db->val('SELECT COUNT(*) FROM trips'));
        $this->sync($tok, [$this->ev('odometer.record', ['kind' => 'start', 'value' => 1000])]);
        $this->sync($tok, [$this->ev('mission.arrive', ['mission_id' => $m1['id']]), $this->ev('mission.complete', ['mission_id' => $m1['id']])]);
        $r = $this->api('GET', '/api/v1/trips/current', [], $tok)['json'];
        $this->assertTrue($r['trip']['tracking_required']);
        $this->assertSame(1, $r['trip']['active_missions']);
        // إغلاق الرحلة مرفوض ما دامت هناك مهمة نشطة
        $c = $this->sync($tok, [$this->ev('trip.close', ['end_odometer' => 1020])]);
        $this->assertSame('conflict', $c['results'][0]['status']);
        $this->assertSame('trip_has_active_missions', $c['results'][0]['error']['code']);
        $this->sync($tok, [$this->ev('mission.arrive', ['mission_id' => $m2['id']]), $this->ev('mission.complete', ['mission_id' => $m2['id']])]);
        $c = $this->sync($tok, [$this->ev('trip.close', ['end_odometer' => 1020])]);
        $this->assertSame('applied', $c['results'][0]['status']);
        $this->assertSame('closed', self::$db->val('SELECT status FROM trips'));
    }

    public function testPhotoRequiredMakesCompletionPendingUntilUpload(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d['driver_id'], $v, ['proof_policy' => 'photo_required']);
        $tok = $this->loginDriver($d);
        $this->sync($tok, [$this->ev('mission.accept', ['mission_id' => $m['id'], 'client_trip_id' => 'T'])]);
        $r = $this->sync($tok, [$this->ev('mission.arrive', ['mission_id' => $m['id']]), $this->ev('mission.complete', ['mission_id' => $m['id'], 'notes' => 'تم'])]);
        $this->assertSame('completion_pending', $r['results'][1]['data']['mission']['status']);
        // رفع صورة مزيفة المحتوى مرفوض
        $tmp = tempnam(sys_get_temp_dir(), 'f'); file_put_contents($tmp, 'not an image');
        $bad = $this->api('POST', '/api/v1/attachments', ['owner_type' => 'mission_proof', 'owner_ref' => (string)$m['id'], 'client_file_id' => 'f1'], $tok, [], ['file' => ['tmp_name' => $tmp, 'name' => 'x.jpg', 'error' => 0, 'size' => 12]]);
        $this->assertSame(415, $bad['status']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        file_put_contents($tmp, $png);
        $ok = $this->api('POST', '/api/v1/attachments', ['owner_type' => 'mission_proof', 'owner_ref' => (string)$m['id'], 'client_file_id' => 'f1', 'sha256' => hash('sha256', $png)], $tok, [], ['file' => ['tmp_name' => $tmp, 'name' => 'x.png', 'error' => 0, 'size' => strlen($png)]]);
        $this->assertSame(201, $ok['status'], json_encode($ok['json']));
        $this->assertSame('completed', (new MissionService($this->app))->get((int)$m['id'])['status']);
        // ملف خارج النطاق: سائق آخر لا يراه
        $d2 = $this->makeDriver('drvtwo');
        $this->assertSame(404, $this->api('GET', '/api/v1/attachments/' . $ok['json']['id'] . '/file', [], $this->loginDriver($d2))['status']);
        $this->assertSame(200, $this->api('GET', '/api/v1/attachments/' . $ok['json']['id'] . '/file', [], $tok)['status']);
        // checksum غير مطابق
        $mm = $this->api('POST', '/api/v1/attachments', ['owner_type' => 'mission_proof', 'owner_ref' => (string)$m['id'], 'client_file_id' => 'f2', 'sha256' => str_repeat('a', 64)], $tok, [], ['file' => ['tmp_name' => $tmp, 'name' => 'x.png', 'error' => 0, 'size' => 1]]);
        $this->assertSame(422, $mm['status']);
    }

    public function testInvalidTransitionsRejected(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d['driver_id'], $v);
        $this->expectException(ApiException::class);
        (new MissionService($this->app))->transition((int)$m['id'], 'completed', null);
    }

    public function testManagerScopeIsolation(): void
    {
        $admin = $this->makeAdmin();
        $mgrA = $this->makeUser('mgrA', ['movement_manager'], [['team', 'A']]);
        $ms = new MissionService($this->app);
        $mA = $ms->create($admin, ['title' => 'A', 'scheduled_start' => gmdate('c', self::T0), 'team' => 'A']);
        $mB = $ms->create($admin, ['title' => 'B', 'scheduled_start' => gmdate('c', self::T0), 'team' => 'B']);
        $this->adminSession($mgrA);
        $list = $this->api('GET', '/api/v1/admin/missions')['json']['data'];
        $this->assertSame([(int)$mA['id']], array_map('intval', array_column($list, 'id')));
        $this->assertSame(403, $this->api('GET', '/api/v1/admin/missions/' . $mB['id'])['status']);
        $this->assertSame(403, $this->api('PUT', '/api/v1/admin/missions/' . $mB['id'], ['title' => 'x'])['status']);
        // مسؤول متابعة بلا missions.create
        $fu = $this->makeUser('follow', ['follow_up']);
        $this->session->destroy(); $this->adminSession($fu);
        $this->assertSame(403, $this->api('POST', '/api/v1/admin/missions', ['title' => 'x', 'scheduled_start' => gmdate('c')])['status']);
    }

    public function testRouteVersionsAndApproval(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d['driver_id'], $v);
        $ms = new MissionService($this->app);
        $r1 = $ms->proposeRoute($admin, (int)$m['id'], 'auto', null);   // مزود الخط المستقيم
        $this->assertSame('1', (string)$r1['version']);
        $r2 = $ms->proposeRoute($admin, (int)$m['id'], 'manual', [[24.7, 46.7], [24.75, 46.7], [24.8, 46.8]]);
        $ms->approveRoute($admin, (int)$r1['id']);
        $ms->approveRoute($admin, (int)$r2['id']);
        $this->assertSame('superseded', self::$db->val('SELECT status FROM mission_routes WHERE id=?', [$r1['id']]));
        $this->assertSame(2, (int)$ms->approvedRoute((int)$m['id'])['version']);
        $this->assertNotNull(self::$db->val("SELECT 1 FROM audit_logs WHERE action='route.approve'"));
    }
}
