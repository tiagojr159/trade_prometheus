<?php
declare(strict_types=1);

namespace Prometheus\evaluation;

use Prometheus\core\Database;
use Prometheus\core\DirectionPolicy;
use Prometheus\core\TemporalAvailability;
use Prometheus\core\Logger;

/**
 * UTILITY BASELINE — simplified technical comparison; not the full pipeline.
 * It uses DirectionPolicy v2 and must not be cited as the official full-pipeline benchmark.
 * Official historical benchmark: evaluation/HistoricalPipelineBacktester.php
 *
 * Este backtester usa um MODELO TÉCNICO SIMPLIFICADO PRÓPRIO (não reproduz
 * os 6 módulos reais). O backtester OFICIAL que replica o pipeline completo
 * de produção (6 módulos → normalizer → regime as-of → pesos as-of →
 * ensemble → ProbabilityCalculator) é o Prometheus\evaluation\HistoricalPipelineBacktester.
 *
 * Mantido apenas como referência/baseline de comparação.
 *
 * WALK-FORWARD BACKTESTER (Item 19, versão simplificada original).
 *
 * Protocolo estrito para cada instante T da série:
 *  1. Recorta ONLY candles com open_time <= T (a janela de features nunca
 *     enxerga o futuro);
 *  2. Persiste uma previsão sintética para T+H com initial_price = close em T;
 *  3. AVANÇA o relógio virtual para T+H e busca o preço do vencimento
 *     (primeiro candle com open_time >= T+H) — só AGORA o futuro (relativo a T)
 *     é acessado, e exclusivamente para AVALIAR;
 *  4. Calcula acerto/retorno e registra em backtest_results (tabela própria,
 *     sem poluir predictions reais).
 *
 * Proibido (e estruturalmente impossível no código abaixo): indicadores,
 * normalização ou pesos calculados com dados posteriores a T.
 */
final class WalkForwardBacktester
{
    private const STEP_MINUTES = 60;   // avanço do relógio entre previsões
    private const MIN_CANDLES = 60;    // mínimo para gerar previsão

