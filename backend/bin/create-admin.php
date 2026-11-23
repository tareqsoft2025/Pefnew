<?php
declare(strict_types=1);
// الاستخدام: ADMIN_PASSWORD='...' php bin/create-admin.php admin "الاسم الكامل" [email]
use App\{App};
use App\Core\{Db, Env};
use App\Services\UserService;

require dirname(__DIR__) . '/vendor/autoload.php';
Env::load(dirname(__DIR__) . '/.env');
$pass = getenv('ADMIN_PASSWORD');
if (!$pass || $argc < 3) { fwrite(STDERR, "ADMIN_PASSWORD=... php bin/create-admin.php <username> <full_name> [email]\n"); exit(1); }
$app = new App(Db::fromEnv());
$id = (new UserService($app))->create(['username' => $argv[1], 'full_name' => $argv[2], 'email' => $argv[3] ?? null, 'password' => $pass, 'roles' => ['system_admin'], 'scopes' => [['all']]]);
echo "تم إنشاء مدير النظام برقم $id\n";
