<?php
declare(strict_types=1);

namespace Prometheus\modules;

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\core\Signal;

/**
 * HEPHAESTUS — módulo de derivativos (Item 10).
 *
 * Combina métricas normalizadas:
 *  - funding_rate:    negativo alto = shorts pagam = pressão de squeeze bullish;
 *                     positivo alto = longs congestionados = risco de flush bearish.
 *                     Normalizado por |funding| / 0.0005 (0.05% é nível "alto").
 *  - open_interest:   nível alto de OI sem tendência de preço = alavancagem esticada.
 *                     Normalizado por z-score da própria série.
 *  - ΔOI (variação):  OI subindo com preço → tendência saudável; OI caindo rápido
 *                     → desalavancagem. Contribui pelo z-score da variação %.
 *  - long_short_ratio: > 1 → crowd long (contrarian leve bearish);
 *                      < 1 → crowd short (contrarian leve bullish).
 *
 * Cada componente só entra com peso se houver DADO REAL; a confiança reflete
 * quantos componentes estão disponíveis. Sem dado nenhum: neutro explícito.
 */
final class HephaestusDerivatives
{
    private const FUNDING_REF = 0.0005; // 0.05% por período = alto

    public function signal(string $horizon = '1h'): Signal
    {
        $funding = $this->latest('funding_rate');
        $oi = $this->latest('open_interest');
        $ls = $this->latest('long_short_ratio');

        if (!$funding && !$oi && !$ls) {
            return new Signal('HEPHAESTUS', 0.0, 0.08, ['reason' => 'no_derivatives_data', 'horizon' => $horizon]);
        }

        $parts = [];
        $confidenceParts = 0.0;
        $weightSum = 0.0;
        $scoreSum = 0.0;

        // 1. Funding rate (peso maior — é o custo direto de carregamento).
        if ($funding !== null) {
            $norm = max(-1.0, min(1.0, $funding / self::FUNDING_REF));
            // Contrarian: funding alto positivo → pressão de queda; negativo → squeeze p/ cima.
            $contrib = -$norm;
            $parts['funding_rate'] = ['value' => $funding, 'normalized' => round($norm, 3), 'contribution' => round($contrib, 4)];
            $scoreSum += $contrib * 1.5;
            $weightSum += 1.5;
            $confidenceParts += 1.0;
        }

        // 2. ΔOI: variação percentual recente do OI (z-score da série de OI).
        if ($oi !== null) {
            $oiSeries = $this->series('open_interest', 48);
            [$z, $available] = $this->zScore($oiSeries);
            if ($available) {
                $contrib = max(-1.0, min(1.0, $z * 0.5));
                $parts['open_interest'] = ['value' => $oi, 'z' => round($z, 3), 'contribution' => round($contrib, 4)];
                $scoreSum += $contrib * 1.0;
                $weightSum += 1.0;
                $confidenceParts += 0.7;
            } else {
                $parts['open_interest'] = ['value' => $oi, 'note' => 'insufficient_history'];
            }
        }

        // 3. Long/short ratio (contrarian suave).
        if ($ls !== null) {
            $norm = max(-1.0, min(1.0, (1.0 - $ls) / 0.5)); // +1 quando ratio=0.5 (crowd short), -1 quando ratio=1.5
            $contrib = $norm * 0.5;
            $parts['long_short_ratio'] = ['value' => $ls, 'normalized' => round($norm, 3), 'contribution' => round($contrib, 4)];
            $scoreSum += $contrib * 1.0;
            $weightSum += 1.0;
            $confidenceParts += 0.7;
        }

        if ($weightSum === 0.0) {
            return new Signal('HEPHAESTUS', 0.0, 0.08, [
                'reason' => 'derivatives_data_without_usable_metrics',
                'parts' => $parts,
                'horizon' => $horizon,
            ]);
        }

        $value = max(-1.0, min(1.0, $scoreSum / $weightSum));
        $confidence = max(0.1, min(0.85, $confidenceParts / 2.7)); // 2.7 = máx teórico

        return new Signal('HEPHAESTUS', $value, $confidence, [
            'parts' => $parts,
            'components_used' => count(array_filter($parts, fn($p) => !isset($p['note']))),
            'horizon' => $horizon,
        ]);
    }

    private function latest(string $metric): ?float
    {
        // As-of-time: em backtest, só observações <= T (sem look-ahead).
        $row = Database::fetch(
            'SELECT value, observed_at FROM derivatives_data WHERE metric=? AND ' . AsOfTime::sqlUpperBound('observed_at') . ' AND ingested_at IS NOT NULL AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . ' AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at)) ORDER BY observed_at DESC LIMIT 1',
            [$metric]
        );
        if ($row) {
            AsOfTime::assertNoFuture((string)$row['observed_at'], 'HephaestusDerivatives');
        }
        return $row ? (float)$row['value'] : null;
    }

    private function series(string $metric, int $limit): array
    {
        $rows = Database::fetchAll(
            'SELECT value, observed_at FROM derivatives_data WHERE metric=? AND ' . AsOfTime::sqlUpperBound('observed_at') . ' AND ingested_at IS NOT NULL AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . ' AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at)) ORDER BY observed_at DESC LIMIT ' . (int)$limit,
            [$metric]
        );
        return array_map(fn($r) => (float)$r['value'], $rows);
    }

    /**
     * z-score do último valor contra os anteriores.
     * @return array{0: float, 1: bool} [z, disponível]
     */
    private function zScore(array $values): array
    {
        if (count($values) < 2) {
            return [0.0, false];
        }
        $current = array_shift($values); // série está em ordem DESC
        $mean = array_sum($values) / count($values);
        $var = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / count($values);
        $std = sqrt($var);
        if ($std < 1e-12) {
            return [0.0, true];
        }
        return [($current - $mean) / $std, true];
    }
}
