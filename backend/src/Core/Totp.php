<?php
declare(strict_types=1);
namespace App\Core;

/** TOTP (RFC 6238) لتفعيل MFA للإداريين دون مزود خارجي (تطبيقات Authenticator). */
final class Totp
{
    private const ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string { return self::b32(random_bytes($bytes)); }

    public static function code(string $secret, ?int $time = null, int $step = 30): string
    {
        $counter = intdiv($time ?? Clock::now(), $step);
        $h = hash_hmac('sha1', pack('J', $counter), self::unb32($secret), true);
        $o = ord($h[19]) & 0xf;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $t = Clock::now();
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $t + $i * 30), $code)) { return true; }
        }
        return false;
    }

    public static function uri(string $secret, string $account, string $issuer = 'Fleet'): string
    {
        return 'otpauth://totp/' . rawurlencode("$issuer:$account") . "?secret=$secret&issuer=" . rawurlencode($issuer);
    }

    private static function b32(string $d): string
    {
        $bits = '';
        foreach (str_split($d) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) { $out .= self::ALPHA[bindec(str_pad($chunk, 5, '0'))]; }
        return $out;
    }

    private static function unb32(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper($s)) as $c) {
            $p = strpos(self::ALPHA, $c);
            if ($p !== false) { $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT); }
        }
        $out = '';
        foreach (str_split($bits, 8) as $b) { if (strlen($b) === 8) { $out .= chr(bindec($b)); } }
        return $out;
    }
}
