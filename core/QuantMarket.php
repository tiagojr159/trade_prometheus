<?php
declare(strict_types=1);

namespace Prometheus\core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class QuantMarket
{
    public static function collectQuote(PDO $db, string $symbol): void
    {
        $requested = QuantClock::now();
        $quote = (new HttpClient(6, 0))->getJson(
            'https://api.binance.com/api/v3/ticker/bookTicker?symbol=' . rawurlencode($symbol)
        );
        $received = QuantClock::now();
        if (($quote['symbol'] ?? null) !== $symbol || $received < $requested
            || $received > $requested->modify('+6 seconds')) {
            throw new \RuntimeException('INVALID_QUOTE');
        }
        foreach (['bidPrice', 'askPrice', 'bidQty', 'askQty'] as $key) {
            $value = $quote[$key] ?? null;
            $scale = in_array($key, ['bidQty', 'askQty'], true) ? 12 : 8;
            if (!is_string($value) || !preg_match('/^\d{1,12}(?:\.\d{1,' . $scale . '})?$/D', $value)
                || bccomp($value, '0', $scale) <= 0) {
                throw new \RuntimeException('INVALID_QUOTE');
            }
        }
        if (bccomp($quote['askPrice'], $quote['bidPrice'], 8) < 0) {
            throw new \RuntimeException('CROSSED_QUOTE');
        }
        $db->prepare('INSERT INTO quant_spot_quotes
            (symbol,requested_at_utc,ingested_at_utc,bid,ask,bid_qty,ask_qty) VALUES (?,?,?,?,?,?,?)')
            ->execute([$symbol, QuantClock::sql($requested), QuantClock::sql($received),
                $quote['bidPrice'], $quote['askPrice'], $quote['bidQty'], $quote['askQty']]);
    }

    public static function candles(PDO $db, string $symbol, string $interval, DateTimeImmutable $now): array
    {
        $seconds = ['1m' => 60, '15m' => 900][$interval];
        $local = $now->setTimezone(new DateTimeZone(PROMETHEUS_TIMEZONE))->format('Y-m-d H:i:s');
        $utc = QuantClock::sql($now);
        $stmt = $db->prepare('SELECT id,open_time,close_time,open_price,high_price,low_price,close_price,volume,available_at,ingested_at
            FROM market_data WHERE symbol=? AND source=? AND interval_name=? AND open_time<=?
            AND close_time<=? AND ingested_at<=? AND temporal_quality IN (\'EXACT\',\'INGESTION_ONLY\')
            AND (available_at IS NOT NULL OR temporal_quality=\'INGESTION_ONLY\')
            AND (available_at IS NULL OR available_at<=ingested_at)
            ORDER BY open_time DESC LIMIT 100');
        $stmt->execute([$symbol, 'binance', $interval, $local, $local, $utc]);
        $window = [];
        $previous = null;
        foreach ($stmt as $row) {
            $open = strtotime($row['open_time']);
            $close = strtotime($row['close_time']);
            if ($open === false || $close !== $open + $seconds - 1
                || ($previous === null && $now->getTimestamp() - $close > 2 * $seconds)
                || ($previous !== null && $previous - $open !== $seconds)
                || QuantClock::fromSql($row['ingested_at'])->getTimestamp() < $close
                || (float)$row['low_price'] <= 0 || (float)$row['volume'] < 0
                || (float)$row['low_price'] > min((float)$row['open_price'], (float)$row['close_price'])
                || (float)$row['high_price'] < max((float)$row['open_price'], (float)$row['close_price'])) {
                break;
            }
            $window[] = $row;
            $previous = $open;
        }
        return array_reverse($window);
    }
}
