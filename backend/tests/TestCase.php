<?php
declare(strict_types=1);
namespace Tests;

use App\App;
use App\Core\{Clock, Db, Migrator, Seeder};
use App\Http\{Kernel, Request, Session};
use App\Providers\{FakeSmsProvider, Http, NullPushProvider};
use App\Services\UserService;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected static ?Db $db = null;
    protected App $app;
    protected Kernel $kernel;
    protected Session $session;
    protected FakeSmsProvider $sms;
    protected const T0 = 1800000000;   // نقطة زمنية ثابتة

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=fleet_test;charset=utf8mb4';
        self::$db = new Db($dsn, getenv('TEST_DB_USER') ?: 'fleet', getenv('TEST_DB_PASS') ?: 'fleet');
        $db = self::$db;
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->all('SHOW TABLES') as $r) { $db->exec('DROP TABLE `' . array_values($r)[0] . '`'); }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        (new Migrator($db, dirname(__DIR__) . '/database/migrations'))->run();
    }

    protected function setUp(): void
    {
        $db = self::$db;
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->all('SHOW TABLES') as $r) { $t = array_values($r)[0]; if ($t !== 'schema_migrations') { $db->exec("TRUNCATE TABLE `$t`"); } }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        Clock::fix(self::T0);
        $this->app = new App($db);
        Seeder::run($db, $this->app->config);
        $this->sms = new FakeSmsProvider();
        $this->app->sms = $this->sms;
        $this->app->push = new NullPushProvider();
        $this->session = new Session();
        $this->kernel = new Kernel($this->app, $this->session);
        Http::$transport = null;
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    }

    protected function tearDown(): void { Clock::fix(null); }

    protected function users(): UserService { return new UserService($this->app); }

    protected function makeAdmin(string $name = 'admin1'): array
    {
        $id = $this->users()->create(['username' => $name, 'full_name' => 'مدير ' . $name, 'password' => 'Str0ng-Passw0rd!', 'roles' => ['system_admin'], 'scopes' => [['all']]]);
        return $this->app->authz->loadUser($id);
    }

    protected function makeUser(string $name, array $roles, array $scopes = [['all']]): array
    {
        $id = $this->users()->create(['username' => $name, 'full_name' => $name, 'password' => 'Str0ng-Passw0rd!', 'roles' => $roles, 'scopes' => $scopes]);
        return $this->app->authz->loadUser($id);
    }

    /** @return array{driver_id:int,user_id:int,username:string} */
    protected function makeDriver(string $name = 'drv1', array $extra = []): array
    {
        $did = $this->users()->createDriver(['username' => $name, 'full_name' => 'سائق ' . $name, 'password' => 'Str0ng-Passw0rd!', 'phone' => '+9665' . random_int(10000000, 99999999)] + $extra);
        return ['driver_id' => $did, 'user_id' => (int)self::$db->val('SELECT user_id FROM drivers WHERE id=?', [$did]), 'username' => $name];
    }

    protected function makeVehicle(string $no = 'V1', array $extra = []): int
    {
        return self::$db->insert('vehicles', ['internal_no' => $no, 'plate_no' => 'PLT-' . $no, 'vehicle_type' => 'سيارة', 'created_at' => Clock::nowStr()] + $extra);
    }

    protected function api(string $method, string $path, array $body = [], ?string $token = null, array $query = [], array $files = []): array
    {
        $headers = ['content-type' => 'application/json'];
        if ($token) { $headers['authorization'] = "Bearer $token"; }
        if ($this->session->data) { $headers['x-csrf-token'] = $this->session->csrf(); }
        $req = new Request($method, $path, $query, $body, $headers, $files, [], '10.0.0.1');
        $res = $this->kernel->handle($req);
        $json = json_decode($res->body, true);
        return ['status' => $res->status, 'json' => $json, 'res' => $res];
    }

    protected function loginDriver(array $d, string $device = 'dev-1'): string
    {
        $r = $this->api('POST', '/api/v1/auth/login', ['username' => $d['username'], 'password' => 'Str0ng-Passw0rd!', 'device_id' => $device]);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        return $r['json']['access_token'];
    }

    protected function adminSession(array $user): void { $this->session->login((int)$user['id']); }

    /** مهمة جاهزة مرسلة لسائق. */
    protected function sentMission(array $admin, int $driverId, int $vehicleId, array $extra = []): array
    {
        $ms = new \App\Services\MissionService($this->app);
        $m = $ms->create($admin, $extra + ['title' => 'نقل وفد', 'scheduled_start' => gmdate('c', self::T0 + 3600), 'origin_lat' => 24.7, 'origin_lon' => 46.7, 'dest_lat' => 24.8, 'dest_lon' => 46.8, 'origin_name' => 'أ', 'dest_name' => 'ب']);
        $ms->assign($admin, (int)$m['id'], $driverId, $vehicleId, null);
        return $ms->send($admin, (int)$m['id']);
    }

    protected function ev(string $type, array $payload, ?string $id = null, ?int $ts = null): array
    {
        return ['event_id' => $id ?? bin2hex(random_bytes(8)), 'type' => $type, 'client_ts' => gmdate('c', $ts ?? Clock::now()), 'payload' => $payload];
    }

    protected function sync(string $token, array $events): array
    {
        $r = $this->api('POST', '/api/v1/sync/events', ['events' => $events], $token);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        return $r['json'];
    }

    protected function pt(string $id, int $dt, float $lat, float $lon, float $acc = 10, float $speed = 0): array
    {
        return ['id' => $id, 'ts' => gmdate('c', self::T0 + $dt), 'lat' => $lat, 'lon' => $lon, 'accuracy' => $acc, 'speed' => $speed];
    }
}
