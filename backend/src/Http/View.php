<?php
declare(strict_types=1);
namespace App\Http;

/** قوالب PHP عادية مع تهريب افتراضي عبر e(). */
final class View
{
    public static string $dir = '';

    public static function render(string $name, array $vars = [], ?string $layout = 'layout'): string
    {
        $dir = self::$dir ?: dirname(__DIR__, 2) . '/views';
        $content = (static function (string $__f, array $__v): string {
            extract($__v, EXTR_SKIP);
            ob_start();
            require $__f;
            return (string)ob_get_clean();
        })($dir . '/' . $name . '.php', $vars);
        if ($layout === null) { return $content; }
        return (static function (string $__f, array $__v, string $content): string {
            extract($__v, EXTR_SKIP);
            ob_start();
            require $__f;
            return (string)ob_get_clean();
        })($dir . '/' . $layout . '.php', $vars, $content);
    }
}
