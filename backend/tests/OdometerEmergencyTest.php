<?php
declare(strict_types=1);
namespace Tests;

use App\Core\{ApiException, Authz, Clock};
use App\Services\{CronService, EmergencyService, MissionService, OdometerService, SmsService};

final class OdometerEmergencyTest extends TestCase
{
    private function trip(array $vehExtra = []): array
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle('V1', $vehExtra);
        $m = $this->sentMission($admin, $d['driver_id'], $v);
        $tok = $this->loginDriver($d);
        $this->sync($tok, [$this->ev('mission.accept', ['mission_id' => $m['id'], 'client_trip_id' => 'T'])]);
        return [$admin, $d, $v, $m, $tok];
    }

    public function testStartAndEndReadingsPerSegmentWithSeparateTimestamps(): void
    {
        [, , $v, , $tok] = $this->trip();
        $r = $this->sync($tok, [$this->ev('odometer.record', ['kind' => 'start', 'value' => 5000, 'measured_at' => gmdate('c', self::T0 - 600)])]);
        $this->assertSame('applied', $r['results'][0]['status']);
        $row = self::$db->one('SELECT * FROM vehicle_odometer_readings');
        $this->assertSame(gmdate('Y-m-d H:i:s', self::T0 - 600), $row['measured_at']);   // وقت القياس
        $this->assertSame(gmdate('Y-m-d H:i:s', self::T0), $row['entered_at']);          // وقت الإدخال
        $this->assertNotNull($row['synced_at']);
        $this->assertSame(0, (int)self::$db->val('SELECT odometer_pending FROM vehicle_segments'));
    }

    public function testDecreasingReadingRejectedUnlessAdminOverride(): void
    {
        [$admin, , $v, , $tok] = $this->trip();
        self::$db->update('vehicles', ['last_odometer' => 8000], 'id=?', [$v]);
        $r = $this->sync($tok, [$this->ev('odometer.record', ['kind' => 'start', 'value' => 7000])]);
        $this->assertSame('conflict', $r['results'][0]['status']);
        $this->assertSame('odometer_decrease', $r['results'][0]['error']['code']);
        $seg = (int)self::$db->val('SELECT id FROM vehicle_segments');
        $mgr = $this->makeUser('mgrx', ['follow_up']);
        try { (new OdometerService($this->app))->adminRecord($mgr, $seg, 'start', 7000, gmdate('c'), 'x'); $this->fail(); } catch (ApiException $e) { $this->assertSame(403, $e->status); }
        (new OdometerService($this->app))->adminRecord($admin, $seg, 'start', 7000, gmdate('c'), 'خطأ سابق في العداد');
        $this->assertSame('خطأ سابق في العداد', self::$db->val('SELECT decrease_exception_reason FROM vehicle_odometer_readings'));
        $this->assertNotNull(self::$db->val("SELECT 1 FROM audit_logs WHERE action='odometer.decrease_exception'"));
    }

    public function testPhotoPolicyStrictestWinsAndStatusVisible(): void
    {
        [, , , , $tok] = $this->trip(['require_odometer_photo' => 1]);
        $r = $this->sync($tok, [$this->ev('odometer.record', ['kind' => 'start', 'value' => 100], 'o1')]);
        $this->assertSame('pending', $r['results'][0]['data']['photo_status']);   // محفوظة محليًا ≠ وصلت
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $tmp = tempnam(sys_get_temp_dir(), 'o'); file_put_contents($tmp, $png);
        $u = $this->api('POST', '/api/v1/attachments', ['owner_type' => 'odometer', 'owner_ref' => 'o1', 'client_file_id' => 'ph1', 'sha256' => hash('sha256', $png)], $tok, [], ['file' => ['tmp_name' => $tmp, 'name' => 'o.png', 'error' => 0, 'size' => strlen($png)]]);
        $this->assertSame(201, $u['status']);
        $this->assertSame('received', self::$db->val('SELECT photo_status FROM vehicle_odometer_readings'));
    }

    public function testNoStartReadingKeepsGpsAndNotifiesAfterTimeout(): void
    {
        [$admin, , , , $tok] = $this->trip();
        $g = $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => [$this->pt('a', 0, 24.7, 46.7)]], $tok);
        $this->assertSame(1, $g['json']['accepted']);
        Clock::advance(700);
        $out = (new CronService($this->app))->run();
        $this->assertSame(1, $out['odometer_pending_alerts']);
        $this->assertGreaterThan(0, (int)self::$db->val("SELECT COUNT(*) FROM notifications WHERE type='odometer_pending' AND user_id=?", [$admin['id']]));
        $this->assertSame(0, (new CronService($this->app))->run()['odometer_pending_alerts']);   // مرة واحدة
    }

    public function testVehicleSwitchCreatesTwoSegmentsWithTwoReadings(): void
    {
        [$admin, $d, $v1, $m, $tok] = $this->trip();
        $v2 = $this->makeVehicle('V2');
        $this->sync($tok, [$this->ev('odometer.record', ['kind' => 'start', 'value' => 1000])]);
        // بدون اعتماد المدير ⇒ مرفوض
        $r = $this->sync($tok, [$this->ev('vehicle.switch', ['new_vehicle_id' => $v2, 'end_odometer' => 1050, 'start_odometer' => 200])]);
        $this->assertSame('rejected', $r['results'][0]['status']);
        $this->assertSame('switch_not_approved', $r['results'][0]['error']['code']);
        (new MissionService($this->app))->assign($admin, (int)$m['id'], null, $v2, 'عطل المركبة الأولى');
        $r = $this->sync($tok, [$this->ev('vehicle.switch', ['new_vehicle_id' => $v2, 'end_odometer' => 1050, 'start_odometer' => 200, 'reason' => 'عطل'])]);
        $this->assertSame('applied', $r['results'][0]['status'], json_encode($r));
        $segs = self::$db->all('SELECT * FROM vehicle_segments ORDER BY seq');
        $this->assertCount(2, $segs);
        $this->assertSame(['closed', 'open'], [$segs[0]['status'], $segs[1]['status']]);
        $this->assertSame(['1000.0', '1050.0'], [$segs[0]['start_odometer'], $segs[0]['end_odometer']]);
        $this->assertSame('200.0', $segs[1]['start_odometer']);
        $this->assertNotNull(self::$db->val('SELECT driver_confirmed_at FROM vehicle_assignment_history WHERE vehicle_id=?', [$v2]));
        $this->assertSame(3, (int)self::$db->val('SELECT COUNT(*) FROM vehicle_odometer_readings'));
        $this->assertNotNull($segs[1]['approved_by']);
    }

    public function testSegmentGpsVsOdometerReviewFlagIsNotAViolation(): void
    {
        [, , , , $tok] = $this->trip();
        $this->sync($tok, [$this->ev('odometer.record', ['kind' => 'start', 'value' => 1000])]);
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => [$this->pt('a', 0, 24.700, 46.7), $this->pt('b', 60, 24.709, 46.7), $this->pt('c', 120, 24.718, 46.7)]], $tok);
        $this->sync($tok, [$this->ev('mission.arrive', ['mission_id' => self::$db->val('SELECT id FROM missions')]), $this->ev('mission.complete', ['mission_id' => self::$db->val('SELECT id FROM missions')])]);
        $this->sync($tok, [$this->ev('trip.close', ['end_odometer' => 1010])]);   // 10كم عداد مقابل 2كم GPS
        $seg = self::$db->one('SELECT * FROM vehicle_segments');
        $this->assertSame(1, (int)$seg['review_flag']);
        $this->assertSame(10000, (int)$seg['odometer_distance_m']);
        $this->assertSame('1010.0', self::$db->val('SELECT last_odometer FROM vehicles'));
    }

    public function testTripCloseRequiresEndReading(): void
    {
        [, , , $m, $tok] = $this->trip();
        $this->sync($tok, [$this->ev('mission.arrive', ['mission_id' => $m['id']]), $this->ev('mission.complete', ['mission_id' => $m['id']])]);
        $r = $this->sync($tok, [$this->ev('trip.close', [])]);
        $this->assertSame('rejected', $r['results'][0]['status']);
        $this->assertSame('end_odometer_required', $r['results'][0]['error']['code']);
    }

    // ---------------- SOS ----------------

    public function testSosMinimalPayloadIdempotentAndLastKnownLocation(): void
    {
        [, $d, , , $tok] = $this->trip();
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => [$this->pt('a', 0, 24.7, 46.7)]], $tok);
        $e = $this->ev('sos.create', [], 'sos-1');   // بلا نوع ولا وصف ولا موقع
        $r = $this->sync($tok, [$e, $e]);
        $this->assertSame(['applied', 'duplicate'], [$r['results'][0]['status'], $r['results'][1]['status']]);
        $inc = self::$db->one('SELECT * FROM emergency_incidents');
        $this->assertSame('new', $inc['status']);
        $this->assertSame(1, (int)$inc['location_is_last_known']);
        $this->assertNotNull($inc['lat']);
        // التتبع لم يتوقف
        $this->assertSame('active', self::$db->val('SELECT status FROM trips'));
    }

    public function testOfflineSosNotVisibleBeforeArrivalAndKeepsOriginalTime(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver();
        $this->adminSession($admin);
        $this->assertCount(0, $this->api('GET', '/api/v1/admin/emergencies')['json']['data']);
        $tok = $this->loginDriver($d);
        Clock::advance(1800);
        $this->sync($tok, [$this->ev('sos.create', ['type' => 'accident', 'lat' => 24.7, 'lon' => 46.7, 'created_at' => gmdate('c', self::T0 + 60)], 'sos-x', self::T0 + 60)]);
        $inc = self::$db->one('SELECT * FROM emergency_incidents');
        $this->assertSame(gmdate('Y-m-d H:i:s', self::T0 + 60), $inc['created_at_client']);
        $this->assertSame(gmdate('Y-m-d H:i:s', self::T0 + 1800), $inc['received_at']);   // وصل فعليًا الآن
        $this->assertCount(1, $this->api('GET', '/api/v1/admin/emergencies')['json']['data']);
    }

    public function testEscalationOnlyAfterReceiptAndOnceAcknowledgedStops(): void
    {
        $admin = $this->makeAdmin(); $alt = $this->makeUser('altoff', ['emergency_officer']);
        $d = $this->makeDriver();
        $this->app->settings->set('emergency.alternate_user_id', (string)$alt['id']);
        $tok = $this->loginDriver($d);
        $this->sync($tok, [$this->ev('sos.create', ['type' => 'medical'], 'sos-1')]);
        $es = new EmergencyService($this->app);
        $this->assertSame(0, $es->escalate());
        Clock::advance(301);
        $this->assertSame(1, $es->escalate());
        $this->assertSame(0, $es->escalate());   // نفس المستوى لا يتكرر
        $this->assertGreaterThan(0, (int)self::$db->val("SELECT COUNT(*) FROM notifications WHERE type='sos_escalated' AND user_id=?", [$alt['id']]));
        Clock::advance(301);
        $this->assertSame(1, $es->escalate());
        $this->assertSame(2, (int)self::$db->val('SELECT COUNT(*) FROM emergency_escalations'));
        $inc = (int)self::$db->val('SELECT id FROM emergency_incidents');
        $es->acknowledge($admin, $inc);
        Clock::advance(2000);
        $this->assertSame(0, $es->escalate());
    }

    public function testEmergencyLifecycleAndMandatoryCloseReason(): void
    {
        $admin = $this->makeAdmin(); $eo = $this->makeUser('emoff', ['emergency_officer']); $fu = $this->makeUser('follower', ['follow_up']);
        $d = $this->makeDriver(); $tok = $this->loginDriver($d);
        $this->sync($tok, [$this->ev('sos.create', [], 'sos-1')]);
        $id = (int)self::$db->val('SELECT id FROM emergency_incidents');
        $es = new EmergencyService($this->app);
        try { $es->acknowledge($fu, $id); $this->fail(); } catch (ApiException $e) { $this->assertSame(403, $e->status); }
        $es->acknowledge($eo, $id);
        $es->assign($eo, $id, (int)$fu['id']);
        $es->addAction($eo, $id, 'call', 'اتصال بالسائق');
        $es->setStatus($eo, $id, 'in_progress');
        try { $es->close($eo, $id, '  '); $this->fail(); } catch (ApiException $e) { $this->assertSame('reason_required', $e->errorCode); }
        $es->close($eo, $id, 'تمت المعالجة ميدانيًا');
        $this->assertSame('closed', self::$db->val('SELECT status FROM emergency_incidents'));
        try { $es->setStatus($eo, $id, 'in_progress'); $this->fail(); } catch (ApiException $e) { $this->assertSame('invalid_transition', $e->errorCode); }
        // السائق يرى التحديثات فقط لبلاغه
        $v = $this->api('GET', "/api/v1/emergencies/$id", [], $tok)['json'];
        $this->assertSame('closed', $v['status']);
        $this->assertNotEmpty($v['updates']);
        $this->assertSame(404, $this->api('GET', "/api/v1/emergencies/$id", [], $this->loginDriver($this->makeDriver('other1')))['status']);
        $this->assertGreaterThanOrEqual(5, (int)self::$db->val("SELECT COUNT(*) FROM audit_logs WHERE entity_type='emergency'"));
    }

    // ---------------- SMS ----------------

    public function testSmsSentAfterTimeoutOnlyForUnreceivedAndStatesStaySeparate(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d['driver_id'], $v);
        $sms = new SmsService($this->app);
        $this->assertSame(0, $sms->dispatchPending());   // لم تنته المهلة
        Clock::advance(200);
        $this->assertSame(1, $sms->dispatchPending());
        $this->assertSame(0, $sms->dispatchPending());   // لا تكرار
        $row = self::$db->one('SELECT * FROM sms_messages');
        $this->assertNotNull($row['provider_accepted_at']);
        $this->assertNull($row['delivered_at']);       // القبول ≠ التسليم
        $this->assertNull($row['app_received_at']);    // ولا الاستلام الفعلي
        $this->assertSame('sent', (new MissionService($this->app))->get((int)$m['id'])['status']);   // SMS ليست قبولًا
        $sms->deliveryReport($row['provider_message_id'], true);
        $this->assertNotNull(self::$db->val('SELECT delivered_at FROM sms_messages'));
        $this->sync($this->loginDriver($d), [$this->ev('mission.delivered', ['mission_id' => $m['id']])]);
        $this->assertNotNull(self::$db->val('SELECT app_received_at FROM sms_messages'));
    }

    public function testNoSmsWhenAppAlreadyReceived(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d['driver_id'], $v);
        $this->sync($this->loginDriver($d), [$this->ev('mission.delivered', ['mission_id' => $m['id']])]);
        Clock::advance(600);
        $this->assertSame(0, (new SmsService($this->app))->dispatchPending());
    }

    public function testNullSmsProviderRecordsFailureHonestly(): void
    {
        $this->app->sms = new \App\Providers\NullSmsProvider();
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $this->sentMission($admin, $d['driver_id'], $v);
        Clock::advance(200);
        (new SmsService($this->app))->dispatchPending();
        $row = self::$db->one('SELECT * FROM sms_messages');
        $this->assertNull($row['provider_accepted_at']);
        $this->assertSame('sms_provider_not_configured', $row['error']);
    }
}
