<?php
declare(strict_types=1);

if (!function_exists('e')) {
    /** تهريب HTML للقوالب */
    function e(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
