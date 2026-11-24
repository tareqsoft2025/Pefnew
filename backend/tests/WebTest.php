<?php
declare(strict_types=1);
namespace Tests;

use App\Http\Request;
use App\Services\{EmergencyService, MissionService};

final class WebTest extends TestCase
{
    private function web(string $method, string $path, array $body = [], array $query = []): array
    {
        $headers = [];
        if ($this->session->data) { $body += ['_csrf' => $this->session->csrf()]; }
        $res = $this->kernel->handle(new Request($method, $path, $query, $body, $headers, [], [], '10.0.0.1'));
        return ['status' => $res->status, 'html' => $res->body, 'res' => $res, 'loc' => $res->headers['Location'] ?? null];
    }

    public function testLoginFlowAndRedirects(): void
    {
        $this->makeAdmin();
        $this->assertSame('/login', $this->web('GET', '/')['loc']);
        $form = $this->web('GET', '/login');
        $this->assertSame(200, $form['status']);
        $this->assertStringContainsString('dir="rtl"', $form['html']);
        $bad = $this->web('POST', '/login', ['username' => 'admin1', 'password' => 'x', '_csrf' => $this->session->csrf()]);
        $this->assertStringContainsString('غير صحيحة', $bad['html']);
        $ok = $this->web('POST', '/login', ['username' => 'admin1', 'password' => 'Str0ng-Passw0rd!', '_csrf' => $this->session->csrf()]);
        $this->assertSame('/', $ok['loc']);
        $this->assertSame(200, $this->web('GET', '/')['status']);
        // POST بلا CSRF مرفوض
        $this->assertSame(419, $this->kernel->handle(new Request('POST', '/logout', [], [], []))->status);
    }

    public function testAllPagesRenderForAdmin(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $m = $this->sentMission($admin, $d['driver_id'], $v);
        $tok = $this->loginDriver($d);
        $this->sync($tok, [$this->ev('mission.accept', ['mission_id' => $m['id'], 'client_trip_id' => 'T']), $this->ev('sos.create', [], 's1')]);
        $this->api('POST', '/api/v1/gps/batch', ['client_trip_id' => 'T', 'points' => [$this->pt('a', 0, 24.7, 46.7)]], $tok);
        $this->adminSession($admin);
        $tripId = (int)self::$db->val('SELECT id FROM trips'); $inc = (int)self::$db->val('SELECT id FROM emergency_incidents');
        foreach (['/', '/missions', '/missions/new', '/missions/' . $m['id'], '/map', "/trips/$tripId", '/vehicles', '/drivers', '/stops', '/emergencies', "/emergencies/$inc", '/odometer', '/inspections', '/fuel',
                 '/reports', '/users', '/roles', '/settings', '/audit', '/account', '/reports?type=trips', '/reports?type=odometer'] as $path) {
            $parts = parse_url($path); parse_str($parts['query'] ?? '', $qs);
            $r = $this->web('GET', $parts['path'], [], $qs);
            $this->assertSame(200, $r['status'], "$path => " . substr(strip_tags($r['html']), 0, 200));
            $this->assertStringNotContainsString('Warning:', $r['html']);
            $this->assertStringNotContainsString('Undefined', $r['html']);
        }
    }

    public function testMissionCreateWizardPostWithStopsAndSend(): void
    {
        $admin = $this->makeAdmin(); $d = $this->makeDriver(); $v = $this->makeVehicle();
        $this->adminSession($admin);
        $r = $this->web('POST', '/missions', ['title' => 'مهمة من النموذج', 'scheduled_start' => '2030-01-01T10:00', 'driver_id' => $d['driver_id'], 'vehicle_id' => $v, 'origin_lat' => '24.7', 'origin_lon' => '46.7', 'dest_lat' => '24.8', 'dest_lon' => '46.8',
            'stops' => [['name' => 'ن1'], ['lat' => '24.75'], ['lon' => '46.75']], 'auto_route' => '1', 'send_now' => '1']);
        $this->assertMatchesRegularExpression('#^/missions/\d+$#', (string)$r['loc']);
        $m = self::$db->one('SELECT * FROM missions');
        $this->assertSame('sent', $m['status']);
        $this->assertSame(1, (int)self::$db->val('SELECT COUNT(*) FROM mission_stops'));
        $this->assertSame(1, (int)self::$db->val('SELECT COUNT(*) FROM mission_routes'));
    }

