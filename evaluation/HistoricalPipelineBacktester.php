<?php
declare(strict_types=1);

namespace Prometheus\evaluation;

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\core\DirectionPolicy;
use Prometheus\core\Logger;
use Prometheus\core\TemporalAvailability;
use Prometheus\intelligence\PrometheusEngine;
use Prometheus\intelligence\RegimeDetector;

/**
 * HISTORICAL PIPELINE BACKTESTER ? reproduz o MOTOR REAL, n?o uma simplifica??o.
 *
 * Para cada instante T da s?rie:
 *   dados <= T ? 6 m?dulos reais (ATHENA..MARKET_RELATIONS) ? SignalNormalizer
 *   ? RegimeDetector (as-of T) ? pesos vigentes em T (module_weights.updated_at <= T)
 *   ? EnsembleEngine ? ProbabilityCalculator ? previs?o para T+H
 *   ? s? ENT?O o rel?gio avan?a e o resultado ? observado.
 *
 * Garantias anti-look-ahead:
 *  - AsOfTime::set(T) antes de cada previs?o: todas as queries de m?dulos,
 *    regime e pesos s?o cortadas em T (prova: assertNoFuture em cada componente);
 *  - a l?gica ? a MESMA de produ??o (PrometheusEngine + m?dulos reais) ? nada
 *    de reimplementa??o simplificada dentro do backtester;
 *  - o futuro s? ? tocado DEPOIS, para avaliar, com AsOfTime::clear().
 *
 * O WalkForwardBacktester (modelo t?cnico simplificado) ? preservado para
 * compara??o; este ? o backtester can?nico do motor.
 */
final class HistoricalPipelineBacktester
{
    /** Avan?o do rel?gio entre previs?es (minutos). */
    private const STEP_MINUTES = 60;
    /** M?nimo de candles antes do primeiro T. */
    private const MIN_CANDLES = 60;

    private PrometheusEngine $engine;

    public function __construct(?PrometheusEngine $engine = null)
    {
        $this->engine = $engine ?: new PrometheusEngine();
    }

    /**
     * Roda walk-forward para UM horizonte por chamada.
     * @param string[] $horizons horizontes suportados: 15m, 1h, 4h, 24h
     */
    public function run(string $symbol = 'BTCUSDT', ?string $from = null, ?string $to = null, array $horizons = ['15m', '1h', '4h', '24h'], int $maxPredictionsPerHorizon = 50): array
    {
        $allHorizons = prometheus_config('horizons', ['15m' => 900, '1h' => 3600, '4h' => 14400, '24h' => 86400]);
        $out = [];
        foreach ($horizons as $h) {
            if (!isset($allHorizons[$h])) {
                $out[$h] = ['error' => 'unknown_horizon'];
                continue;
            }
            $out[$h] = $this->runHorizon($symbol, $from, $to, $h, (int)$allHorizons[$h], $maxPredictionsPerHorizon);
        }
        return $out;
    }

