<?php
declare(strict_types=1);
namespace App\Core;

/** RBAC + نطاقات الوصول. كل فحص يتم على الخادم. */
final class Authz
{
    public function __construct(private Db $db) {}

    /** يبني كائن مستخدم مصرّح: id, username, full_name, perms[], scopes[], roles[] */
    public function loadUser(int $userId): ?array
    {
        $u = $this->db->one('SELECT id, username, full_name, email, phone, is_active, mfa_enabled FROM users WHERE id=?', [$userId]);
        if (!$u || !(int)$u['is_active']) { return null; }
        $u['roles'] = array_column($this->db->all('SELECT r.code FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=?', [$userId]), 'code');
        $perms = array_column($this->db->all(
            'SELECT DISTINCT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id JOIN user_roles ur ON ur.role_id=rp.role_id WHERE ur.user_id=?', [$userId]), 'code');
        $u['perms'] = array_fill_keys($perms, true);
        $u['is_admin'] = in_array('system_admin', $u['roles'], true);
        $u['scopes'] = $this->db->all('SELECT scope_type, scope_value FROM access_scopes WHERE user_id=?', [$userId]);
        return $u;
    }

    public static function can(array $user, string $perm): bool
    {
        return $user['is_admin'] || isset($user['perms'][$perm]);
    }

    public static function require(array $user, string $perm): void
    {
        if (!self::can($user, $perm)) { throw new ApiException(403, 'forbidden', 'لا تملك الصلاحية المطلوبة', ['permission' => $perm]); }
    }

    /** @return array{0:string,1:array} شرط SQL لتقييد كيان له أعمدة team/region/fleet */
    public static function scopeSql(array $user, string $alias): array
    {
        if ($user['is_admin']) { return ['1=1', []]; }
        $by = [];
        foreach ($user['scopes'] as $s) {
            if ($s['scope_type'] === 'all') { return ['1=1', []]; }
            $by[$s['scope_type']][] = $s['scope_value'];
        }
        if (!$by) { return ['0=1', []]; }
        $parts = []; $params = [];
        foreach ($by as $type => $vals) {
            $parts[] = "$alias.$type IN (" . implode(',', array_fill(0, count($vals), '?')) . ')';
            array_push($params, ...$vals);
        }
        return ['(' . implode(' AND ', $parts) . ')', $params];
    }

    public static function inScope(array $user, array $entity): bool
    {
        if ($user['is_admin']) { return true; }
        $by = [];
        foreach ($user['scopes'] as $s) {
            if ($s['scope_type'] === 'all') { return true; }
            $by[$s['scope_type']][] = $s['scope_value'];
        }
        if (!$by) { return false; }
        foreach ($by as $type => $vals) {
            if (!in_array($entity[$type] ?? null, $vals, true)) { return false; }
        }
        return true;
    }

    public static function requireScope(array $user, array $entity): void
    {
        if (!self::inScope($user, $entity)) { throw new ApiException(403, 'out_of_scope', 'الكيان خارج نطاق وصولك'); }
    }
}
