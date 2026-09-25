<?php
declare(strict_types=1);

namespace Prometheus\evaluation;

use Prometheus\core\Database;

/**
 * MetricsCalculator ? atualiza desempenho POR m?dulo+horizonte+regime.
 *
 * Sem?ntica v2 (DirectionPolicy): o target direcional ? BIN?RIO (UP/DOWN).
 *  - dire??o do m?dulo: derivada do SINAL (value > 0 ? UP, value < 0 ? DOWN);
 *    signal exatamente 0 ? INDETERMINATE (n?o conta hit/miss);
 *  - Brier usa a probabilidade impl?cita do m?dulo (p = 0.5 + signal?conf/2)
 *    contra o outcome bin?rio ? nunca derivada de classe prevista;
 *  - FLAT (retorno exatamente zero) N?O chega aqui: PredictionEvaluator
 *    s? chama com hit !== null.
 */
final class MetricsCalculator
{
    public function updateFromPrediction(array $prediction, string $actualDirection, int $correct): void
    {
        $signals = json_decode($prediction['signals_json'], true) ?: [];
        foreach ($signals as $s) {
            if (!in_array((string)($s['status'] ?? $s['metadata']['status'] ?? 'AVAILABLE'), ['AVAILABLE', 'STALE'], true)) {
                continue;
            }
            $value = max(-1.0, min(1.0, (float)($s['value'] ?? 0)));
            $confidence = max(0.0, min(1.0, (float)($s['confidence'] ?? 0)));

            // Dire??o do m?dulo a partir do SINAL: mesma conven??o bin?ria do
            // DirectionPolicy para a previs?o (UP = sinal positivo). Zero exato
            // n?o ? voto direcional.
            if (abs($value) < 1e-12) {
                continue; // INDETERMINATE: m?dulo sem opini?o direcional
            }
            $moduleDirection = $value > 0 ? 'UP' : 'DOWN';
            $moduleCorrect = $moduleDirection === $actualDirection ? 1 : 0;

            // Probabilidade impl?cita do m?dulo para UP (bin?rio):
            // signal 0 ? 0.5; signal ?1 com conf 1 ? ~1.0/0.0.
            $pModule = 0.5 + ($value * $confidence) / 2.0;
            $outcome = $actualDirection === 'UP' ? 1.0 : 0.0;
            $brier = ($pModule - $outcome) ** 2;

            $existing = Database::fetch(
                'SELECT * FROM module_performance WHERE module=? AND horizon=? AND regime=?',
                [$s['module'], $prediction['horizon'], $prediction['regime']]
            );
            if (!$existing) {
                Database::execute(
                    'INSERT INTO module_performance (module, horizon, regime, sample_size, accuracy, brier_score, avg_confidence)
                     VALUES (?, ?, ?, 1, ?, ?, ?)',
                    [$s['module'], $prediction['horizon'], $prediction['regime'], $moduleCorrect, $brier, $confidence]
                );
                continue;
            }
            $n = (int)$existing['sample_size'];
            $newN = $n + 1;
            $accuracy = (((float)$existing['accuracy'] * $n) + $moduleCorrect) / $newN;
            $brierAvg = (((float)$existing['brier_score'] * $n) + $brier) / $newN;
            $conf = (((float)$existing['avg_confidence'] * $n) + $confidence) / $newN;
            Database::execute(
                'UPDATE module_performance SET sample_size=?, accuracy=?, brier_score=?, avg_confidence=? WHERE id=?',
                [$newN, $accuracy, $brierAvg, $conf, $existing['id']]
            );
        }
    }
}