    private function runHorizon(string $symbol, ?string $from, ?string $to, string $horizon, int $horizonSeconds, int $maxPredictions): array
    {
        $start = $from ?? date('Y-m-d H:i:s', strtotime('-7 days'));
        $end = $to ?? date('Y-m-d H:i:s');

        // ?ndice de candles (1m) do per?odo ? apenas para caminhar no tempo.
        $candles = Database::fetchAll(
            'SELECT open_time, close_time, close_price, available_at, ingested_at, temporal_quality FROM market_data
             WHERE symbol = ? AND interval_name = ? AND open_time >= ? AND close_time <= ?
               AND ingested_at IS NOT NULL AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . '
             ORDER BY open_time ASC',
            [$symbol, prometheus_config('collector.market_interval', '1m'), $start, $end]
        );
        $total = count($candles);
        $need = self::MIN_CANDLES + (int)($horizonSeconds / 60);
        if ($total < $need) {
            return ['error' => 'insufficient_data', 'candles' => $total, 'need' => $need];
        }

        $step = max(1, (int)round(self::STEP_MINUTES));
        $hSteps = (int)($horizonSeconds / 60);

        $results = [
            'horizon' => $horizon,
            'predictions' => 0, 'evaluated' => 0, 'correct' => 0,
            'up_total' => 0, 'up_correct' => 0, 'down_total' => 0, 'down_correct' => 0,
            'flat' => 0,
        ];
        $brierSum = 0.0;
        $brierN = 0;
        $logLossSum = 0.0;
        $equity = 0.0;
        $sample = null; // primeira previs?o completa, para evid?ncia/auditoria

        $index = self::MIN_CANDLES;
        while ($index < $total - $hSteps && $results['predictions'] < $maxPredictions) {
            // A prediction could only run after the closed candle was received.
            $tTime = date('Y-m-d H:i:s', strtotime((string)$candles[$index]['ingested_at'] . ' UTC'));
            if (!TemporalAvailability::isEligible(
                $tTime,
                $candles[$index]['available_at'] !== null ? (string)$candles[$index]['available_at'] : null,
                (string)$candles[$index]['ingested_at'],
                $tTime,
                (string)$candles[$index]['temporal_quality']
            )) {
                $index += $step;
                continue;
            }
            $initial = (float)$candles[$index]['close_price'];

            // ===== 1. GERA??O: mundo congelado em T =====
            AsOfTime::set($tTime);
            try {
                $analysis = $this->engine->analyze($horizon, $symbol);
            } catch (\RuntimeException $e) {
                // Viola??o de look-ahead (assertNoFuture) ? bug grave: aborta barulhento.
                AsOfTime::clear();
                throw $e;
            } catch (\Throwable $e) {
                // Falha de coleta/dado em T: registra e avan?a (n?o corrompe o backtest).
                Logger::warning('backtest_step_failed', ['t' => $tTime, 'message' => $e->getMessage()]);
                AsOfTime::clear();
                $index += $step;
                continue;
            }
            AsOfTime::clear(); // a partir daqui o "futuro" de T pode ser observado

            $pUp = (float)$analysis['probability_up'];
            // Dire??o prevista: MESMA sem?ntica da produ??o (DirectionPolicy ?
            // bin?ria; zero duplica??o de f?rmula).
            $direction = DirectionPolicy::predictFromUp($pUp)['direction'];
            // INDETERMINATE (P(up)=P(down) exato) n?o ? voto direcional: pula.
            if ($direction === 'INDETERMINATE') {
                AsOfTime::clear();
                $index += $step;
                continue;
            }

            // ===== 2. Rel?gio virtual avan?a para T+H =====
            $targetTime = date('Y-m-d H:i:s', strtotime($tTime) + $horizonSeconds);
            $targetCandle = TargetPriceResolver::fromCandles($candles, $targetTime, $end);
            if ($targetCandle === null) {
                break;
            }
            $finalPrice = (float)$targetCandle['close_price'];

            // ===== 3. AVALIA??O (?nico acesso ao futuro, agora passado virtual) =====
            // Mesma sem?ntica v2 da produ??o (DirectionPolicy): bin?ria + FLAT.
            $ret = ($finalPrice - $initial) / max(1e-12, $initial);
            $actual = DirectionPolicy::actual($ret);
            $correct = DirectionPolicy::hit($direction, $actual);

            // FLAT (retorno exatamente zero): sem voto direcional e sem pontua??o
            // probabil?stica ? contado ? parte, n?o como erro.
            if ($correct === null) {
                $results['flat'] = ($results['flat'] ?? 0) + 1;
                $index += $step;
                continue;
            }

            $b = DirectionPolicy::brier($pUp, $actual);
            if ($b !== null) {
                $brierSum += $b;
                $brierN++;
            }
            $ll = DirectionPolicy::logLoss($pUp, $actual);
            if ($ll !== null) {
                $logLossSum += $ll;
            }

            if ($direction === 'UP') { $results['up_total']++; $results['up_correct'] += $correct; }
            if ($direction === 'DOWN') { $results['down_total']++; $results['down_correct'] += $correct; }
            $equity += $direction === 'UP' ? $ret : -$ret;

            Database::execute(
                'INSERT INTO backtest_results
                    (symbol, at_time, horizon, predicted_direction, probability_up, initial_price, final_price, actual_direction, correct, return_pct, evaluation_version, evaluated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 2, NOW())',
                [$symbol, $tTime, $horizon, $direction, $pUp, $initial, $finalPrice, $actual, $correct, $ret * 100]
            );

            if ($sample === null) {
                $sample = [
                    'at_time' => $tTime,
                    'target_time' => $targetCandle['open_time'],
                    'initial_price' => $initial,
                    'final_price' => $finalPrice,
                    'actual_direction' => $actual,
                    'correct' => (bool)$correct,
                    'regime' => $analysis['regime'],
                    'signals' => $analysis['signals'],
                    'weights' => $analysis['weights'],
                    'contributions' => $analysis['contributions'],
                    'ensemble_signal' => $analysis['ensemble_signal'],
                    'confidence' => $analysis['confidence'],
                    'probability_up' => $pUp,
                    'probability_down' => $analysis['probability_down'],
                    'predicted_direction' => $direction,
                ];
            }

            $results['predictions']++;
            $results['evaluated']++;
            $results['correct'] += $correct;
            $index += $step;
        }

        $results['accuracy'] = $results['evaluated'] > 0 ? round($results['correct'] / $results['evaluated'], 4) : null;
        $results['brier'] = $brierN > 0 ? round($brierSum / $brierN, 4) : null;
        $results['logloss'] = $brierN > 0 ? round($logLossSum / $brierN, 4) : null;
        $results['evaluation_version'] = DirectionPolicy::EVALUATION_VERSION;
        $results['cum_return_pct'] = round($equity * 100, 4);
        $results['baseline_accuracy'] = 0.5;
        $results['edge_vs_baseline'] = $results['accuracy'] !== null ? round($results['accuracy'] - 0.5, 4) : null;
        $results['first_prediction_sample'] = $sample;

        Logger::info('historical_pipeline_backtest_completed', array_diff_key($results, ['first_prediction_sample' => 1]));
        return $results;
    }
}
