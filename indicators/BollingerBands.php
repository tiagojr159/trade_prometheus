<?php
declare(strict_types=1);

namespace Prometheus\indicators;

final class BollingerBands
{
    public static function latest(array $closes, int $period = 20, float $multiplier = 2.0): array
    {
        if (count($closes) < $period) {
            return ['upper' => null, 'middle' => null, 'lower' => null, 'position' => null];
        }
        $slice = array_slice($closes, -$period);
        $middle = array_sum($slice) / $period;
        $variance = array_sum(array_map(fn($v) => ($v - $middle) ** 2, $slice)) / $period;
        $std = sqrt($variance);
        $upper = $middle + ($multiplier * $std);
        $lower = $middle - ($multiplier * $std);
        $last = end($closes);
        $position = ($upper - $lower) == 0.0 ? 0.5 : ($last - $lower) / ($upper - $lower);
        return compact('upper', 'middle', 'lower', 'position');
    }
}
