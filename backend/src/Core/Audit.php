<?php
declare(strict_types=1);
namespace App\Core;

/** سجل تدقيق append-only بسلسلة تجزئة؛ verify() يكشف أي تلاعب. */
final class Audit
{
    public function __construct(private Db $db) {}

    public function log(?int $userId, string $action, ?string $entityType = null, string|int|null $entityId = null, array $details = [], ?string $ip = null): void
    {
        $this->db->tx(function () use ($userId, $action, $entityType, $entityId, $details, $ip) {
            $prev = $this->db->val('SELECT row_hash FROM audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE') ?? str_repeat('0', 64);
            $now = Clock::nowStr();
            $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;
            $ip ??= $_SERVER['REMOTE_ADDR'] ?? null;
            $hash = hash('sha256', $prev . '|' . implode('|', [$userId, $action, $entityType, $entityId, $json, $now]));
            $this->db->insert('audit_logs', [
                'user_id' => $userId, 'action' => $action, 'entity_type' => $entityType,
                'entity_id' => $entityId === null ? null : (string)$entityId, 'details' => $json, 'ip' => $ip,
                'created_at' => $now, 'prev_hash' => $prev, 'row_hash' => $hash,
            ]);
        });
    }

    /** @return int|null رقم أول صف مخالف أو null إذا السلسلة سليمة */
    public function verify(): ?int
    {
        $prev = str_repeat('0', 64);
        foreach ($this->db->all('SELECT * FROM audit_logs ORDER BY id') as $r) {
            $json = $r['details'] === null ? null : $r['details'];
            $calc = hash('sha256', $prev . '|' . implode('|', [$r['user_id'], $r['action'], $r['entity_type'], $r['entity_id'], $json, $r['created_at']]));
            if ($r['prev_hash'] !== $prev || $r['row_hash'] !== $calc) { return (int)$r['id']; }
            $prev = $r['row_hash'];
        }
        return null;
    }
}
