<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\{ApiException, Authz, Clock, Db, Validator};

final class UserService
{
    private Db $db;
    public function __construct(private App $app) { $this->db = $app->db; }

    /** إنشاء مستخدم بأدوار ونطاقات. $scopes = [['all'] | ['team','A'] ...] */
    public function create(array $in, ?array $actor = null): int
    {
        if ($actor) { Authz::require($actor, 'users.manage'); }
        $d = Validator::validate($in, ['username' => 'required|string|max:64', 'full_name' => 'required|string|max:160', 'password' => 'required|string|min:10|max:200',
            'email' => 'nullable|string|max:190', 'phone' => 'nullable|string|max:32', 'must_change_password' => 'bool']);
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $d['username'])) { throw new ApiException(422, 'validation_failed', 'اسم مستخدم غير صالح', ['username' => 'أحرف لاتينية وأرقام فقط']); }
        if (mb_strlen($d['password']) < 10) { throw new ApiException(422, 'weak_password', 'كلمة المرور يجب ألا تقل عن 10 أحرف'); }
        $now = Clock::nowStr();
        try {
            $id = $this->db->tx(function () use ($d, $in, $now) {
                $id = $this->db->insert('users', ['username' => $d['username'], 'email' => $d['email'] ?? null, 'phone' => $d['phone'] ?? null, 'full_name' => $d['full_name'],
                    'password_hash' => AuthService::hashPassword($d['password']), 'must_change_password' => (int)($d['must_change_password'] ?? 0), 'created_at' => $now, 'updated_at' => $now]);
                $this->setRoles($id, (array)($in['roles'] ?? []));
                $this->setScopes($id, (array)($in['scopes'] ?? []));
                return $id;
            });
        } catch (\PDOException $e) {
            if (Db::isDuplicate($e)) { throw new ApiException(409, 'duplicate', 'اسم المستخدم أو البريد مستخدم مسبقًا'); }
            throw $e;
        }
        $this->app->audit->log($actor['id'] ?? null, 'user.create', 'user', $id, ['roles' => $in['roles'] ?? []]);
        return $id;
    }

    public function setRoles(int $userId, array $roleCodes): void
    {
        $this->db->exec('DELETE FROM user_roles WHERE user_id=?', [$userId]);
        foreach (array_unique($roleCodes) as $code) {
            $rid = $this->db->val('SELECT id FROM roles WHERE code=?', [$code]);
            if (!$rid) { throw new ApiException(422, 'validation_failed', "دور غير معروف: $code"); }
            $this->db->insert('user_roles', ['user_id' => $userId, 'role_id' => $rid]);
        }
    }

    public function setScopes(int $userId, array $scopes): void
    {
        $this->db->exec('DELETE FROM access_scopes WHERE user_id=?', [$userId]);
        foreach ($scopes as $s) {
            $type = $s[0] ?? ($s['type'] ?? ''); $val = $s[1] ?? ($s['value'] ?? '*');
            if (!in_array($type, ['all', 'team', 'region', 'fleet'], true)) { throw new ApiException(422, 'validation_failed', 'نوع نطاق غير صالح'); }
            $this->db->q('INSERT IGNORE INTO access_scopes (user_id, scope_type, scope_value) VALUES (?,?,?)', [$userId, $type, $type === 'all' ? '*' : (string)$val]);
        }
    }

    public function createDriver(array $in, ?array $actor = null): int
    {
        if ($actor) { Authz::require($actor, 'drivers.manage'); }
        $in['roles'] = ['driver'];
        $uid = $this->create($in, $actor ? $actor + [] : null);
        $did = $this->db->insert('drivers', ['user_id' => $uid, 'license_no' => $in['license_no'] ?? null, 'license_expiry' => $in['license_expiry'] ?? null,
            'team' => $in['team'] ?? null, 'region' => $in['region'] ?? null, 'fleet' => $in['fleet'] ?? null, 'notes' => $in['notes'] ?? null, 'created_at' => Clock::nowStr()]);
        $this->app->audit->log($actor['id'] ?? null, 'driver.create', 'driver', $did);
        return $did;
    }

    public function setActive(array $actor, int $userId, bool $active): void
    {
        Authz::require($actor, 'users.manage');
        $this->db->update('users', ['is_active' => $active ? 1 : 0, 'updated_at' => Clock::nowStr()], 'id=?', [$userId]);
        if (!$active) { (new AuthService($this->app))->revokeAllForUser($userId); }
        $this->app->audit->log($actor['id'], $active ? 'user.activate' : 'user.deactivate', 'user', $userId);
    }
}
