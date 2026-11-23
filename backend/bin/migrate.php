<?php
declare(strict_types=1);
// الاستخدام: php bin/migrate.php  — ينفذ migrations ثم يزرع الصلاحيات والأدوار (آمن للتكرار).
use App\Core\{Db, Env, Migrator, Seeder};

require dirname(__DIR__) . '/vendor/autoload.php';
Env::load(dirname(__DIR__) . '/.env');
$db = Db::fromEnv();
$ran = (new Migrator($db, dirname(__DIR__) . '/database/migrations'))->run();
echo $ran ? "تم تنفيذ: " . implode(', ', $ran) . "\n" : "لا توجد migrations جديدة\n";
Seeder::run($db, require dirname(__DIR__) . '/config/permissions.php');
echo "تم زرع الصلاحيات والأدوار\n";
