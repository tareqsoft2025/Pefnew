<?php
declare(strict_types=1);
namespace App\Core;

/** محدد معدل بالجدول (يعمل على الاستضافة المشتركة دون Redis). */
final class RateLimiter
{
    public function __construct(private Db $db) {}

    public function hit(string $bucket, int $max, int $windowS): void
    {
        $now = Clock::now();
        $bucket = substr($bucket, 0, 190);
        $row = $this->db->one('SELECT hits, window_start FROM rate_limits WHERE bucket=?', [$bucket]);
        if (!$row || $now - (int)$row['window_start'] >= $windowS) {
            $this->db->q('REPLACE INTO rate_limits (bucket,hits,window_start) VALUES (?,?,?)', [$bucket, 1, $now]);
            return;
        }
        if ((int)$row['hits'] >= $max) {
            throw new ApiException(429, 'rate_limited', 'عدد الطلبات كبير جدًا، حاول لاحقًا', ['retry_after' => $windowS - ($now - (int)$row['window_start'])]);
        }
        $this->db->exec('UPDATE rate_limits SET hits=hits+1 WHERE bucket=?', [$bucket]);
    }
}
