<?php
declare(strict_types=1);

namespace Prometheus\prediction;

final class QuantFeatures
{
    public const VERSION = 1;
    public const NAMES = ['return_1_bps', 'return_5_bps', 'return_15_bps', 'return_60_bps', 'mean_range_15_bps', 'relative_volume_15'];

    public static function fromCandles(array $candles): array
    {
        $count = count($candles);
        if ($count === 0) return [];
        $last = $candles[$count - 1];
        $price = (float)$last['close_price'];
        $features = [];
        foreach ([1, 5, 15, 60] as $lag) {
            $features['return_' . $lag . '_bps'] = $count > $lag
                ? 10000 * ($price / (float)$candles[$count - 1 - $lag]['close_price'] - 1.0) : null;
        }
        $features['mean_range_15_bps'] = null;
        $features['relative_volume_15'] = null;
        if ($count >= 15) {
            $ranges = 0.0;
            $volume = 0.0;
            foreach (array_slice($candles, -15) as $row) {
                $ranges += ((float)$row['high_price'] - (float)$row['low_price']) / (float)$row['close_price'];
                $volume += (float)$row['volume'];
            }
            $features['mean_range_15_bps'] = $ranges * 10000 / 15;
            if ($volume > 0) $features['relative_volume_15'] = 15 * (float)$last['volume'] / $volume;
        }
        return $features;
    }
}