    public function testXssIsEscapedInTables(): void
    {
        $admin = $this->makeAdmin();
        $this->adminSession($admin);
        (new MissionService($this->app))->create($admin, ['title' => '<script>alert(1)</script>', 'scheduled_start' => gmdate('c')]);
        $html = $this->web('GET', '/missions')['html'];
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPermissionsEnforcedOnPages(): void
    {
        $fu = $this->makeUser('followup', ['follow_up']);
        $this->adminSession($fu);
        $this->assertSame(403, $this->web('GET', '/users')['status']);
        $this->assertSame(403, $this->web('GET', '/settings')['status']);
        $this->assertSame(403, $this->web('GET', '/missions/new')['status']);
        $this->assertSame(200, $this->web('GET', '/map')['status']);
        $this->assertSame(403, $this->web('GET', '/reports/export', [], ['type' => 'missions'])['status']);
        $nav = $this->web('GET', '/')['html'];
        $this->assertStringNotContainsString('href="/users"', $nav);
    }

    public function testReportExportsScopedAndCsvInjectionNeutralised(): void
    {
        $admin = $this->makeAdmin();
        $mgrA = $this->makeUser('mgra', ['movement_manager'], [['team', 'A']]);
        $ms = new MissionService($this->app);
        $ms->create($admin, ['title' => '=HYPERLINK("http://x")', 'scheduled_start' => gmdate('c'), 'team' => 'A']);
        $ms->create($admin, ['title' => 'مهمة فريق ب', 'scheduled_start' => gmdate('c'), 'team' => 'B']);
        $this->adminSession($mgrA);
        $csv = $this->web('GET', '/reports/export', [], ['type' => 'missions', 'format' => 'csv', 'from' => gmdate('Y-m-d', time() - 86400), 'to' => gmdate('Y-m-d', time() + 86400)]);
        $this->assertSame(200, $csv['status']);
        $this->assertStringContainsString("'=HYPERLINK", $csv['res']->body);
        $this->assertStringNotContainsString('فريق ب', $csv['res']->body);
        $x = $this->web('GET', '/reports/export', [], ['type' => 'missions', 'format' => 'xlsx']);
        $this->assertSame('PK', substr($x['res']->body, 0, 2));
        $this->assertSame(1, (int)self::$db->val("SELECT COUNT(*) FROM audit_logs WHERE action='report.export'") - 1);
    }

    public function testEmergencyWebActionsRespectScope(): void
    {
        $admin = $this->makeAdmin();
        $eoB = $this->makeUser('eob', ['emergency_officer'], [['team', 'B']]);
        $d = $this->makeDriver('teama', ['team' => 'A']); $tok = $this->loginDriver($d);
        $this->sync($tok, [$this->ev('sos.create', [], 's1')]);
        $id = (int)self::$db->val('SELECT id FROM emergency_incidents');
        $this->adminSession($eoB);
        $this->assertSame(403, $this->web('GET', "/emergencies/$id")['status']);
        $this->assertSame(0, count($this->api('GET', '/api/v1/admin/emergencies')['json']['data']));
        $this->session->destroy(); $this->adminSession($admin);
        $r = $this->web('POST', "/emergencies/$id/acknowledge");
        $this->assertSame("/emergencies/$id", $r['loc']);
        $this->assertSame('acknowledged', self::$db->val('SELECT status FROM emergency_incidents'));
    }

    public function testMfaEnrollmentThroughAccountPage(): void
    {
        $admin = $this->makeAdmin();
        $this->adminSession($admin);
        $this->web('POST', '/account/mfa', ['act' => 'begin']);
        $secret = self::$db->val('SELECT pending_mfa_secret FROM users');
        $this->web('POST', '/account/mfa', ['act' => 'confirm', 'code' => \App\Core\Totp::code($secret)]);
        $this->assertSame(1, (int)self::$db->val('SELECT mfa_enabled FROM users'));
        $this->session->destroy();
        $form = $this->web('GET', '/login');
        $r = $this->web('POST', '/login', ['username' => 'admin1', 'password' => 'Str0ng-Passw0rd!', '_csrf' => $this->session->csrf()]);
        $this->assertStringContainsString('رمز التحقق', $r['html']);
    }
}
