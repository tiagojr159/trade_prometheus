<?php
declare(strict_types=1);

namespace Prometheus\modules;

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\core\Signal;

/**
 * CRONOS — módulo macro (Item 11).
 *
 * Distinção FUNDAMENTAL: ZERO REAL ≠ DADO INDISPONÍVEL.
 *  - Métrica sem qualquer observação → NÃO entra no score (contribuição null),
 *    fica registrada como 'unavailable' no metadata.
 *  - Métrica com histórico → normalizada por z-score da própria série
 *    (janela histórica anterior à observação atual, sem look-ahead).
 *
 * Direção esperada (impacto em BTC):
 *  - us_10y_yield ↑ → juros altos puxam liquidez de risco → bearish (-1)
 *  - fed_funds ↑   → idem → bearish (-1)
 *  - dollar_index_proxy ↑ → dólar forte pressiona BTC → bearish (-1)
 *  - us_cpi ↑      → inflação alta → neutro-negativo → bearish (-0.5)
 */
final class CronosMacro
{
    private const DIRECTIONS = [
        'us_10y_yield'       => -1.0,
        'fed_funds'          => -1.0,
        'dollar_index_proxy' => -1.0,
        'us_cpi'             => -0.5,
        'us_unemployment'    => 0.5, // desemprego alto → expectativa de estímulo → levemente bullish
    ];

    public function signal(string $horizon = '1h'): Signal
    {
        // As-of-time: janela [T-120d, T] — limite inferior E superior (sem o
        // superior, em backtest observações futuras a T entrariam na série).
        $rows = Database::fetchAll(
            'SELECT metric, value, observed_at FROM macro_data
             WHERE ' . AsOfTime::sqlLowerBoundRelative('observed_at', '120 DAY') . '
               AND ' . AsOfTime::sqlUpperBound('observed_at') . '
               AND ingested_at IS NOT NULL AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . '
               AND temporal_quality IN ("EXACT","INGESTION_ONLY")
               AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at))
             ORDER BY observed_at ASC'
        );

        if (!$rows) {
            // Sem registro nenhum: neutro explícito com motivo — não é zero real.
            return new Signal('CRONOS', 0.0, 0.08, [
                'reason' => 'no_macro_data',
                'status' => 'UNAVAILABLE',
                'note' => 'macro source (FRED) not configured or never collected',
                'horizon' => $horizon,
            ]);
        }

        // As-of: usa a ÚLTIMA observação publicada/disponível EM T (não NOW()).
        // Em produção, sqlUpperBound = NOW() → última observação real.
        $latest = [];
        foreach ($rows as $r) {
            $latest[(string)$r['metric']] = $r['observed_at'];
        }

        $series = [];
        $latest = [];
        foreach ($rows as $r) {
            $series[(string)$r['metric']][] = (float)$r['value'];
            $latest[(string)$r['metric']] = $r['observed_at'];
        }

        $scoreSum = 0.0;
        $weightSum = 0.0;
        $confidenceSum = 0.0;
        $contributions = [];
        $unavailable = [];

        foreach (self::DIRECTIONS as $metric => $dir) {
            if (!isset($series[$metric])) {
                $unavailable[$metric] = 'never_collected';
                continue;
            }
            $values = $series[$metric];
            $n = count($values);
            $current = end($values);
            [$z, $available] = $this->zScore($values);
            if (!$available) {
                // Uma única observação: não dá para normalizar — não inventa direção.
                $unavailable[$metric] = 'insufficient_history (1 obs)';
                $contributions[$metric] = [
                    'current' => $current,
                    'z' => null,
                    'obs' => $n,
                    'contribution' => null,
                ];
                continue;
            }

            $contribution = max(-1.0, min(1.0, $z * 0.5)) * $dir;
            $scoreSum += $contribution;
            $weightSum += 1.0;
            $confidenceSum += min(1.0, $n / 10.0); // série macro é de baixa frequência
            $contributions[$metric] = [
                'current' => $current,
                'z' => round($z, 3),
                'direction' => $dir,
                'contribution' => round($contribution, 4),
                'obs' => $n,
                'last_observed_at' => $latest[$metric],
            ];
        }

        if ($weightSum === 0.0) {
            return new Signal('CRONOS', 0.0, 0.08, [
                'reason' => 'macro_data_without_normalizable_series',
                'status' => 'UNAVAILABLE',
                'unavailable' => $unavailable,
                'contributions' => $contributions,
                'horizon' => $horizon,
            ]);
        }

        $value = max(-1.0, min(1.0, $scoreSum / $weightSum));
        $confidence = max(0.1, min(0.75, $confidenceSum / count(self::DIRECTIONS)));

        return new Signal('CRONOS', $value, $confidence, [
            'contributions' => $contributions,
            'unavailable' => $unavailable,
            'series_used' => $weightSum,
            'horizon' => $horizon,
        ]);
    }

    /**
     * z-score do último valor contra os anteriores (sem look-ahead).
     * @return array{0: float, 1: bool} [z, disponível]
     */
    private function zScore(array $values): array
    {
        $n = count($values);
        if ($n < 2) {
            return [0.0, false];
        }
        $current = array_pop($values);
        $mean = array_sum($values) / count($values);
        $var = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / count($values);
        $std = sqrt($var);
        if ($std < 1e-12) {
            return [0.0, true]; // série constante → z=0 é a resposta correta
        }
        return [($current - $mean) / $std, true];
    }
}
