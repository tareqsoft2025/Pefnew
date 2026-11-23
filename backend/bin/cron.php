<?php
declare(strict_types=1);
// cPanel Cron (كل دقيقة): * * * * * /usr/bin/php /home/USER/fleet/backend/bin/cron.php >/dev/null 2>&1
use App\Core\{Db, Env};
use App\Services\CronService;

require dirname(__DIR__) . '/vendor/autoload.php';
Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('UTC');
$lock = fopen(sys_get_temp_dir() . '/fleet-cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }   // لا تشغيل متداخل
$res = (new CronService(new App\App(Db::fromEnv())))->run();
echo json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
