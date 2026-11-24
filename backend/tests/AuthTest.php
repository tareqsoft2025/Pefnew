<?php
declare(strict_types=1);
namespace Tests;

use App\Core\{Authz, ApiException, Audit, Clock, Totp};

final class AuthTest extends TestCase
{
    public function testDriverLoginRefreshAndLogout(): void
    {
        $d = $this->makeDriver();
        $r = $this->api('POST', '/api/v1/auth/login', ['username' => 'drv1', 'password' => 'Str0ng-Passw0rd!', 'device_id' => 'dev-1']);
        $this->assertSame(200, $r['status']);
        $tok = $r['json']['access_token'];
        $this->assertSame(200, $this->api('GET', '/api/v1/me', [], $tok)['status']);
        $ref = $this->api('POST', '/api/v1/auth/refresh', ['refresh_token' => $r['json']['refresh_token']]);
        $this->assertSame(200, $ref['status']);
        // رمز التجديد القديم لا يعمل ويُبطل الجهاز كله (اشتباه إعادة استخدام)
        $this->assertSame(401, $this->api('POST', '/api/v1/auth/refresh', ['refresh_token' => $r['json']['refresh_token']])['status']);
        $this->assertSame(401, $this->api('GET', '/api/v1/me', [], $ref['json']['access_token'])['status']);
    }

    public function testWrongPasswordLocksAccount(): void
    {
        $this->makeDriver();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $this->api('POST', '/api/v1/auth/login', ['username' => 'drv1', 'password' => 'bad', 'device_id' => 'd'])['status']);
        }
        $r = $this->api('POST', '/api/v1/auth/login', ['username' => 'drv1', 'password' => 'Str0ng-Passw0rd!', 'device_id' => 'd']);
        $this->assertSame(423, $r['status']);
        Clock::advance(1000);
        $this->assertSame(200, $this->api('POST', '/api/v1/auth/login', ['username' => 'drv1', 'password' => 'Str0ng-Passw0rd!', 'device_id' => 'd'])['status']);
    }

    public function testAdminCannotUseDriverApi(): void
    {
        $this->makeAdmin();
        $r = $this->api('POST', '/api/v1/auth/login', ['username' => 'admin1', 'password' => 'Str0ng-Passw0rd!', 'device_id' => 'd']);
        $this->assertSame(403, $r['status']);
    }

    public function testMfaRequiredWhenEnabled(): void
    {
        $d = $this->makeDriver();
        $secret = Totp::generateSecret();
        self::$db->update('users', ['mfa_enabled' => 1, 'mfa_secret' => $secret], 'id=?', [$d['user_id']]);
        $r = $this->api('POST', '/api/v1/auth/login', ['username' => 'drv1', 'password' => 'Str0ng-Passw0rd!', 'device_id' => 'd']);
        $this->assertSame('mfa_required', $r['json']['error']['code']);
        $ok = $this->api('POST', '/api/v1/auth/login', ['username' => 'drv1', 'password' => 'Str0ng-Passw0rd!', 'device_id' => 'd', 'mfa_code' => Totp::code($secret)]);
        $this->assertSame(200, $ok['status']);
    }

    public function testTotpKnownVector(): void
    {
        // RFC 6238 (SHA1) سر "12345678901234567890" عند الزمن 59 → 94287082 (8 أرقام) ⇒ آخر 6 = 287082
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::code($secret, 59));
    }

    public function testAuditChainDetectsTampering(): void
    {
        $this->app->audit->log(1, 'a'); $this->app->audit->log(1, 'b', 'x', 5, ['k' => 'v']); $this->app->audit->log(2, 'c');
        $this->assertNull($this->app->audit->verify());
        self::$db->exec("UPDATE audit_logs SET action='zzz' WHERE action='b'");
        $this->assertNotNull($this->app->audit->verify());
    }

    public function testRbacAndScopes(): void
    {
        $mgr = $this->makeUser('mgr', ['movement_manager'], [['team', 'A']]);
        $this->assertTrue(Authz::can($mgr, 'missions.create'));
        $this->assertFalse(Authz::can($mgr, 'users.manage'));
        $this->assertTrue(Authz::inScope($mgr, ['team' => 'A', 'region' => null, 'fleet' => null]));
        $this->assertFalse(Authz::inScope($mgr, ['team' => 'B']));
        $none = $this->makeUser('nobody', ['follow_up'], []);
        $this->assertFalse(Authz::inScope($none, ['team' => 'A']));
        // دور متعدد
        $multi = $this->makeUser('multi', ['follow_up', 'emergency_officer'], [['all']]);
        $this->assertTrue(Authz::can($multi, 'emergencies.close') && Authz::can($multi, 'stops.review'));
    }

    public function testAdminApiRequiresSessionAndCsrf(): void
    {
        $this->assertSame(401, $this->api('GET', '/api/v1/admin/live')['status']);
        $admin = $this->makeAdmin();
        $this->adminSession($admin);
        $this->assertSame(200, $this->api('GET', '/api/v1/admin/live')['status']);
        $req = new \App\Http\Request('POST', '/api/v1/admin/missions', [], ['title' => 'x'], ['content-type' => 'application/json']);
        $this->assertSame(419, $this->kernel->handle($req)->status);   // بلا رمز CSRF
    }

    public function testRateLimiter(): void
    {
        $this->makeDriver();
        $last = 0;
        for ($i = 0; $i < 12; $i++) { $last = $this->api('POST', '/api/v1/auth/login', ['username' => 'drv1', 'password' => 'bad', 'device_id' => 'd'])['status']; }
        $this->assertContains($last, [423, 429]);
    }
}
