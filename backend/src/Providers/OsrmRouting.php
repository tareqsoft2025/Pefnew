<?php
declare(strict_types=1);
namespace App\Providers;

/** OSRM (خادم ذاتي أو عام للتجارب فقط): ROUTING_OSRM_URL مثل https://router.project-osrm.org */
final class OsrmRouting implements RoutingProvider
{
    public function __construct(private string $baseUrl) {}
    public function name(): string { return 'osrm'; }
    public function route(array $points): array
    {
        $coords = implode(';', array_map(fn($p) => $p[1] . ',' . $p[0], $points));
        [$code, $res] = Http::request('GET', rtrim($this->baseUrl, '/') . "/route/v1/driving/$coords?overview=full&geometries=geojson");
        $j = json_decode($res, true);
        if ($code !== 200 || empty($j['routes'][0])) { throw new \RuntimeException('routing_failed'); }
        $r = $j['routes'][0];
        return ['geometry' => array_map(fn($c) => [$c[1], $c[0]], $r['geometry']['coordinates']),
            'distance_m' => (int)$r['distance'], 'duration_s' => (int)$r['duration']];
    }
}
