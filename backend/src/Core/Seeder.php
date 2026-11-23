<?php
declare(strict_types=1);
namespace App\Core;

/** زرع الصلاحيات والأدوار والقوائم المرجعية. آمن للتكرار ولا يمس بيانات المستخدمين. */
final class Seeder
{
    public static function run(Db $db, array $config): void
    {
        foreach ($config['permissions'] as $code => $desc) {
            $db->q('INSERT INTO permissions (code, description_ar) VALUES (?,?) ON DUPLICATE KEY UPDATE description_ar=VALUES(description_ar)', [$code, $desc]);
        }
        foreach ($config['roles'] as $code => [$name, $perms]) {
            $db->q('INSERT INTO roles (code, name_ar, is_system) VALUES (?,?,1) ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar)', [$code, $name]);
            $rid = (int)$db->val('SELECT id FROM roles WHERE code=?', [$code]);
            $list = $perms === ['*'] ? array_keys($config['permissions']) : $perms;
            foreach ($list as $p) {
                $pid = $db->val('SELECT id FROM permissions WHERE code=?', [$p]);
                if ($pid) { $db->q('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?,?)', [$rid, $pid]); }
            }
        }
        foreach ($config['stop_reasons'] as $c => $n) { $db->q('INSERT INTO stop_reasons (code, name_ar) VALUES (?,?) ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar)', [$c, $n]); }
        foreach ($config['emergency_types'] as $c => $n) { $db->q('INSERT INTO emergency_types (code, name_ar) VALUES (?,?) ON DUPLICATE KEY UPDATE name_ar=VALUES(name_ar)', [$c, $n]); }
    }
}
