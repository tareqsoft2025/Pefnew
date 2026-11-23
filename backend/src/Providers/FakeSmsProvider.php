<?php
declare(strict_types=1);
namespace App\Providers;

/** بيئة اختبار: يقبل كل شيء ويحتفظ بالرسائل في الذاكرة. */
final class FakeSmsProvider implements SmsProvider
{
    public array $sent = [];
    public function name(): string { return 'fake'; }
    public function send(string $to, string $body): array
    {
        $this->sent[] = compact('to', 'body');
        return ['ok' => true, 'message_id' => 'fake-' . count($this->sent), 'error' => null];
    }
}
