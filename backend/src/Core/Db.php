<?php
declare(strict_types=1);
namespace App\Core;

use PDO;

final class Db
{
    public PDO $pdo;

    public function __construct(string $dsn, string $user, string $pass)
    {
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00', NAMES utf8mb4");
    }

    public static function fromEnv(): self
    {
        $dsn = Env::get('DB_DSN') ?? sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', '127.0.0.1'), Env::get('DB_PORT', '3306'), Env::get('DB_NAME', 'fleet'));
        return new self($dsn, Env::get('DB_USER', 'root') ?? '', Env::get('DB_PASS', '') ?? '');
    }

    public function q(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st;
    }
    public function all(string $sql, array $p = []): array { return $this->q($sql, $p)->fetchAll(); }
    public function one(string $sql, array $p = []): ?array { $r = $this->q($sql, $p)->fetch(); return $r === false ? null : $r; }
    public function val(string $sql, array $p = []): mixed { $r = $this->q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
    public function exec(string $sql, array $p = []): int { return $this->q($sql, $p)->rowCount(); }

    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table,
            implode(',', array_map(fn($c) => "`$c`", $cols)), implode(',', array_fill(0, count($cols), '?')));
        $this->q($sql, array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $wp = []): int
    {
        $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($data)));
        return $this->exec("UPDATE `$table` SET $set WHERE $where", [...array_values($data), ...$wp]);
    }

    /** معاملة متداخلة آمنة. */
    public function tx(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) { return $fn(); }
        $this->pdo->beginTransaction();
        try { $r = $fn(); $this->pdo->commit(); return $r; }
        catch (\Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $e; }
    }

    public static function isDuplicate(\Throwable $e): bool
    {
        return $e instanceof \PDOException && ($e->errorInfo[1] ?? 0) === 1062;
    }
}
