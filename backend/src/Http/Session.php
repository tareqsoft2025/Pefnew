<?php
declare(strict_types=1);
namespace App\Http;

/** غلاف جلسة: يعمل مع $_SESSION الأصلية أو كمصفوفة مستقلة في الاختبارات. */
final class Session
{
    public array $data = [];
    private bool $native = false;

    public static function native(): self
    {
        $s = new self();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_name('fleet_sid');
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
            session_start();
        }
        $s->data = &$_SESSION;
        $s->native = true;
        return $s;
    }

    public function userId(): ?int
    {
        $last = $this->data['last'] ?? 0;
        if (isset($this->data['uid']) && time() - $last > 7200) { $this->destroy(); return null; }
        if (isset($this->data['uid'])) { $this->data['last'] = time(); }
        return $this->data['uid'] ?? null;
    }

    public function login(int $uid): void
    {
        if ($this->native) { session_regenerate_id(true); }
        $this->data['uid'] = $uid; $this->data['last'] = time(); $this->data['csrf'] = bin2hex(random_bytes(32));
    }

    public function destroy(): void
    {
        $this->data = [];
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
    }

    public function csrf(): string { return $this->data['csrf'] ??= bin2hex(random_bytes(32)); }
    public function flash(string $k, ?string $v = null): ?string
    {
        if ($v !== null) { $this->data['flash'][$k] = $v; return null; }
        $x = $this->data['flash'][$k] ?? null; unset($this->data['flash'][$k]); return $x;
    }
}
