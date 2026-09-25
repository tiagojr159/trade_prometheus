<?php
declare(strict_types=1);

namespace Prometheus\core;

/**
 * DIRECTION POLICY — definição ÚNICA e compartilhada do target direcional.
 *
 * Este componente é a FONTE ÚNICA das semânticas de:
 *  - predicted_direction (a partir de P(up)/P(down));
 *  - actual_direction    (a partir do retorno realizado);
 *  - directional_hit     (acerto direcional binário);
 *  - Brier score / Log Loss (sempre sobre P(up), nunca sobre classes).
 *
 * PRODUÇÃO (PredictionEvaluator) e BACKTEST (HistoricalPipelineBacktester)
 * usam ESTE componente — não podem discordar sobre o resultado da mesma
 * previsão, nem duplicar fórmulas.
 *
 * DEFINIÇÃO MATEMÁTICA DO TARGET (binário):
 *   r(t,H) = (P(t+H) - P(t)) / P(t)
 *   Y = UP   se r > 0
 *   Y = DOWN se r < 0
 *   Y = FLAT se r = 0 (retorno EXATAMENTE zero — nunca convertido
 *                     arbitrariamente em UP ou DOWN; excluído das métricas
 *                     direcionais e probabilísticas, contado à parte).
 *
 * PREVISÃO (binária, a partir da distribuição):
 *   P(up) > P(down) → UP
 *   P(down) > P(up) → DOWN
 *   P(up) = P(down) → INDETERMINATE (caso degenerado; excluído de hit/miss)
 *
 * SIDEWAYS NÃO É MAIS uma classe direcional. Baixa convicção é exposta
 * separadamente como edge = |P(up) - 0.5| = |P(up) - P(down)| / 2 —
 * P(up)=0.501 é UP com edge 0.001; P(up)=0.720 é UP com edge 0.220.
 * Regime de mercado (SIDEWAYS etc.) é conceito INDEPENDENTE e continua
 * não sendo confundido com direção.
 *
 * EVALUATION_VERSION: versão da semântica de avaliação. Resultados com
 * versão anterior (ternária, v1) NÃO são misturados com métricas v2.
 */
final class DirectionPolicy
{
    /** Tolerância numérica para igualdade exata (P(up)=P(down), r=0). */
    public const EPS = 1e-12;

    /** Versão da semântica de avaliação (v1 = ternária legada, v2 = binária). */
    public const EVALUATION_VERSION = 2;

    /**
     * Direção prevista a partir da distribuição de probabilidades.
     * @return array{direction: string, edge: float, margin: float}
     *         direction ∈ {UP, DOWN, INDETERMINATE}
     *         edge      = |P(up) - 0.5|           (força da convicção)
     *         margin    = |P(up) - P(down)| = 2×edge
     */
    public static function predict(float $pUp, float $pDown): array
    {
        $diff = $pUp - $pDown;
        if (abs($diff) < self::EPS) {
            return ['direction' => 'INDETERMINATE', 'edge' => 0.0, 'margin' => 0.0];
        }
        $direction = $diff > 0 ? 'UP' : 'DOWN';
        $edge = abs($pUp - 0.5);
        return ['direction' => $direction, 'edge' => $edge, 'margin' => abs($diff)];
    }

    /**
     * Conveniência: direção a partir de P(up) apenas (assumindo P(up)+P(down)=1).
     */
    public static function predictFromUp(float $pUp): array
    {
        return self::predict($pUp, 1.0 - $pUp);
    }

    /**
     * Direção REALIZADA a partir do retorno. FLAT somente para retorno
     * EXATAMENTE zero (tolerância EPS) — sem banda arbitrária.
     */
    public static function actual(float $realizedReturn): string
    {
        if (abs($realizedReturn) < self::EPS) {
            return 'FLAT';
        }
        return $realizedReturn > 0 ? 'UP' : 'DOWN';
    }

    /**
     * Acerto direcional: 1/0 SOMENTE quando previsto e realizado estão em
     * {UP, DOWN}. FLAT e INDETERMINATE → null (excluídos, não contam como erro).
     */
    public static function hit(string $predicted, string $actual): ?int
    {
        if ($predicted !== 'UP' && $predicted !== 'DOWN') {
            return null; // INDETERMINATE (ou legado): não é voto direcional
        }
        if ($actual !== 'UP' && $actual !== 'DOWN') {
            return null; // FLAT: mercado não definiu direção — não é erro nem acerto
        }
        return $predicted === $actual ? 1 : 0;
    }

    /**
     * Brier score sobre a PROBABILIDADE (não sobre a classe): (pUp - y)²,
     * com y=1 se realizado UP, y=0 se DOWN. FLAT → null (não pontua).
     */
    public static function brier(float $pUp, string $actual): ?float
    {
        if ($actual === 'UP') {
            return ($pUp - 1.0) ** 2;
        }
        if ($actual === 'DOWN') {
            return ($pUp - 0.0) ** 2;
        }
        return null; // FLAT
    }

    /**
     * Log Loss com clipping numérico seguro: eps <= p <= 1-eps.
     * FLAT → null (não pontua).
     */
    public static function logLoss(float $pUp, string $actual, float $eps = 1e-9): ?float
    {
        if ($actual !== 'UP' && $actual !== 'DOWN') {
            return null;
        }
        $p = max($eps, min(1.0 - $eps, $pUp));
        return $actual === 'UP' ? -log($p) : -log(1.0 - $p);
    }
}
