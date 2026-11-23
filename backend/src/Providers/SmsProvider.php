<?php
declare(strict_types=1);
namespace App\Providers;

interface SmsProvider
{
    public function name(): string;
    /** @return array{ok:bool,message_id:?string,error:?string} ok = قبول المزود الطلب (ليس تسليمًا) */
    public function send(string $to, string $body): array;
}
