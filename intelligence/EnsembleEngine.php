<?php
declare(strict_types=1);

namespace Prometheus\intelligence;

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\core\Signal;

/**
 * ENSEMBLE ENGINE (Item 15).
 *
 * Combina os 6 m?dulos considerando:
 *  - signal e confidence de cada um;
 *  - peso hist?rico (module_weights por m?dulo+horizonte+regime);
 *  - regime atual.
 *
 * Garantias:
 *  1. Contribui??o INDIVIDUAL de cada m?dulo ? calculada e devolvida
 *     (audit?vel: raw_signal ? peso ? confian?a ? contribui??o).
 *  2. Aus?ncia de m?dulo N?O ? tratada como sinal neutro real: o denominador
 *     usa apenas o peso dos m?dulos PRESENTES, e o metadata registra quais
 *     m?dulos faltaram (aus?ncia reduz a confian?a, n?o vira 0 falsamente).
 *  3. M?dulo com confidence muito baixa tem influ?ncia reduzida
 *     (floor de 0.05 evita divis?o por zero), mas continua contando.
 */
final class EnsembleEngine
{
    public function combine(array $signals, string $horizon, string $regime): array
    {
        $present = [];
        foreach ($signals as $signal) {
            if ($signal instanceof Signal && in_array((string)($signal->metadata['status'] ?? 'AVAILABLE'), ['AVAILABLE', 'STALE'], true)) {
                $present[$signal->module] = $signal;
            }
        }

        $missing = array_values(array_diff(SignalNormalizer::MODULES, array_keys($present)));

        $weighted = 0.0;
        $weightSum = 0.0;
        $confidenceSum = 0.0;
        $weights = [];
        $contributions = [];

        foreach ($present as $signal) {
            $weight = $this->weightFor($signal->module, $horizon, $regime);
            $effective = $weight * max(0.05, $signal->confidence);
            $contrib = $signal->value * $effective;
            $weighted += $contrib;
            $weightSum += $effective;
            $confidenceSum += $signal->confidence;
            $weights[$signal->module] = $weight;
            $contributions[$signal->module] = [
                'raw_signal' => round($signal->value, 5),
                'confidence' => round($signal->confidence, 5),
                'weight' => round($weight, 4),
                'effective_weight' => round($effective, 4),
                'contribution' => round($contrib, 5),
                'share_pct' => null, // preenchido abaixo quando weightSum > 0
            ];
        }

        if ($weightSum > 0) {
            foreach ($contributions as $m => $c) {
                $contributions[$m]['share_pct'] = round($c['effective_weight'] / $weightSum * 100, 2);
            }
        }

        $ensemble = $weightSum > 0 ? $weighted / $weightSum : 0.0;

        // Confian?a: m?dia das confian?as dos presentes, penalizada por aus?ncia.
        $avgConfidence = $present ? $confidenceSum / count($present) : 0.05;
        $coverage = count($present) / max(1, count(SignalNormalizer::MODULES));
        $confidence = max(0.05, min(1.0, $avgConfidence * (0.5 + 0.5 * $coverage)));

        return [
            'signal' => max(-1.0, min(1.0, $ensemble)),
            'confidence' => $confidence,
            'weights' => $weights,
            'contributions' => $contributions,
            'missing_modules' => $missing,
            'coverage' => round($coverage, 3),
        ];
    }

    private function weightFor(string $module, string $horizon, string $regime): float
    {
        // As-of-time: no backtest, peso vigente EM T (updated_at <= T).
        // Isso reproduz o que a produ??o teria no instante T: pesos s?o
        // calculados apenas com resultados j? conhecidos (previs?es vencidas).
        $row = Database::fetch(
            'SELECT weight, updated_at FROM module_weights WHERE module=? AND horizon=? AND regime=?'
            . (AsOfTime::enabled() ? ' AND (updated_at IS NULL OR updated_at <= ?)' : ''),
            AsOfTime::enabled()
                ? [$module, $horizon, $regime, AsOfTime::get()]
                : [$module, $horizon, $regime]
        );
        if ($row) {
            return max(0.05, min(5.0, (float)$row['weight']));
        }
        return (float)prometheus_config('prediction.default_module_weight', 1.0);
    }
}