    public function run(string $symbol = 'BTCUSDT', ?string $from = null, ?string $to = null, int $horizonSeconds = 3600, int $maxPredictions = 200): array
    {
        $start = $from ?? date('Y-m-d H:i:s', strtotime('-7 days'));
        $end = $to ?? date('Y-m-d H:i:s');

        // Todos os candles do período (1m), em ordem cronológica.
        $candles = Database::fetchAll(
            'SELECT open_time, close_time, close_price, available_at, ingested_at, temporal_quality FROM market_data
             WHERE symbol = ? AND interval_name = ? AND open_time >= ? AND close_time <= ?
               AND ingested_at IS NOT NULL AND ' . \Prometheus\core\AsOfTime::sqlUtcUpperBound('ingested_at') . '
             ORDER BY open_time ASC',
            [$symbol, prometheus_config('collector.market_interval', '1m'), $start, $end]
        );
        $total = count($candles);
        if ($total < self::MIN_CANDLES + (int)($horizonSeconds / 60)) {
            return ['error' => 'insufficient_data', 'candles' => $total];
        }

        $index = self::MIN_CANDLES;
        $results = ['predictions' => 0, 'evaluated' => 0, 'correct' => 0, 'up_correct' => 0, 'up_total' => 0, 'down_correct' => 0, 'down_total' => 0];
        $equity = 0.0;
        $brierSum = 0.0;
        $brierN = 0;

        while ($index < $total - (int)($horizonSeconds / 60) && $results['predictions'] < $maxPredictions) {
            $t = $candles[$index];
            $tTime = date('Y-m-d H:i:s', strtotime((string)$t['ingested_at'] . ' UTC'));
            if (!TemporalAvailability::isEligible($tTime, $t['available_at'] !== null ? (string)$t['available_at'] : null, (string)$t['ingested_at'], $tTime, (string)$t['temporal_quality'])) {
                $index += (int)(self::STEP_MINUTES / 1);
                continue;
            }
            $initial = (float)$t['close_price'];

            // ---- 1. GERAÇÃO: somente dados <= T ----
            $signal = $this->generateSignalAt($candles, $index);
            // Probabilidade logística idêntica à do sistema real.
            $slope = (float)prometheus_config('prediction.probability_slope', 1.65);
            $pUp = 1 / (1 + exp(-$slope * $signal));
            $pUp = max(0.005, min(0.995, $pUp));
            // Mesma semântica v2 do sistema (DirectionPolicy) — o legado
            // também não pode mais gerar SIDEWAYS direcional nem avaliar em banda.
            $direction = DirectionPolicy::predictFromUp($pUp)['direction'];
            if ($direction === 'INDETERMINATE') {
                $index += (int)(self::STEP_MINUTES / 1);
                continue;
            }

            // ---- 2. Relógio virtual avança para T+H ----
            $targetTime = date('Y-m-d H:i:s', strtotime($tTime) + $horizonSeconds);
            $targetCandle = TargetPriceResolver::fromCandles($candles, $targetTime, $end);
            if ($targetCandle === null) {
                break;
            }
            $finalPrice = (float)$targetCandle['close_price'];

            // ---- 3. AVALIAÇÃO (único acesso ao "futuro", agora passado virtual) ----
            // Semântica v2 (DirectionPolicy): binária + FLAT explícito.
            $return = ($finalPrice - $initial) / max(1e-12, $initial);
            $actual = DirectionPolicy::actual($return);
            $correct = DirectionPolicy::hit($direction, $actual);
            if ($correct === null) {
                $index += (int)(self::STEP_MINUTES / 1);
                continue;
            }

            $b = DirectionPolicy::brier($pUp, $actual);
            if ($b !== null) {
                $brierSum += $b;
                $brierN++;
            }

            if ($direction === 'UP') { $results['up_total']++; $results['up_correct'] += $correct; }
            if ($direction === 'DOWN') { $results['down_total']++; $results['down_correct'] += $correct; }

            $equity += $direction === 'UP' ? $return : -$return;

            Database::execute(
                'INSERT INTO backtest_results (symbol, at_time, horizon, predicted_direction, probability_up, initial_price, final_price, actual_direction, correct, return_pct, evaluation_version, evaluated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 2, NOW())',
                [$symbol, $tTime, (string)round($horizonSeconds / 60) . 'm', $direction, $pUp, $initial, $finalPrice, $actual, $correct, $return * 100]
            );

            $results['predictions']++;
            $results['evaluated']++;
            $results['correct'] += $correct;
            $index += (int)(self::STEP_MINUTES / 1); // avança relógio 1h entre previsões
        }

        $results['accuracy'] = $results['evaluated'] > 0 ? round($results['correct'] / $results['evaluated'], 4) : null;
        $results['brier'] = $brierN > 0 ? round($brierSum / $brierN, 4) : null;
        $results['cum_return_pct'] = round($equity * 100, 4);
        $results['baseline_accuracy'] = 0.5;
        $results['edge_vs_baseline'] = $results['accuracy'] !== null ? round($results['accuracy'] - 0.5, 4) : null;

        Logger::info('walk_forward_completed', $results);
        return $results;
    }

    /**
     * Sinal técnico determinístico calculado EXCLUSIVAMENTE com candles
     * até o índice $index (inclusive) — mesma lógica de momento/reversão
     * da ATHENA, reimplementada localmente para não depender do banco
     * (que pode ter candles posteriores a T).
     */
    private function generateSignalAt(array $candles, int $index): float
    {
        $n = min($index + 1, 240);
        $slice = array_slice($candles, $index - $n + 1, $n);
        $closes = array_map(fn($c) => (float)$c['close_price'], $slice);

        $last = end($closes);
        $first = $closes[0];
        $momentum = ($last - $first) / max(1e-12, $first);

        // RSI simplificado de 14 períodos (sobre a janela).
        $gains = 0.0;
        $losses = 0.0;
        for ($i = 1; $i < count($closes); $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            if ($d > 0) { $gains += $d; } else { $losses -= $d; }
        }
        $rs = $losses > 0 ? $gains / $losses : 100.0;
        $rsi = 100 - 100 / (1 + $rs);
        $rsiScore = (50 - $rsi) / 50; // reversão

        // Combina tendência (momentum) e reversão, squash para [-1,1].
        return max(-1.0, min(1.0, $momentum * 60 * 0.7 + $rsiScore * 0.3));
    }
}
