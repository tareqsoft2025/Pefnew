<?php
declare(strict_types=1);
namespace App\Core;

final class Geo
{
    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
        $dp = $p2 - $p1; $dl = deg2rad($lon2 - $lon1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** أقصر مسافة (م) من نقطة إلى مسار [[lat,lon],...] باستخدام إسقاط محلي مستوٍ. */
    public static function distanceToPolyline(float $lat, float $lon, array $line): float
    {
        $n = count($line);
        if ($n === 0) { return INF; }
        if ($n === 1) { return self::haversine($lat, $lon, (float)$line[0][0], (float)$line[0][1]); }
        $k = cos(deg2rad($lat)) * 111320.0;
        $best = INF;
        for ($i = 0; $i < $n - 1; $i++) {
            $ax = ((float)$line[$i][1] - $lon) * $k;     $ay = ((float)$line[$i][0] - $lat) * 110540.0;
            $bx = ((float)$line[$i + 1][1] - $lon) * $k; $by = ((float)$line[$i + 1][0] - $lat) * 110540.0;
            $dx = $bx - $ax; $dy = $by - $ay;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $len2)) : 0.0;
            $best = min($best, hypot($ax + $t * $dx, $ay + $t * $dy));
        }
        return $best;
    }
}
