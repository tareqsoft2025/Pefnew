<?php
declare(strict_types=1);
namespace App\Services;

use App\App;
use App\Core\Clock;

final class NotificationService
{
    public function __construct(private App $app) {}

    /** إشعار داخل النظام + دفع Push إن وُجد مزود ورمز جهاز. الدفع لا يُعتمد عليه للتسليم. */
    public function notify(int $userId, string $type, string $title, ?string $body = null, array $data = []): int
    {
        $db = $this->app->db;
        $id = $db->insert('notifications', ['user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body,
            'data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, 'created_at' => Clock::nowStr()]);
        $status = 'none';
        $tokens = $db->all('SELECT push_token FROM api_tokens WHERE user_id=? AND revoked_at IS NULL AND push_token IS NOT NULL', [$userId]);
        foreach ($tokens as $t) {
            $status = $this->app->push->send($t['push_token'], $title, (string)$body, ['type' => $type, 'notification_id' => $id] + $data) ? 'sent' : ($status === 'sent' ? 'sent' : 'failed');
        }
        $db->update('notifications', ['push_status' => $status], 'id=?', [$id]);
        return $id;
    }

    /** إشعار كل مستخدم يملك صلاحية معينة (ضمن نطاقه إن وُجد كيان). */
    public function notifyPermission(string $perm, string $type, string $title, ?string $body = null, array $data = [], ?array $entity = null): void
    {
        $users = $this->app->db->all("SELECT DISTINCT ur.user_id FROM user_roles ur JOIN roles r ON r.id=ur.role_id
            LEFT JOIN role_permissions rp ON rp.role_id=r.id LEFT JOIN permissions p ON p.id=rp.permission_id
            JOIN users u ON u.id=ur.user_id AND u.is_active=1 WHERE r.code='system_admin' OR p.code=?", [$perm]);
        foreach ($users as $u) {
            if ($entity !== null) {
                $user = $this->app->authz->loadUser((int)$u['user_id']);
                if (!$user || !\App\Core\Authz::inScope($user, $entity)) { continue; }
            }
            $this->notify((int)$u['user_id'], $type, $title, $body, $data);
        }
    }
}
