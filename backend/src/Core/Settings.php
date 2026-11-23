<?php
declare(strict_types=1);
namespace App\Core;

/** إعدادات قابلة للتعديل من لوحة الإدارة مع قيم افتراضية من config/permissions.php */
final class Settings
{
    private ?array $cache = null;
    public function __construct(private Db $db, private array $defaults) {}

    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->defaults;
            foreach ($this->db->all('SELECT skey, svalue FROM settings') as $r) { $this->cache[$r['skey']] = $r['svalue']; }
        }
        return $this->cache;
    }
    public function get(string $k): string { return $this->all()[$k] ?? ''; }
    public function int(string $k): int { return (int)$this->get($k); }
    public function bool(string $k): bool { return $this->get($k) === '1'; }
    public function defaults(): array { return $this->defaults; }

    public function set(string $k, string $v): void
    {
        if (!array_key_exists($k, $this->defaults)) { throw new ApiException(422, 'validation_failed', "إعداد غير معروف: $k"); }
        $this->db->q('INSERT INTO settings (skey, svalue, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue), updated_at=VALUES(updated_at)', [$k, $v, Clock::nowStr()]);
        $this->cache = null;
    }
}
