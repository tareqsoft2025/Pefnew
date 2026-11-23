<?php
declare(strict_types=1);
namespace App\Providers;

/** غلاف HTTP صغير (curl) قابل للاستبدال في الاختبارات عبر Http::$transport. */
final class Http
{
    /** @var callable|null fn(string $method,string $url,array $headers,?string $body): array{0:int,1:string} */
    public static $transport = null;

    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array
    {
        if (self::$transport) { return (self::$transport)($method, $url, $headers, $body); }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_SSL_VERIFYPEER => true]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) { return [0, $err]; }
        return [$code, (string)$res];
    }
}
