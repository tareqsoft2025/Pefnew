<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{ApiException, Authz, Clock, Db, RateLimiter, Totp};

final class AuthService
{
    private Db $db;
    public const MAX_FAILS = 5;
    public const LOCK_S = 900;

    public function __construct(private App $app) { $this->db = $app->db; }

    public static function hashPassword(string $p): string
    {
        return password_hash($p, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /** يتحقق من بيانات الدخول (مع قفل الحساب وMFA) ويعيد معرف المستخدم. */
    public function verifyCredentials(string $username, string $password, ?string $mfaCode, string $ip): int
    {
        (new RateLimiter($this->db))->hit('login:ip:' . $ip, 30, 600);
        (new RateLimiter($this->db))->hit('login:user:' . strtolower($username), 10, 600);
        $u = $this->db->one('SELECT * FROM users WHERE username=?', [$username]);
        $bad = new ApiException(401, 'invalid_credentials', 'اسم المستخدم أو كلمة المرور غير صحيحة');
        if (!$u) { password_verify($password, '$2y$10$abcdefghijklmnopqrstuuF3hQZ0oVQ9Q9y9H0K0K0K0K0K0K0K0K'); throw $bad; }
        if ($u['locked_until'] && Clock::parse($u['locked_until']) > Clock::now()) {
            throw new ApiException(423, 'account_locked', 'الحساب مقفل مؤقتًا بسبب محاولات فاشلة');
        }
        if (!password_verify($password, $u['password_hash'])) {
            $fails = (int)$u['failed_logins'] + 1;
            $this->db->update('users', ['failed_logins' => $fails, 'locked_until' => $fails >= self::MAX_FAILS ? Clock::fmt(Clock::now() + self::LOCK_S) : null], 'id=?', [$u['id']]);
            $this->app->audit->log((int)$u['id'], 'auth.login_failed', 'user', $u['id'], ['ip' => $ip], $ip);
            throw $bad;
        }
        if (!(int)$u['is_active']) { throw new ApiException(403, 'account_disabled', 'الحساب معطّل'); }
        if ((int)$u['mfa_enabled']) {
            if (!$mfaCode) { throw new ApiException(401, 'mfa_required', 'رمز التحقق الثنائي مطلوب'); }
            if (!Totp::verify((string)$u['mfa_secret'], $mfaCode)) { throw new ApiException(401, 'mfa_invalid', 'رمز التحقق غير صحيح'); }
        } elseif ($this->app->settings->bool('security.require_admin_mfa')) {
            $user = $this->app->authz->loadUser((int)$u['id']);
            if ($user && $user['is_admin']) { throw new ApiException(403, 'mfa_enrollment_required', 'يجب تفعيل التحقق الثنائي لحسابات الإدارة'); }
        }
        $this->db->update('users', ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => Clock::nowStr()], 'id=?', [$u['id']]);
        if (password_needs_rehash($u['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $this->db->update('users', ['password_hash' => self::hashPassword($password)], 'id=?', [$u['id']]);
        }
        $this->app->audit->log((int)$u['id'], 'auth.login', 'user', $u['id'], [], $ip);
        return (int)$u['id'];
    }

    /** دخول تطبيق السائق: يصدر رمز وصول قصير ورمز تجديد. */
    public function apiLogin(string $username, string $password, string $deviceId, ?string $label, ?string $pushToken, string $ip, ?string $mfa = null): array
    {
        $uid = $this->verifyCredentials($username, $password, $mfa, $ip);
        $user = $this->app->authz->loadUser($uid);
        Authz::require($user, 'api.driver');
        $driver = $this->db->one('SELECT id FROM drivers WHERE user_id=?', [$uid]);
        if (!$driver) { throw new ApiException(403, 'not_a_driver', 'الحساب غير مرتبط بسائق'); }
        // جهاز واحد نشط لكل (مستخدم، جهاز)
        $this->db->exec('UPDATE api_tokens SET revoked_at=? WHERE user_id=? AND device_id=? AND revoked_at IS NULL', [Clock::nowStr(), $uid, $deviceId]);
        return $this->issue($uid, $deviceId, $label, $pushToken) + ['driver_id' => (int)$driver['id'], 'user' => ['id' => $uid, 'full_name' => $user['full_name']]];
    }

    private function issue(int $uid, string $deviceId, ?string $label, ?string $push): array
    {
        $access = bin2hex(random_bytes(32)); $refresh = bin2hex(random_bytes(32));
        $now = Clock::now();
        $this->db->insert('api_tokens', [
            'user_id' => $uid, 'device_id' => $deviceId, 'device_label' => $label,
            'access_hash' => hash('sha256', $access), 'refresh_hash' => hash('sha256', $refresh),
            'access_expires_at' => Clock::fmt($now + 3600), 'refresh_expires_at' => Clock::fmt($now + 86400 * 30),
            'push_token' => $push, 'created_at' => Clock::fmt($now),
        ]);
        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => 3600];
    }

    public function refresh(string $refreshToken): array
    {
        $row = $this->db->one('SELECT * FROM api_tokens WHERE refresh_hash=?', [hash('sha256', $refreshToken)]);
        if (!$row || $row['revoked_at'] || Clock::parse($row['refresh_expires_at']) < Clock::now()) {
            // إعادة استخدام رمز تجديد قديم = اشتباه سرقة: أبطل كل رموز الجهاز
            if ($row && $row['revoked_at']) { $this->db->exec('UPDATE api_tokens SET revoked_at=? WHERE user_id=? AND device_id=? AND revoked_at IS NULL', [Clock::nowStr(), $row['user_id'], $row['device_id']]); }
            throw new ApiException(401, 'invalid_refresh', 'رمز التجديد غير صالح');
        }
        $user = $this->app->authz->loadUser((int)$row['user_id']);
        if (!$user) { throw new ApiException(401, 'invalid_refresh', 'الحساب غير نشط'); }
        $this->db->update('api_tokens', ['revoked_at' => Clock::nowStr()], 'id=?', [$row['id']]);
        return $this->issue((int)$row['user_id'], $row['device_id'], $row['device_label'], $row['push_token']);
    }

    public function revokeToken(string $accessToken): void
    {
        $this->db->exec('UPDATE api_tokens SET revoked_at=? WHERE access_hash=? AND revoked_at IS NULL', [Clock::nowStr(), hash('sha256', $accessToken)]);
    }

    public function revokeAllForUser(int $uid): void
    {
        $this->db->exec('UPDATE api_tokens SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL', [Clock::nowStr(), $uid]);
    }

    /** @return array{user:array,token_id:int} */
    public function authenticateBearer(string $token): array
    {
        $row = $this->db->one('SELECT * FROM api_tokens WHERE access_hash=?', [hash('sha256', $token)]);
        if (!$row || $row['revoked_at'] || Clock::parse($row['access_expires_at']) < Clock::now()) {
            throw new ApiException(401, 'invalid_token', 'الرمز غير صالح أو منتهي');
        }
        $user = $this->app->authz->loadUser((int)$row['user_id']);
        if (!$user) { throw new ApiException(401, 'invalid_token', 'الحساب غير نشط'); }
        $this->db->update('api_tokens', ['last_used_at' => Clock::nowStr()], 'id=?', [$row['id']]);
        return ['user' => $user, 'token_id' => (int)$row['id'], 'device_id' => $row['device_id']];
    }

    public function changePassword(int $uid, string $new): void
    {
        if (mb_strlen($new) < 10) { throw new ApiException(422, 'weak_password', 'كلمة المرور يجب ألا تقل عن 10 أحرف'); }
        $this->db->update('users', ['password_hash' => self::hashPassword($new), 'must_change_password' => 0, 'updated_at' => Clock::nowStr()], 'id=?', [$uid]);
        $this->revokeAllForUser($uid);
        $this->app->audit->log($uid, 'auth.password_changed', 'user', $uid);
    }
}
