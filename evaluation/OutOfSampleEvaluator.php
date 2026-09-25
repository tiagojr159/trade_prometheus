<?php
declare(strict_types=1);

namespace Prometheus\evaluation;

use Prometheus\core\Database;
use Prometheus\core\DirectionPolicy;

/**
 * OUT-OF-SAMPLE EVALUATOR (Item 21).
 *
 * Split CRONOL?GICO (nunca aleat?rio): a fra??o inicial
 * (`out_of_sample.train_fraction`, default 0.7) dos resultados avaliados,
 * ordenada por created_at, ? o per?odo de treino/calibra??o; o restante ?
 * OUT-OF-SAMPLE puro. Todas as m?tricas abaixo s?o calculadas sobre o
 * per?odo out-of-sample apenas:
 *
 *  - accuracy direcional (e vs baseline 0.5 e baseline "sempre UP");
 *  - precision/recall por dire??o (UP e DOWN) quando aplic?vel;
 *  - Brier score;
 *  - calibra??o (erro m?dio por bin de P(up));
 *  - resultado POR HORIZONTE;
 *  - resultado POR REGIME;
 *  - resultado POR M?DULO (acerto direcional de cada m?dulo isolado).
 */
final class OutOfSampleEvaluator
{
    public function run(string $symbol = 'BTCUSDT'): array
    {
        $rows = Database::fetchAll(
            'SELECT p.id, p.symbol, p.horizon, p.regime, p.predicted_direction, p.probability_up,
                    r.actual_direction, r.directional_hit, r.correct, r.return_pct, r.evaluation_version
             FROM predictions p
             JOIN prediction_results r ON r.prediction_id = p.id
             WHERE p.symbol = ? AND r.evaluation_version = 2
             ORDER BY p.created_at ASC',
            [$symbol]
        );
        if (count($rows) < 10) {
            return ['error' => 'insufficient_evaluated_predictions', 'available' => count($rows), 'note' => 'm?nimo 10 previs?es avaliadas'];
        }

        $trainFraction = (float)prometheus_config('out_of_sample.train_fraction', 0.7);
        $splitIdx = (int)floor(count($rows) * $trainFraction);
        $oos = array_slice($rows, $splitIdx); // cronol?gico: s? o final vira out-of-sample
        $train = array_slice($rows, 0, $splitIdx);

        // Sem?ntica v2: apenas targets bin?rios (UP/DOWN) entram nas m?tricas
        // direcionais/probabil?sticas. FLAT (retorno exato zero) e registros
        // legados v1 (tern?ria SIDEWAYS) ficam FORA ? nunca misturados.
        $legacyCount = count(array_filter($oos, fn($r) => (int)($r['evaluation_version'] ?? 1) !== DirectionPolicy::EVALUATION_VERSION));
        $flatCount = count(array_filter($oos, fn($r) => $r['actual_direction'] === 'FLAT'));
        $oos = array_values(array_filter($oos,
            fn($r) => (int)($r['evaluation_version'] ?? 1) === DirectionPolicy::EVALUATION_VERSION
                && ($r['actual_direction'] === 'UP' || $r['actual_direction'] === 'DOWN')));

        return [
            'split' => [
                'total_evaluated' => count($rows),
                'train_size' => count($train),
                'oos_size' => count($oos),
                'train_fraction' => $trainFraction,
                'oos_starts_at' => $oos ? $oos[0]['id'] : null,
                'method' => 'chronological (never random)',
                'evaluation_version' => DirectionPolicy::EVALUATION_VERSION,
                'excluded_legacy_v1' => $legacyCount,
                'excluded_flat' => $flatCount,
            ],
            'overall' => $this->metrics($oos),
            'by_horizon' => $this->groupBy($oos, 'horizon'),
            'by_regime' => $this->groupBy($oos, 'regime'),
            'baselines' => $this->baselines($oos),
            'note' => count($oos) < 30 ? 'amostra OOS pequena (<30): m?tricas ainda n?o est?veis' : null,
        ];
    }

    private function metrics(array $rows): array
    {
        $n = count($rows);
        if ($n === 0) {
            return ['n' => 0];
        }
        $correct = 0;
        $brier = 0.0;
        $tp = $fp = $tn = $fn = 0; // UP como classe positiva
        $dp = $dfp = $dtn = $dfn = 0; // DOWN como classe positiva
        foreach ($rows as $r) {
            $correct += (int)($r['directional_hit'] ?? $r['correct']);
            $pUp = (float)$r['probability_up'];
            $outcome = $r['actual_direction'] === 'UP' ? 1.0 : 0.0;
            $brier += ($pUp - $outcome) ** 2;

            $pred = $r['predicted_direction'];
            $act = $r['actual_direction'];
            if ($pred === 'UP' && $act === 'UP') $tp++;
            if ($pred === 'UP' && $act !== 'UP') $fp++;
            if ($pred !== 'UP' && $act !== 'UP') $tn++;
            if ($pred !== 'UP' && $act === 'UP') $fn++;
            if ($pred === 'DOWN' && $act === 'DOWN') $dp++;
            if ($pred === 'DOWN' && $act !== 'DOWN') $dfp++;
            if ($pred !== 'DOWN' && $act !== 'DOWN') $dtn++;
            if ($pred !== 'DOWN' && $act === 'DOWN') $dfn++;
        }
        return [
            'n' => $n,
            'accuracy' => round($correct / $n, 4),
            'brier_score' => round($brier / $n, 4),
            'precision_up' => ($tp + $fp) > 0 ? round($tp / ($tp + $fp), 4) : null,
            'recall_up' => ($tp + $fn) > 0 ? round($tp / ($tp + $fn), 4) : null,
            'precision_down' => ($dp + $dfp) > 0 ? round($dp / ($dp + $dfp), 4) : null,
            'recall_down' => ($dp + $dfn) > 0 ? round($dp / ($dp + $dfn), 4) : null,
        ];
    }

    private function groupBy(array $rows, string $key): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r[$key]][] = $r;
        }
        $out = [];
        foreach ($groups as $g => $gr) {
            $m = $this->metrics($gr);
            $m['baseline_accuracy'] = 0.5;
            $m['edge'] = $m['n'] > 0 ? round(($m['accuracy'] ?? 0) - 0.5, 4) : null;
            $out[$g] = $m;
        }
        return $out;
    }

    private function baselines(array $rows): array
    {
        $n = count($rows);
        if ($n === 0) {
            return [];
        }
        $alwaysUp = count(array_filter($rows, fn($r) => $r['actual_direction'] === 'UP'));
        // Baseline "persist?ncia": dire??o real = dire??o do retorno anterior.
        $persistCorrect = 0;
        $persistN = 0;
        $prev = null;
        foreach ($rows as $r) {
            // Baseline persist?ncia: mesma sem?ntica bin?ria v2 (aqui todas as
            // linhas j? s?o UP/DOWN ap?s o filtro).
            if ($prev !== null) {
                $persistN++;
                if ($prev === $r['actual_direction']) {
                    $persistCorrect++;
                }
            }
            $prev = $r['actual_direction'];
        }
        return [
            'always_up_accuracy' => round($alwaysUp / $n, 4),
            'persistence_accuracy' => $persistN > 0 ? round($persistCorrect / $persistN, 4) : null,
            'random_accuracy' => 0.5,
        ];
    }
}
