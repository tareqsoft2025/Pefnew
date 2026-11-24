<?php
declare(strict_types=1);
namespace App\Providers;

/** Firebase Cloud Messaging HTTP v1 عبر Service Account (FCM_SERVICE_ACCOUNT_JSON = مسار الملف خارج المستودع). */
final class FcmPushProvider implements PushProvider
{
    private ?string $token = null;
    private int $tokenExp = 0;

    public function __construct(private string $serviceAccountPath) {}

    public function send(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        $sa = json_decode((string)@file_get_contents($this->serviceAccountPath), true);
        if (!$sa || empty($sa['project_id'])) { return false; }
        $access = $this->accessToken($sa);
        if (!$access) { return false; }
        $payload = ['message' => ['token' => $deviceToken, 'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data), 'android' => ['priority' => 'HIGH']]];
        [$code] = Http::request('POST', "https://fcm.googleapis.com/v1/projects/{$sa['project_id']}/messages:send",
            ["Authorization: Bearer $access", 'Content-Type: application/json'], json_encode($payload));
        return $code === 200;
    }

    private function accessToken(array $sa): ?string
    {
        if ($this->token && time() < $this->tokenExp - 60) { return $this->token; }
        $b = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $head = $b(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
        if (!openssl_sign("$head.$claims", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) { return null; }
        [$code, $res] = Http::request('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$claims." . $b($sig)]));
        $j = json_decode($res, true);
        if ($code !== 200 || empty($j['access_token'])) { return null; }
        $this->tokenExp = $now + (int)($j['expires_in'] ?? 3600);
        return $this->token = $j['access_token'];
    }
}
