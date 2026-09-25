<?php
declare(strict_types=1);

namespace Prometheus\prediction;

use Prometheus\core\Database;
use Prometheus\core\DirectionPolicy;

/**
 * PROBABILITY CALCULATOR (Item 17).
 *
 * Converte ensemble → probabilidades de forma COERENTE e mensurável:
 *  - Logistic com slope configurável; o sinal É o insumo, a confidence
 *    modula o quão longe da moeda justa (0.5) a probabilidade vai.
 *    NUNCA há salto arbitrário tipo "sinal > 0 ⇒ 70%".
 *  - Limites simétricos [0.5% .. 99.5%] evitam certezas absolutas.
 *  - confidence ≠ probability: confidence é a confiança do ENSEMBLE no sinal;
 *    P(up)/P(down) são probabilidades da DIREÇÃO. Ambas registradas separadas.
 *  - Calibração mensurável: bins de P(up) prevista vs frequência real
 *    observada (avaliada sobre prediction_results), com Brier global.
 */
final class ProbabilityCalculator
{
    public function calculate(float $signal, float $confidence): array
    {
        $slope = (float)prometheus_config('prediction.probability_slope', 1.65);
        $conf = max(0.05, min(1.0, $confidence));
        // Sinal escalado pela confiança: sinal forte + confiança baixa → perto de 0.5.
        $adjusted = max(-1.0, min(1.0, $signal)) * $conf;
        $pUp = 1 / (1 + exp(-$slope * $adjusted));
        $pUp = max(0.005, min(0.995, $pUp));
        return ['up' => $pUp, 'down' => 1.0 - $pUp];
    }

    /**
     * Direção prevista: BINÁRIA a partir da distribuição (DirectionPolicy).
     * P(up) > P(down) → UP; P(down) > P(up) → DOWN; iguais → INDETERMINATE.
     * SIDEWAYS NÃO é mais classe direcional — baixa convicção é exposta como
     * edge (|P(up)-0.5|). Regime continua conceito independente.
     */
    public function direction(float $pUp, float $pDown): string
    {
        return DirectionPolicy::predict($pUp, $pDown)['direction'];
    }

    /**
     * Calibração empírica BINÁRIA (v2): agrupa previsões JÁ AVALIADAS em bins
     * de P(up) e compara com a frequência real de alta. Targets usados:
     * SOMENTE UP/DOWN (v2) — SIDEWAYS (v1 legada) e FLAT são EXCLUÍDOS,
     * nunca contados como UP ou DOWN. Bins com amostra insuficiente são
     * marcados INSUFFICIENT_DATA. Sem recalibração automática aqui.
     */
    public function calibration(): array
    {
        $rows = Database::fetchAll(
            'SELECT p.probability_up, r.actual_direction, r.evaluation_version
             FROM predictions p
             JOIN prediction_results r ON r.prediction_id = p.id
             ORDER BY p.created_at ASC
             LIMIT 5000'
        );
        // Versionamento: apenas v2 entra na calibração corrente (v1 legada
        // é ternária — misturaria semânticas).
        $rows = array_values(array_filter($rows, fn($r) => (int)$r['evaluation_version'] === DirectionPolicy::EVALUATION_VERSION
            && ($r['actual_direction'] === 'UP' || $r['actual_direction'] === 'DOWN')));
        $bins = [];
        foreach ($rows as $r) {
            $p = (float)$r['probability_up'];
            $idx = min(9, (int)floor($p * 10)); // 10 bins de 10%
            $bins[$idx]['n'] = ($bins[$idx]['n'] ?? 0) + 1;
            $bins[$idx]['sum_p'] = ($bins[$idx]['sum_p'] ?? 0.0) + $p;
            if ($r['actual_direction'] === 'UP') {
                $bins[$idx]['up'] = ($bins[$idx]['up'] ?? 0) + 1;
            }
        }

        $out = [];
        $totalN = 0;
        $brierSum = 0.0;
        foreach ($bins as $idx => $b) {
            $n = $b['n'];
            $avgP = $b['sum_p'] / $n;
            $freq = ($b['up'] ?? 0) / $n;
            $out[] = [
                'bin' => sprintf('%d0%%-%d0%%', $idx, $idx + 1),
                'n' => $n,
                'avg_predicted_p_up' => round($avgP, 4),
                'observed_up_freq' => round($freq, 4),
                'calibration_gap' => round($freq - $avgP, 4),
                'reliable' => $n >= 30,
                'insufficient_data' => $n < 30,
            ];
            $totalN += $n;
            $brierSum += (($avgP - $freq) ** 2) * $n; // proxy de calibração (bin-level)
        }

        usort($out, fn($a, $b) => strcmp($a['bin'], $b['bin']));

        return [
            'bins' => $out,
            'total_evaluated' => $totalN,
            'calibration_error' => $totalN > 0 ? round(sqrt($brierSum / $totalN), 4) : null,
            'note' => $totalN < 100 ? 'amostra pequena: calibração ainda não estatisticamente estável' : null,
        ];
    }
}
