<?php
declare(strict_types=1);
namespace App\Providers;

/** افتراضي بلا Firebase: لا يرسل شيئًا (التطبيق يعتمد على polling). */
final class NullPushProvider implements PushProvider
{
    public array $sent = [];
    public function send(string $deviceToken, string $title, string $body, array $data = []): bool { $this->sent[] = compact('deviceToken', 'title', 'body', 'data'); return false; }
}
