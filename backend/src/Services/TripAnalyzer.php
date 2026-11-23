<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Geo;

/**
 * تحليل نقي (بلا قاعدة بيانات) لنقاط الرحلة: المسافة، التوقفات، الانحرافات، مسافة كل مرحلة مركبة.
 * النقاط: ['t'=>int ts,'lat','lon','acc'=>?float,'speed'=>?float]. يجب أن تكون مرتبة زمنيًا.
 * النتائج حتمية؛ إعادة التحليل بعد وصول نقاط متأخرة تعطي النتيجة الصحيحة نفسها.
 */
final class TripAnalyzer
{
    public const MAX_SPEED_MPS = 70.0;   // نقاط بسرعة ضمنية أعلى تُعد قفزات GPS
    public const MIN_STEP_M = 5.0;       // تجاهل ضجيج ثبات الإحداثيات

    /**
     * @param array $cfg max_accuracy, stop_radius, stop_record_s, dev_distance, dev_duration
     * @param array $routes قائمة مسارات معتمدة (كل مسار قائمة [lat,lon])
     * @param array $segments [['id'=>..,'start'=>ts,'end'=>?ts]]
     */
    public static function analyze(array $points, array $cfg, array $routes = [], array $segments = []): array
    {
        $valid = array_values(array_filter($points, fn($p) => ($p['acc'] ?? 0) <= $cfg['max_accuracy'] || ($p['acc'] ?? null) === null));
        $distance = 0.0; $pairs = [];
        for ($i = 1; $i < count($valid); $i++) {
            $a = $valid[$i - 1]; $b = $valid[$i];
            $d = Geo::haversine($a['lat'], $a['lon'], $b['lat'], $b['lon']);
            $dt = max(1, $b['t'] - $a['t']);
            if ($d < self::MIN_STEP_M || $d / $dt > self::MAX_SPEED_MPS) { continue; }
            $distance += $d;
            $pairs[] = [$b['t'], $d];
        }
        $segDist = [];
        foreach ($segments as $s) {
            $sum = 0.0;
            foreach ($pairs as [$t, $d]) { if ($t >= $s['start'] && ($s['end'] === null || $t < $s['end'])) { $sum += $d; } }
            $segDist[$s['id']] = (int)round($sum);
        }
        return [
            'distance_m' => (int)round($distance),
            'segment_distance_m' => $segDist,
            'stops' => self::stops($valid, $cfg),
            'deviations' => $routes ? self::deviations($valid, $cfg, $routes) : [],
        ];
    }

    public static function stops(array $pts, array $cfg): array
    {
        $stops = []; $anchor = null; $lastStill = null; $cur = null;
        foreach ($pts as $p) {
            if ($anchor === null) { $anchor = $p; $lastStill = $p['t']; continue; }
            $moved = Geo::haversine($anchor['lat'], $anchor['lon'], $p['lat'], $p['lon']) > $cfg['stop_radius']
                || (($p['speed'] ?? 0) > 2.5);
            if (!$moved) {
                $lastStill = $p['t'];
                if ($cur === null && $p['t'] - $anchor['t'] >= $cfg['stop_record_s']) {
                    $cur = ['started_at' => $anchor['t'], 'ended_at' => null, 'lat' => $anchor['lat'], 'lon' => $anchor['lon'], 'last_seen' => $p['t']];
                }
                if ($cur) { $cur['last_seen'] = $p['t']; }
            } else {
                if ($cur) { $cur['ended_at'] = $lastStill; $stops[] = $cur; $cur = null; }
                $anchor = $p; $lastStill = $p['t'];
            }
        }
        if ($cur) { $stops[] = $cur; }   // توقف جارٍ: ended_at = null
        return array_map(function ($s) {
            $end = $s['ended_at'] ?? $s['last_seen'];
            $s['duration_s'] = max(0, $end - $s['started_at']);
            unset($s['last_seen']);
            return $s;
        }, $stops);
    }

    public static function deviations(array $pts, array $cfg, array $routes): array
    {
        $out = []; $off = null; $cur = null;
        foreach ($pts as $p) {
            $d = INF;
            foreach ($routes as $r) { $d = min($d, Geo::distanceToPolyline($p['lat'], $p['lon'], $r)); }
            if ($d > $cfg['dev_distance']) {
                if ($off === null) { $off = ['t' => $p['t'], 'max' => $d, 'lat' => $p['lat'], 'lon' => $p['lon']]; }
                else if ($d > $off['max']) { $off['max'] = $d; $off['lat'] = $p['lat']; $off['lon'] = $p['lon']; }
                if ($cur === null && $p['t'] - $off['t'] >= $cfg['dev_duration']) {
                    $cur = true;
                }
                $off['last'] = $p['t'];
            } else {
                if ($off !== null && $cur) { $out[] = ['started_at' => $off['t'], 'ended_at' => $p['t'], 'max_distance_m' => (int)$off['max'], 'lat' => $off['lat'], 'lon' => $off['lon']]; }
                $off = null; $cur = null;
            }
        }
        if ($off !== null && $cur) { $out[] = ['started_at' => $off['t'], 'ended_at' => null, 'max_distance_m' => (int)$off['max'], 'lat' => $off['lat'], 'lon' => $off['lon']]; }
        return $out;
    }
}
