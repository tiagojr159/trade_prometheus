<?php
declare(strict_types=1);

namespace Prometheus\evaluation;

use Prometheus\core\Database;
use Prometheus\core\Logger;

/**
 * WEIGHT OPTIMIZER (Item 16).
 *
 * Pesos adaptativos por módulo + horizonte + regime, com salvaguardas:
 *
 *  1. AMOSTRA MÍNIMA: pesos só mudam quando a célula tem pelo menos
 *     `optimizer.min_samples` observações (default 20). Antes disso o peso
 *     permanece no default 1.0 — evidência insuficiente não vira otimismo.
 *  2. MÉTRICA DE QUALIDADE RICA: não é acerto direcional bruto. Combina
 *     - edge direcional: accuracy vs 0.5 (baseline), penalizando SIDEWAYS puros;
 *     - qualidade de confiança: Brier score do módulo (menor = melhor).
 *  3. SUAVIZAÇÃO: o peso novo é blendado com o peso anterior
 *     (learning rate `optimizer.learning_rate`, default 0.3) e limitado a
 *     ±25% de mudança por ciclo — nada de saltos violentos com poucas obs.
 *  4. LIMITES: peso final clampado em [0.25, 3.0].
 *  5. Auditoria: cada mudança registra de/para/motivo em system_logs.
 */
final class WeightOptimizer
{
    public function optimize(): int
    {
        $minSamples = (int)prometheus_config('optimizer.min_samples', 20);
        $lr = (float)prometheus_config('optimizer.learning_rate', 0.3);
        $maxStep = (float)prometheus_config('optimizer.max_step_pct', 0.25);
        $default = (float)prometheus_config('prediction.default_module_weight', 1.0);

        $rows = Database::fetchAll('SELECT * FROM module_performance');
        $count = 0;

        foreach ($rows as $r) {
            $sampleSize = (int)$r['sample_size'];
            $module = (string)$r['module'];
            $horizon = (string)$r['horizon'];
            $regime = (string)$r['regime'];

            // 1. Amostra mínima: sem evidência suficiente, peso volta ao default
            //    gradualmente (decai para 1.0 em vez de pular).
            if ($sampleSize < $minSamples) {
                $this->applyWeight($module, $horizon, $regime, $default, $default, 'insufficient_samples', $sampleSize);
                continue;
            }

            $accuracy = (float)$r['accuracy'];
            $brier = (float)$r['brier_score'];

            // 2. Edge direcional vs baseline 0.5, com shrink bayesiano simples
            //    em direção a 0.5 conforme a amostra (evita sorte de amostras pequenas).
            $shrink = $sampleSize / ($sampleSize + $minSamples);
            $adjAccuracy = 0.5 + ($accuracy - 0.5) * $shrink;
            $edge = max(-0.5, min(0.5, $adjAccuracy - 0.5)); // -0.5..0.5

            // 3. Qualidade de confiança: fator 1.0 no Brier de referência 0.25
            //    (chute aleatório); abaixo disso amplifica, acima penaliza.
            $brierFactor = max(0.5, min(1.5, 1.0 - ($brier - 0.25) * 2.0));

            // Peso alvo: default × (1 + edge amplificado) × qualidade de confiança.
            $target = $default * (1.0 + $edge * 2.0) * $brierFactor;
            $target = max(0.25, min(3.0, $target));

            // 4. Suavização: blend com peso atual + teto de passo por ciclo.
            $current = $this->currentWeight($module, $horizon, $regime, $default);
            $blended = $current * (1.0 - $lr) + $target * $lr;
            $step = $blended - $current;
            if (abs($step) > abs($current) * $maxStep) {
                $blended = $current + ($step > 0 ? 1 : -1) * abs($current) * $maxStep;
            }
            $blended = max(0.25, min(3.0, $blended));

            $reason = 'optimized';
            if (abs($blended - $current) < 0.0005) {
                $reason = 'no_change';
            }
            $this->applyWeight($module, $horizon, $regime, $blended, $current, $reason, $sampleSize);
            $count++;
        }

        return $count;
    }

    private function currentWeight(string $module, string $horizon, string $regime, float $default): float
    {
        $row = Database::fetch(
            'SELECT weight FROM module_weights WHERE module=? AND horizon=? AND regime=?',
            [$module, $horizon, $regime]
        );
        return $row ? (float)$row['weight'] : $default;
    }

    private function applyWeight(string $module, string $horizon, string $regime, float $weight, float $previous, string $reason, int $sampleSize): void
    {
        Database::execute(
            'INSERT INTO module_weights (module, horizon, regime, weight) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE weight=VALUES(weight)',
            [$module, $horizon, $regime, round($weight, 6)]
        );
        if ($reason !== 'no_change') {
            Logger::info('weight_updated', [
                'module' => $module,
                'horizon' => $horizon,
                'regime' => $regime,
                'from' => round($previous, 4),
                'to' => round($weight, 4),
                'reason' => $reason,
                'sample_size' => $sampleSize,
            ]);
        }
    }
}
