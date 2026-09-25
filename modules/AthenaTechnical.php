<?php
declare(strict_types=1);

namespace Prometheus\modules;

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\core\Signal;
use Prometheus\indicators\ATR;
use Prometheus\indicators\BollingerBands;
use Prometheus\indicators\EMA;
use Prometheus\indicators\MACD;
use Prometheus\indicators\RSI;
use Prometheus\indicators\Volume;

final class AthenaTechnical
{
    /**
     * Sinal técnico consciente de horizonte:
     * - janelas de lookback crescem com o horizonte;
     * - pesos dos componentes se adaptam (tendência domina em 4h/24h,
     *   reversão à média (BB/RSI) tem mais peso em 15m).
     */
    /**
     * Mapeamento horizonte → timeframe. O lookback em CANDLES do intervalo
     * escolhido cobre ≈ o mesmo horizonte + contexto técnico (EMA 50 precisa
     * de ≥ 50 candles). Cada horizonte lê UM intervalo só — nunca mistura.
     */
    public const HORIZON_INTERVALS = [
        // 15m: candles 1m — resolução fina para reversão à média intrabar.
        '15m' => ['interval' => '1m',  'limit' => 120],  // 120 × 1m = 2h de contexto
        // 1h: candles 5m — mesmo horizonte do sinal, sem ruído de 1m.
        '1h'  => ['interval' => '5m',  'limit' => 180],  // 180 × 5m = 15h
        // 4h: candles 15m.
        '4h'  => ['interval' => '15m', 'limit' => 200],  // 200 × 15m = 50h
        // 24h: candles 1h — o horizonte do sinal É o candle.
        '24h' => ['interval' => '1h',  'limit' => 200],  // 200 × 1h ≈ 8 dias
    ];

    public function signal(string $horizon = '1h', string $symbol = 'BTCUSDT'): Signal
    {
        $map = self::HORIZON_INTERVALS[$horizon] ?? ['interval' => prometheus_config('collector.market_interval', '1m'), 'limit' => 200];
        $interval = $map['interval'];
        $limit = $map['limit'];
        // As-of-time: em backtest, só candles <= T (sem look-ahead). Em produção, NOW().
        $rows = Database::fetchAll(
            'SELECT * FROM market_data WHERE symbol=? AND interval_name=? AND ' . AsOfTime::sqlUpperBound('close_time') . ' AND ingested_at IS NOT NULL AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . ' AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at))'
            . ' ORDER BY open_time DESC LIMIT ' . $limit,
            [$symbol, $interval]
        );
        $rows = array_reverse($rows);
        if ($rows) {
            AsOfTime::assertNoFuture((string)$rows[count($rows) - 1]['open_time'], 'AthenaTechnical');
        }
        if (count($rows) < 60) {
            return new Signal('ATHENA', 0, 0.1, ['reason' => 'insufficient_market_data', 'horizon' => $horizon, 'interval' => $interval, 'rows_found' => count($rows)]);
        }
        $closes = array_map(fn($r) => (float)$r['close_price'], $rows);
        $volumes = array_map(fn($r) => (float)$r['volume'], $rows);
        $ema20 = EMA::calculate($closes, 20);
        $ema50 = EMA::calculate($closes, 50);
        $rsi = RSI::latest($closes);
        $macd = MACD::latest($closes);
        $bb = BollingerBands::latest($closes);
        $atr = ATR::latest($rows);
        $volumeScore = Volume::score($volumes);
        $last = end($closes);
        $trend = (($ema20[count($ema20)-1] ?? $last) - ($ema50[count($ema50)-1] ?? $last)) / max(1, $last);
        $rsiScore = $rsi === null ? 0 : (50 - $rsi) / 50 * -1;
        $macdScore = ($macd['histogram'] ?? 0) / max(1, $last) * 500;
        $bbScore = $bb['position'] === null ? 0 : (0.5 - $bb['position']) * -1.4;

        // Pesos por horizonte: tendência ganha relevância em horizontes longos.
        $w = $this->weightsFor($horizon);
        $value = max(-1, min(1,
            ($trend * $w['trend']) +
            ($rsiScore * $w['rsi']) +
            ($macdScore * $w['macd']) +
            ($bbScore * $w['bb']) +
            ($volumeScore * $w['volume'])
        ));
        $confidence = min(0.95, 0.45 + min(0.25, abs($trend) * 20) + min(0.15, (($atr ?? 0) / max(1, $last)) * 30));

        $lastCandle = end($rows);
        Database::execute(
            'INSERT INTO technical_indicators (symbol, candle_time, rsi, macd, macd_signal, ema_20, ema_50, bb_upper, bb_middle, bb_lower, atr, volume_score)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rsi=VALUES(rsi), macd=VALUES(macd), macd_signal=VALUES(macd_signal), ema_20=VALUES(ema_20), ema_50=VALUES(ema_50), bb_upper=VALUES(bb_upper), bb_middle=VALUES(bb_middle), bb_lower=VALUES(bb_lower), atr=VALUES(atr), volume_score=VALUES(volume_score)',
            [$symbol, $lastCandle['open_time'], $rsi, $macd['macd'], $macd['signal'], end($ema20), end($ema50), $bb['upper'], $bb['middle'], $bb['lower'], $atr, $volumeScore]
        );
        return new Signal('ATHENA', $value, $confidence, [
            'rsi' => $rsi,
            'macd' => $macd,
            'bb' => $bb,
            'atr' => $atr,
            'volumeScore' => $volumeScore,
            'trend' => $trend,
            'horizon' => $horizon,
            'interval' => $interval,
            'rows_used' => count($rows),
            'last_candle_time' => $lastCandle['open_time'],
        ]);
    }

    /**
     * Pesos por componente, por horizonte. Somam ~1.55 (mesma escala total
     * anterior) para não alterar a faixa de saída do sinal.
     */
    private function weightsFor(string $horizon): array
    {
        $map = [
            // 15m: reversão à média importa mais (BB/RSI), tendência menos.
            '15m' => ['trend' => 40, 'rsi' => 0.35, 'macd' => 0.20, 'bb' => 0.40, 'volume' => 0.10],
            '1h'  => ['trend' => 80, 'rsi' => 0.25, 'macd' => 0.25, 'bb' => 0.20, 'volume' => 0.10],
            // 4h/24h: tendência domina.
            '4h'  => ['trend' => 120, 'rsi' => 0.20, 'macd' => 0.30, 'bb' => 0.15, 'volume' => 0.10],
            '24h' => ['trend' => 140, 'rsi' => 0.20, 'macd' => 0.30, 'bb' => 0.10, 'volume' => 0.10],
        ];
        return $map[$horizon] ?? $map['1h'];
    }
}
