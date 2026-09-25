<?php
declare(strict_types=1);

namespace Prometheus\indicators;

final class Volume
{
    public static function score(array $volumes, int $period = 30): float
    {
        if (count($volumes) < $period + 1) {
            return 0.0;
        }
        $last = (float)end($volumes);
        $avg = array_sum(array_slice($volumes, -($period + 1), $period)) / $period;
        if ($avg <= 0) {
            return 0.0;
        }
        return max(-1.0, min(1.0, (($last / $avg) - 1) / 2));
    }
}
