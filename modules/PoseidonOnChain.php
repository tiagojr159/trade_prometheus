<?php
declare(strict_types=1);

namespace Prometheus\modules;

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\core\Logger;
use Prometheus\core\Signal;

/**
 * POSEIDON - on-chain analysis using real Coin Metrics Community data.
 *
 * The module uses historical changes, not raw monotonic levels, so metrics like
 * current supply do not become permanently bullish just because they rise over
 * time. Absence or insufficient history yields confidence 0 and an explicit
 * reason, not a synthetic neutral signal.
 */
final class PoseidonOnChain
{
    /** Expected BTC price impact when the metric's daily change rises. */
    private const DIRECTIONS = [
        'active_addresses' => 1,
        'tx_count' => 1,
        'tx_transfer_count' => 1,
        'fees_total_native' => 1,
        'hash_rate' => 1,
        'supply_current' => -1,
        'issuance_total_native' => -1,
        'block_count' => 1,
    ];

    /** Relative importance in the on-chain composite. */
    private const WEIGHTS = [
        'active_addresses' => 1.20,
        'tx_count' => 1.00,
        'tx_transfer_count' => 0.90,
        'fees_total_native' => 0.80,
        'hash_rate' => 1.10,
        'supply_current' => 0.45,
        'issuance_total_native' => 0.65,
        'block_count' => 0.35,
    ];

    public function signal(string $horizon = '1h'): Signal
    {
        // As-of-time: janela [T-60d, T] — AMBOS os limites respeitam o relógio
        // virtual (limite inferior E superior; sem o superior, em backtest a
        // janela incluiria dados futuros a T).
        $rows = Database::fetchAll(
            'SELECT metric, value, observed_at FROM onchain_data
             WHERE source = "coin_metrics_community"
               AND ' . AsOfTime::sqlLowerBoundRelative('observed_at', '60 DAY') . '
               AND ' . AsOfTime::sqlUpperBound('observed_at') . '
               AND ingested_at IS NOT NULL AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . '
               AND temporal_quality IN ("EXACT","INGESTION_ONLY")
               AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at))
             ORDER BY metric ASC, observed_at ASC'
        );

        if (!$rows) {
            return new Signal('POSEIDON', 0.0, 0.0, [
                'reason' => 'no_coin_metrics_onchain_data',
                'status' => 'UNAVAILABLE',
                'horizon' => $horizon,
            ]);
        }

        $series = [];
        foreach ($rows as $r) {
            $metric = (string)$r['metric'];
            $series[$metric][] = [
                'value' => (float)$r['value'],
                'observed_at' => (string)$r['observed_at'],
            ];
        }

        $weightedScore = 0.0;
        $weightUsed = 0.0;
        $confidenceNumerator = 0.0;
        $contributions = [];
        $usedMetrics = [];

        foreach ($series as $metric => $points) {
            $direction = self::DIRECTIONS[$metric] ?? null;
            if ($direction === null) {
                Logger::info('poseidon_unknown_metric_ignored', ['metric' => $metric]);
                continue;
            }

            $changes = $this->changes(array_column($points, 'value'));
            $n = count($changes);
            if ($n < 5) {
                $contributions[$metric] = [
                    'obs' => count($points),
                    'note' => 'insufficient_history',
                ];
                continue;
            }

            [$z, $available] = $this->zScore($changes);
            if (!$available) {
                $contributions[$metric] = [
                    'obs' => count($points),
                    'note' => 'flat_or_unusable_history',
                ];
                continue;
            }

            $weight = self::WEIGHTS[$metric] ?? 1.0;
            $contribution = max(-1.0, min(1.0, $z * 0.45)) * $direction;
            $metricConfidence = min(1.0, $n / 20.0);

            $weightedScore += $contribution * $weight;
            $weightUsed += $weight;
            $confidenceNumerator += $metricConfidence * $weight;
            $usedMetrics[] = $metric;

            $latest = end($points);
            $contributions[$metric] = [
                'current' => $latest['value'],
                'observed_at' => $latest['observed_at'],
                'change' => round((float)end($changes), 8),
                'z' => round($z, 3),
                'direction' => $direction,
                'weight' => $weight,
                'contribution' => round($contribution, 4),
                'obs' => count($points),
            ];
        }

        if ($weightUsed <= 0.0) {
            return new Signal('POSEIDON', 0.0, 0.0, [
                'reason' => 'coin_metrics_without_sufficient_history',
                'status' => 'UNAVAILABLE',
                'metrics_seen' => array_keys($series),
                'contributions' => $contributions,
                'horizon' => $horizon,
            ]);
        }

        $value = max(-1.0, min(1.0, $weightedScore / $weightUsed));
        $coverage = count($usedMetrics) / count(self::DIRECTIONS);
        $confidence = max(0.0, min(0.9, ($confidenceNumerator / $weightUsed) * (0.55 + 0.45 * $coverage)));

        return new Signal('POSEIDON', $value, $confidence, [
            'source' => 'coin_metrics_community',
            'metrics_used' => $usedMetrics,
            'metric_count' => count($usedMetrics),
            'coverage' => round($coverage, 3),
            'contributions' => $contributions,
            'horizon' => $horizon,
        ]);
    }

    /**
     * Uses relative daily changes when possible; falls back to absolute changes
     * for zero/near-zero previous values.
     *
     * @param float[] $values
     * @return float[]
     */
    private function changes(array $values): array
    {
        $changes = [];
        for ($i = 1, $n = count($values); $i < $n; $i++) {
            $prev = (float)$values[$i - 1];
            $current = (float)$values[$i];
            $changes[] = abs($prev) > 1e-12 ? (($current - $prev) / abs($prev)) : ($current - $prev);
        }
        return $changes;
    }

    /**
     * z-score of the latest change against previous changes.
     *
     * @param float[] $values
     * @return array{0: float, 1: bool}
     */
    private function zScore(array $values): array
    {
        $n = count($values);
        if ($n < 5) {
            return [0.0, false];
        }

        $current = array_pop($values);
        $mean = array_sum($values) / count($values);
        $var = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / count($values);
        $std = sqrt($var);
        if ($std < 1e-12) {
            return [0.0, false];
        }

        return [($current - $mean) / $std, true];
    }
}
