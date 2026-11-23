<?php
declare(strict_types=1);
namespace App\Providers;

interface PushProvider
{
    /** @return bool true إذا قبل المزود الرسالة */
    public function send(string $deviceToken, string $title, string $body, array $data = []): bool;
}
