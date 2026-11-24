<?php
declare(strict_types=1);
namespace App\Providers;

use App\Core\Geo;

/** بديل بلا خدمة خارجية: خط مستقيم بين النقاط. المدير يعدله يدويًا قبل الاعتماد. */
final class StraightLineRouting implements RoutingProvider
{
    public function name(): string { return 'straight'; }
    public function route(array $points): array
    {
        $d = 0.0;
        for ($i = 1; $i < count($points); $i++) { $d += Geo::haversine($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]); }
        return ['geometry' => array_values($points), 'distance_m' => (int)$d, 'duration_s' => null];
    }
}
