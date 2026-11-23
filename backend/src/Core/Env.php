<?php
declare(strict_types=1);
namespace App\Core;

/** قراءة متغيرات البيئة من .env (اختياري) أو البيئة الفعلية. لا يُخزن أي سر في المستودع. */
final class Env
{
    private static array $vars = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) { return; }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
            [$k, $v] = explode('=', $line, 2);
            self::$vars[trim($k)] = trim(trim($v), "\"'");
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v !== false && $v !== '') { return $v; }
        return self::$vars[$key] ?? $default;
    }

    public static function set(string $key, string $value): void { self::$vars[$key] = $value; putenv("$key=$value"); }
}
