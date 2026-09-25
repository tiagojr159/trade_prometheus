<?php
declare(strict_types=1);

namespace Prometheus\intelligence;

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\core\Logger;

/**
 * REGIME DETECTOR (Item 14).
 *
 * Melhorias sobre a versão anterior:
 *  1. Thresholds orientados por dados: a volatilidade atual é comparada ao
 *     percentil da distribuição de volatilidade de janelas passadas (mesma
 *     janela deslizante), em vez de constante arbitrária (0.006).
 *  2. NÃO insere regime duplicado a cada previsão: nova linha só se o regime
 *     mudou ou se a última classificação for mais antiga que
 *     regime.dedupe_minutes (default 15).
 *  3. Evidências completas no metadata: trend, vol atual, percentil, cutoffs,
 *     tamanho das amostras e janelas usadas.
 *
 * Regimes: BULLISH, BEARISH, SIDEWAYS, HIGH_VOLATILITY.
 * (HIGH_VOLATILITY tem precedência — é um estado de risco, não de direção.)
 */
final class RegimeDetector
{
    public function detect(string $symbol = 'BTCUSDT'): array
    {
        // As-of-time: em backtest, regime calculado somente com candles <= T.
        $rows = Database::fetchAll(
            'SELECT close_price, open_time FROM market_data WHERE symbol=? AND interval_name=? AND ' . AsOfTime::sqlUpperBound('open_time')
            . ' ORDER BY open_time DESC LIMIT 1500',
            [$symbol, prometheus_config('collector.market_interval', '1m')]
        );
        if ($rows) {
            AsOfTime::assertNoFuture((string)$rows[0]['open_time'], 'RegimeDetector');
        }
        $rows = array_reverse($rows);
        if (count($rows) < 120) {
            return [
                'regime' => 'SIDEWAYS',
                'confidence' => 0.2,
                'metadata' => ['reason' => 'insufficient_data', 'obs' => count($rows)],
            ];
        }

        $closes = array_map(fn($r) => (float)$r['close_price'], $rows);
        $returns = $this->returns($closes);

        // Janela curta: últimos 120 retornos (~2h de 1m) — estado atual.
        $recentReturns = array_slice($returns, -120);
        // Janela longa: distribuição de vol de blocos de 120 retornos passados.
        $volHistory = $this->rollingVolHistory($returns, 120, 30);

        $trend = $this->trendPct(array_slice($closes, -120));
        $vol = $this->std($recentReturns);
        $volPercentile = $this->percentileRank($vol, $volHistory);

        // Cutoffs derivados de dados: vol alta se estiver acima do p80 histórico;
        // fallback suave se a história for curta.
        $highVolCutoff = $volHistory ? $this->quantile($volHistory, 0.80) : 0.006;

        // Trend: compara magnitude com a vol atual — movimento precisa superar
        // ~1.3 desvios-padrão acumulados da janela para contar como direcional.
        $trendThreshold = max(0.004, 1.3 * $vol * sqrt(120) * 0.25);

        if ($vol > $highVolCutoff && $volPercentile >= 0.80) {
            $regime = 'HIGH_VOLATILITY';
        } elseif ($trend > $trendThreshold) {
            $regime = 'BULLISH';
        } elseif ($trend < -$trendThreshold) {
            $regime = 'BEARISH';
        } else {
            $regime = 'SIDEWAYS';
        }

        $confidence = max(0.2, min(0.95,
            min(1.0, $volPercentile) * 0.4 + min(1.0, abs($trend) / max(1e-9, $trendThreshold * 3)) * 0.6
        ));

        $metadata = [
            'trend_pct' => round($trend, 6),
            'trend_threshold' => round($trendThreshold, 6),
            'volatility' => round($vol, 8),
            'vol_percentile' => round($volPercentile, 3),
            'high_vol_cutoff_p80' => round($highVolCutoff, 8),
            'returns_window' => 120,
            'vol_history_blocks' => count($volHistory),
            'obs_total' => count($closes),
        ];

        $this->persist($regime, $confidence, $metadata);

        return ['regime' => $regime, 'confidence' => $confidence, 'metadata' => $metadata];
    }

    /** Insere somente se regime mudou ou última entrada expirou (sem duplicatas). */
    private function persist(string $regime, float $confidence, array $metadata): void
    {
        $dedupeMinutes = (int)prometheus_config('regime.dedupe_minutes', 15);
        $last = Database::fetch('SELECT regime, created_at FROM market_regimes ORDER BY id DESC LIMIT 1');
        if ($last) {
            $sameRecent = $last['regime'] === $regime
                && strtotime((string)$last['created_at']) > strtotime(AsOfTime::getEffectiveNow()) - $dedupeMinutes * 60;
            if ($sameRecent) {
                return;
            }
        }
        Database::execute(
            'INSERT INTO market_regimes (regime, confidence, metadata) VALUES (?, ?, ?)',
            [$regime, $confidence, json_encode($metadata)]
        );
    }

    /** @return float[] vol de cada bloco de $block retornos, saltando $stride. */
    private function rollingVolHistory(array $returns, int $block, int $maxBlocks): array
    {
        $vols = [];
        $n = count($returns);
        $end = $n - $block; // exclui o bloco mais recente (é o estado atual)
        for ($i = $end - $block; $i >= 0 && count($vols) < $maxBlocks; $i -= $block) {
            $slice = array_slice($returns, $i, $block);
            if (count($slice) === $block) {
                $vols[] = $this->std($slice);
            }
        }
        return $vols;
    }

    private function returns(array $closes): array
    {
        $out = [];
        for ($i = 1; $i < count($closes); $i++) {
            $out[] = ($closes[$i] - $closes[$i - 1]) / max(1e-12, $closes[$i - 1]);
        }
        return $out;
    }

    private function trendPct(array $closes): float
    {
        return (end($closes) - $closes[0]) / max(1e-12, $closes[0]);
    }

    private function std(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $avg = array_sum($values) / $n;
        return sqrt(array_sum(array_map(fn($v) => ($v - $avg) ** 2, $values)) / $n);
    }

    /** @param float[] $history */
    private function percentileRank(float $value, array $history): float
    {
        if (!$history) {
            return 0.5;
        }
        $below = count(array_filter($history, fn($h) => $h < $value));
        return $below / count($history);
    }

    private function quantile(array $sortedOrNot, float $q): float
    {
        sort($sortedOrNot);
        $n = count($sortedOrNot);
        $idx = (int)floor($q * ($n - 1));
        return $sortedOrNot[$idx];
    }
}
