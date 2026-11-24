<?php
declare(strict_types=1);
namespace App\Core;

/** مشغل migrations بسيط: ملفات SQL مرقمة، جدول schema_migrations، يعمل على استضافة مشتركة. */
final class Migrator
{
    public function __construct(private Db $db, private string $dir) {}

    /** @return string[] الملفات المنفذة */
    public function run(): array
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB');
        $done = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');
        $files = glob($this->dir . '/*.sql');
        sort($files);
        $ran = [];
        foreach ($files as $f) {
            $name = basename($f);
            if (in_array($name, $done, true)) { continue; }
            foreach ($this->split((string)file_get_contents($f)) as $stmt) { $this->db->pdo->exec($stmt); }
            $this->db->insert('schema_migrations', ['name' => $name, 'applied_at' => Clock::nowStr()]);
            $ran[] = $name;
        }
        return $ran;
    }

    private function split(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode(";\n", $sql . "\n")), fn($s) => $s !== ''));
    }
}
