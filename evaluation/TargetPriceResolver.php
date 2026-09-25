<?php
declare(strict_types=1);

namespace Prometheus\evaluation;

use Prometheus\core\Database;
use Prometheus\core\AsOfTime;

/** Shared target candle rule: earliest candle starting at/after target, closed by evaluation time. */
final class TargetPriceResolver
{
    public static function resolve(string $symbol, string $targetTime, string $evaluationTime, string $interval): ?array
    {
        return Database::fetch(
            'SELECT open_time, close_time, close_price FROM market_data
             WHERE symbol = ? AND interval_name = ? AND open_time >= ? AND close_time <= ?
               AND ingested_at IS NOT NULL AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . '
               AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at))
             ORDER BY open_time ASC LIMIT 1',
            [$symbol, $interval, $targetTime, $evaluationTime]
        );
    }

    /** @param array<int,array{open_time:string,close_time:string,close_price:mixed}> $candles */
    public static function fromCandles(array $candles, string $targetTime, string $evaluationTime): ?array
    {
        foreach ($candles as $candle) {
            if ($candle['open_time'] >= $targetTime && $candle['close_time'] <= $evaluationTime) {
                return $candle;
            }
        }
        return null;
    }
}
