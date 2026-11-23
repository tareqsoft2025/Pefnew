<?php
declare(strict_types=1);

use App\Core\{ApiException, Env};
use App\Http\{Kernel, Request, Response, Session};

require dirname(__DIR__) . '/vendor/autoload.php';
Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set('UTC');
ini_set('display_errors', '0');

try {
    $app = App\App::boot();
    $req = Request::fromGlobals();
    $session = $req->isApi() && str_starts_with($req->path, '/api/v1/admin') === false ? new Session() : Session::native();
    (new Kernel($app, $session))->handle($req)->send();
} catch (ApiException $e) {
    Response::json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->status)->send();
} catch (Throwable $e) {
    error_log('[fleet-boot] ' . $e->getMessage());
    Response::json(['error' => ['code' => 'server_error', 'message' => 'الخدمة غير متاحة']], 500)->send();
}
