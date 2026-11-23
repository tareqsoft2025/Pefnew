<?php
declare(strict_types=1);
namespace App\Providers;

/**
 * مزود SMS عام عبر HTTP JSON: SMS_API_URL, SMS_API_TOKEN (Bearer), SMS_SENDER.
 * يرسل {"to","from","message"} ويتوقع JSON يحتوي id أو message_id. عدّل هذه الفئة لتطابق مزودك الفعلي.
 */
final class HttpSmsProvider implements SmsProvider
{
    public function __construct(private string $url, private string $token, private string $sender) {}
    public function name(): string { return 'http'; }
    public function send(string $to, string $body): array
    {
        [$code, $res] = Http::request('POST', $this->url, ["Authorization: Bearer {$this->token}", 'Content-Type: application/json'],
            json_encode(['to' => $to, 'from' => $this->sender, 'message' => $body], JSON_UNESCAPED_UNICODE));
        $j = json_decode($res, true);
        if ($code >= 200 && $code < 300) { return ['ok' => true, 'message_id' => (string)($j['message_id'] ?? $j['id'] ?? ''), 'error' => null]; }
        return ['ok' => false, 'message_id' => null, 'error' => "http_$code"];
    }
}
