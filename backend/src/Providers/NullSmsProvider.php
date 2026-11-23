<?php
declare(strict_types=1);
namespace App\Providers;

/** لا مزود مهيأ: يُسجل الفشل بصدق ولا يدّعي الإرسال. */
final class NullSmsProvider implements SmsProvider
{
    public function name(): string { return 'none'; }
    public function send(string $to, string $body): array { return ['ok' => false, 'message_id' => null, 'error' => 'sms_provider_not_configured']; }
}
