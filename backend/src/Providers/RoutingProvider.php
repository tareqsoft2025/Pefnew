<?php
declare(strict_types=1);
namespace App\Providers;

interface RoutingProvider
{
    public function name(): string;
    /** @param array $points [[lat,lon],...] (2+) @return array{geometry:array,distance_m:int,duration_s:?int} */
    public function route(array $points): array;
}
