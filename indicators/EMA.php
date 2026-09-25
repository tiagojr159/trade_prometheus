<?php
declare(strict_types=1);

namespace Prometheus\indicators;

final class EMA
{
    public static function calculate(array $values, int $period): array
    {
        if (count($values) < $period) {
            return [];
        }
        $k = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;
        $out = array_fill(0, $period - 1, null);
        $out[] = $ema;
        for ($i = $period; $i < count($values); $i++) {
            $ema = ($values[$i] * $k) + ($ema * (1 - $k));
            $out[] = $ema;
        }
        return $out;
    }
}
