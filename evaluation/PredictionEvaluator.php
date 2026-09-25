<?php
declare(strict_types=1);

namespace Prometheus\evaluation;

use Prometheus\core\Database;
use Prometheus\core\DirectionPolicy;
use Prometheus\core\Logger;

/**
 * PREDICTION EVALUATOR (Item 18).
 *
 * Contrato temporal: toda previsÃ£o Ã© persistida ANTES do resultado existir
 * (garantido pelo PredictionService: created_at < target_time sempre, com
 * initial_price congelado no momento da criaÃ§Ã£o). Este avaliador sÃ³ entra
 * em cena DEPOIS do vencimento (target_time <= NOW()) e:
 *  - busca o primeiro candle com open_time >= target_time (preÃ§o do vencimento);
 *  - registra final_price, actual_direction (UP/DOWN/FLAT), directional_hit,
 *    realized_return, error_score (Brier sobre P(up)) e evaluation_version=2;
 *  - NUNCA altera a linha original da previsÃ£o (created_at, target_time,
 *    initial_price, predicted_direction, probability, signals, weights,
 *    regime ficam intactos â€” resultado vai para prediction_results);
 *  - previsÃµes vencidas sem candle disponÃ­vel ficam pendentes (nÃ£o avaliadas
 *    com dados inventados), com contagem registrada nos logs.
 *
 * POLÃTICA DE PREÃ‡O (documentaÃ§Ã£o do item 6):
 *   initial_price = close do candle 1m disponÃ­vel no momento da criaÃ§Ã£o
 *                   (persistido em predictions.initial_price â€” nunca reavaliado);
 *   target_price  = close do PRIMEIRO candle 1m com open_time >= target_time
 *                   e open_time <= NOW (candle fechado; nunca um candle
 *                   posterior "nearest"; mesmo intervalo base em produÃ§Ã£o e
 *                   backtest â€” ver DirectionPolicy/HistoricalPipelineBacktester).
 */
final class PredictionEvaluator
{
    public function evaluateDue(): int
    {
        $predictions = Database::fetchAll(
            'SELECT p.* FROM predictions p
             LEFT JOIN prediction_results r ON r.prediction_id = p.id
             WHERE r.id IS NULL AND p.target_time <= NOW()
             ORDER BY p.target_time ASC
             LIMIT 200'
        );
        $count = 0;
        $unavailable = 0;

        foreach ($predictions as $p) {
            // PreÃ§o do vencimento: primeiro candle FECHADO com open_time >= target_time.
            // (open_time >= target_time, nunca < â€” sem usar candle anterior ao vencimento
            //  e sem usar candle que ainda nÃ£o existe). FILTRO EXPLÃCITO do intervalo
            // base: com mÃºltiplos timeframes no banco, sem o filtro o vencimento
            // poderia usar um candle 4h/1d aberto depois do target (misalignment).
            $final = TargetPriceResolver::resolve(
                (string)$p['symbol'], (string)$p['target_time'], date('Y-m-d H:i:s'),
                (string)prometheus_config('collector.market_interval', '1m')
            );
            if (!$final) {
                $unavailable++;
                continue; // sem dado ainda â€” fica pendente, nÃ£o inventa resultado
            }

            $initial = (float)$p['initial_price'];
            $finalPrice = (float)$final['close_price'];
            $realizedReturn = ($finalPrice - $initial) / max(1e-12, $initial);

            // SemÃ¢ntica v2 (DirectionPolicy â€” Ãºnica fonte): target BINÃRIO.
            // return > 0 â†’ UP; < 0 â†’ DOWN; = 0 (exato) â†’ FLAT (nÃ£o Ã© erro nem acerto).
            $actual = DirectionPolicy::actual($realizedReturn);
            $hit = DirectionPolicy::hit($p['predicted_direction'], $actual);
            $brier = DirectionPolicy::brier((float)$p['probability_up'], $actual);

            Database::execute(
                'INSERT INTO prediction_results (prediction_id, final_price, actual_direction, correct, return_pct, error_score, directional_hit, realized_return, evaluation_version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE final_price=VALUES(final_price), actual_direction=VALUES(actual_direction), correct=VALUES(correct), return_pct=VALUES(return_pct), error_score=VALUES(error_score), directional_hit=VALUES(directional_hit), realized_return=VALUES(realized_return), evaluation_version=VALUES(evaluation_version)',
                [$p['id'], $finalPrice, $actual, $hit, $realizedReturn * 100, $brier, $hit, $realizedReturn, DirectionPolicy::EVALUATION_VERSION]
            );

            // MÃ©tricas por mÃ³dulo: FLAT nÃ£o entra (nÃ£o define direÃ§Ã£o real).
            if ($hit !== null) {
                (new MetricsCalculator())->updateFromPrediction($p, $actual, $hit);
            }
            $count++;
        }

        if ($unavailable > 0) {
            Logger::info('evaluation_price_unavailable', ['pending' => $unavailable]);
        }
        if ($count > 0) {
            (new WeightOptimizer())->optimize();
        }
        return $count;
    }
}
