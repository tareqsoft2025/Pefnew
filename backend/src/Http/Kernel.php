<?php
declare(strict_types=1);
namespace App\Http;

use App\App;
use App\Core\{ApiException, Authz, Clock, Env, RateLimiter};
use App\Services\AuthService;

/** نقطة الدخول: التوجيه، المصادقة (Bearer للسائق/جلسة للويب)، CSRF، CORS، معالجة الأخطاء الموحدة. */
final class Kernel
{
    public Router $router;
    public function __construct(public App $app, public Session $session)
    {
        $this->router = new Router();
        (new ApiController($this))->routes($this->router);
        (new WebController($this))->routes($this->router);
    }

    public function handle(Request $req): Response
    {
        try {
            if ($req->method === 'OPTIONS' && $req->isApi()) { return $this->cors($req, new Response(204)); }
            [$handler, $params, $opts] = $this->router->match($req);
            $auth = $opts['auth'] ?? 'none';
            if ($auth === 'driver') { $this->authDriver($req); }
            elseif ($auth === 'admin') { $this->authAdmin($req); }
            if (isset($opts['perm'])) { Authz::require($req->attrs['user'], $opts['perm']); }
            $res = $handler($req, $params);
            return $req->isApi() ? $this->cors($req, $res) : $res;
        } catch (ApiException $e) {
            return $this->error($req, $e);
        } catch (\Throwable $e) {
            error_log('[fleet] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return $this->error($req, new ApiException(500, 'server_error', 'حدث خطأ غير متوقع'));
        }
    }

    private function authDriver(Request $req): void
    {
        $tok = $req->bearer();
        if (!$tok) { throw new ApiException(401, 'unauthenticated', 'مطلوب تسجيل الدخول'); }
        $a = (new AuthService($this->app))->authenticateBearer($tok);
        Authz::require($a['user'], 'api.driver');
        $driver = $this->app->db->one('SELECT * FROM drivers WHERE user_id=?', [$a['user']['id']]);
        if (!$driver) { throw new ApiException(403, 'not_a_driver', 'الحساب غير مرتبط بسائق'); }
        (new RateLimiter($this->app->db))->hit('api:' . $a['token_id'], 1200, 60);
        $req->attrs += ['user' => $a['user'], 'driver' => $driver, 'device_id' => $a['device_id'], 'token' => $tok];
    }

    private function authAdmin(Request $req): void
    {
        $uid = $this->session->userId();
        if (!$uid) {
            if ($req->isApi()) { throw new ApiException(401, 'unauthenticated', 'مطلوب تسجيل الدخول'); }
            throw new ApiException(302, 'redirect_login', '/login');
        }
        $user = $this->app->authz->loadUser($uid);
        if (!$user) { $this->session->destroy(); throw new ApiException(401, 'unauthenticated', 'الجلسة غير صالحة'); }
        if (in_array($req->method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $sent = $req->body['_csrf'] ?? $req->header('x-csrf-token') ?? '';
            if (!is_string($sent) || !hash_equals($this->session->csrf(), $sent)) { throw new ApiException(419, 'csrf_mismatch', 'انتهت صلاحية النموذج، أعد تحميل الصفحة'); }
        }
        $req->attrs['user'] = $user;
    }

    private function cors(Request $req, Response $res): Response
    {
        $origin = $req->header('origin');
        $allowed = array_filter(array_map('trim', explode(',', Env::get('CORS_ORIGINS', '') ?? '')));
        if ($origin && in_array($origin, $allowed, true)) {
            $res->headers += ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin', 'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-CSRF-Token',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS', 'Access-Control-Max-Age' => '600'];
        }
        return $res;
    }

    private function error(Request $req, ApiException $e): Response
    {
        if (!$req->isApi()) {
            if ($e->errorCode === 'redirect_login') { return Response::redirect($e->details[0] ?? '/login'); }
            return Response::html(View::render('error', ['status' => $e->status, 'message' => $e->getMessage()], null), $e->status);
        }
        $body = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
        if ($e->details) { $body['error']['details'] = $e->details; }
        $res = Response::json($body, $e->status);
        if ($e->status === 429 && isset($e->details['retry_after'])) { $res->headers['Retry-After'] = (string)$e->details['retry_after']; }
        return $this->cors($req, $res);
    }
}
