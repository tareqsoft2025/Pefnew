<?php
declare(strict_types=1);
namespace App\Http;

/** نص HTML موثوق (لا يُهرّب). */
final class Raw
{
    public function __construct(public string $html) {}
}
