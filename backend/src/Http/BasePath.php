<?php
declare(strict_types=1);
namespace App\Http;

use App\Core\Env;

/**
 * دعم التشغيل تحت مسار فرعي (مثل https://tarsof.top/Tasks).
 * المصدر: APP_BASE_PATH في .env إن ضُبط، وإلا مجلد السكربت (SCRIPT_NAME).
 * القيمة '' تعني جذر النطاق (السلوك القديم دون أي تغيير).
 */
final class BasePath
{
    private static ?string $cached = null;

    public static function get(): string
    {
        if (self::$cached !== null) { return self::$cached; }
        $v = Env::get('APP_BASE_PATH');
        if ($v === null) {
            $v = PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' ? '' : dirname($_SERVER['SCRIPT_NAME'] ?? '');
        }
        return self::$cached = self::normalize($v);
    }

    public static function set(?string $v): void { self::$cached = $v === null ? null : self::normalize($v); }

    public static function normalize(string $v): string
    {
        $v = trim(str_replace('\\', '/', $v));
        $v = '/' . trim($v, '/');
        return $v === '/' ? '' : $v;
    }

    /** يضيف المسار الأساسي لرابط داخلي مطلق (يبدأ بـ / وليس //). */
    public static function url(string $path): string
    {
        $b = self::get();
        if ($b === '' || $path === '' || $path[0] !== '/' || str_starts_with($path, '//')) { return $path; }
        if ($path === $b || str_starts_with($path, $b . '/') || str_starts_with($path, $b . '?')) { return $path; }
        return $b . $path;
    }

    /** يزيل المسار الأساسي من مسار الطلب الوارد. */
    public static function strip(string $path): string
    {
        $b = self::get();
        if ($b === '') { return $path; }
        if (strcasecmp($path, $b) === 0) { return '/'; }
        if (strncasecmp($path, $b . '/', strlen($b) + 1) === 0) { return substr($path, strlen($b)) ?: '/'; }
        return $path;
    }

    /** يعيد كتابة href/src/action/formaction المطلقة داخل HTML. */
    public static function rewriteHtml(string $html): string
    {
        if (self::get() === '') { return $html; }
        return (string)preg_replace_callback(
            '~\b(href|src|action|formaction)=(["\'])(/(?!/)[^"\']*)\2~i',
            fn($m) => $m[1] . '=' . $m[2] . self::url($m[3]) . $m[2],
            $html
        );
    }
}
