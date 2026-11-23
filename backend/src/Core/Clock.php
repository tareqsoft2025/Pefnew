<?php
declare(strict_types=1);
namespace App\Core;

/** ساعة قابلة للتثبيت في الاختبارات. كل الأوقات UTC بصيغة Y-m-d H:i:s. */
final class Clock
{
    private static ?int $fixed = null;
    public static function now(): int { return self::$fixed ?? time(); }
    public static function fix(?int $ts): void { self::$fixed = $ts; }
    public static function advance(int $s): void { self::$fixed = self::now() + $s; }
    public static function nowStr(): string { return gmdate('Y-m-d H:i:s', self::now()); }
    public static function fmt(int $ts): string { return gmdate('Y-m-d H:i:s', $ts); }
    public static function parse(string $s): int
    {
        // يقبل ISO-8601 أو Y-m-d H:i:s (UTC)
        $t = strtotime(str_contains($s, 'T') ? $s : $s . ' UTC');
        if ($t === false) { throw new ApiException(422, 'validation_failed', 'صيغة تاريخ غير صالحة: ' . $s); }
        return $t;
    }
}
