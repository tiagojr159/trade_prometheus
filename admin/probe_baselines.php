<?php
declare(strict_types=1);
/**
 * LEGACY TOOL — EVALUATION SEMANTICS V1
 * This file is preserved only for historical/debugging purposes.
 * It MUST NOT be used as the official PROMETHEUS v2 benchmark.
 * Official directional semantics: core/DirectionPolicy.php
 * Official v2 baseline comparison: admin/alignment_baselines_v2.php
 * Official full-pipeline historical engine: evaluation/HistoricalPipelineBacktester.php
 */
// Item 7 — comparação de baselines no MESMO conjunto de timestamps,
// + diagnóstico SIDEWAYS (banda prevista vs banda real).
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Database;

echo "LEGACY MODE — Evaluation semantics v1 — DO NOT USE FOR CURRENT PERFORMANCE\n";

$rows = Database::fetchAll(
    'SELECT p.id, p.horizon, p.created_at, p.target_time, p.initial_price, p.predicted_direction,
            p.probability_up, p.confidence, p.regime,
            r.final_price, r.actual_direction, r.correct, r.return_pct
     FROM predictions p
     JOIN prediction_results r ON r.prediction_id = p.id
     WHERE p.symbol = ?
     ORDER BY p.created_at ASC',
    ['BTCUSDT']
);
if (!$rows) {
    echo "NO_EVALUATED\n";
    exit(1);
}

// ===== Tabela MODEL / N / ACCURACY / BRIER / LOG LOSS — mesmos timestamps =====
$models = [];
foreach (['PROMETHEUS', 'ALWAYS_UP', 'PREV_DIRECTION', 'RANDOM', 'TECH_SIMPLE'] as $m) {
    $models[$m] = ['n' => 0, 'correct' => 0, 'brier' => 0.0, 'logloss' => 0.0];
}
$n = count($rows);
$prevActual = null;
foreach ($rows as $i => $r) {
    $ret = (float)$r['return_pct'] / 100.0;
    $actual = (string)$r['actual_direction'];
    $pUp = (float)$r['probability_up'];
    $target = (float)($r['confidence'] ?? 0.0) > 0.0 ? $pUp : 0.5;
    // tech-simple: SMA5 vs SMA20 sobre closes 1m ≤ target (sem futuro: candle aberto <= target)
    $tech = 'UP';
    $closes = Database::fetchAll(
        'SELECT close_price FROM market_data WHERE symbol=? AND interval_name=? AND open_time <= ? ORDER BY open_time DESC LIMIT 20',
        [$r['id'] ? 'BTCUSDT' : 'BTCUSDT', prometheus_config('collector.market_interval', '1m'), $r['target_time']]
    );
    if (count($closes) >= 20) {
        $sma = fn($w) => array_sum(array_slice(array_map('floatval', array_column($closes, 'close_price')), 0, $w)) / $w;
        $tech = $sma(5) >= $sma(20) ? 'UP' : 'DOWN';
    }
    $preds = [
        'PROMETHEUS' => $r['predicted_direction'],
        'ALWAYS_UP' => 'UP',
        'PREV_DIRECTION' => $prevActual ?? $actual,
        'RANDOM' => (mt_rand() / mt_getrandmax()) < 0.5 ? 'UP' : 'DOWN',
        'TECH_SIMPLE' => $tech,
    ];
    foreach ($preds as $m => $pred) {
        $models[$m]['n']++;
        $models[$m]['correct'] += ($pred === $actual) ? 1 : 0;
        $pForUp = $m === 'PROMETHEUS' ? $pUp : ($pred === 'UP' ? 0.75 : 0.25);
        $models[$m]['brier'] += ($pForUp - ($actual === 'UP' ? 1 : 0)) ** 2;
        $lp = max(1e-9, $actual === 'UP' ? $pForUp : 1 - $pForUp);
        $models[$m]['logloss'] += -log($lp);
    }
    $prevActual = $actual;
}

echo "MODEL,N,ACCURACY,BRIER,LOGLOSS,PERIODO\n";
foreach ($models as $m => $s) {
    printf(
        "%s,%d,%.4f,%.4f,%.4f,%s ~ %s\n",
        $m, $s['n'], $s['correct'] / $s['n'], $s['brier'] / $s['n'], $s['logloss'] / $s['n'],
        $rows[0]['created_at'], $rows[$n - 1]['created_at']
    );
}

// ===== Diagnóstico SIDEWAYS =====
echo "\n=== SIDEWAYS DIAG ===\n";
echo "actual_sideways=" . count(array_filter($rows, fn($r) => $r['actual_direction'] === 'SIDEWAYS')) . "/$n\n";
echo "predicted_sideways=" . count(array_filter($rows, fn($r) => $r['predicted_direction'] === 'SIDEWAYS')) . "/$n\n";
$sw = array_filter($rows, fn($r) => $r['predicted_direction'] === 'SIDEWAYS' && $r['actual_direction'] !== 'SIDEWAYS');
echo "predSIDEWAYS_but_moved=" . count($sw) . "\n";
foreach (array_slice($sw, 0, 5, true) as $id => $r) {
    echo sprintf(
        "  #%d %s ret=%.4f%% actual=%s pred=%s pUp=%.4f conf=%.3f\n",
        $id, $r['horizon'], (float)$r['return_pct'], $r['actual_direction'], $r['predicted_direction'],
        (float)$r['probability_up'], (float)$r['confidence']
    );
}
// distribuição das magnitudes dos retornos reais
$rets = array_map(fn($r) => abs((float)$r['return_pct']), $rows);
sort($rets);
echo "abs_return_pct quartis: " . round($rets[(int)floor($n * .25)], 4) . ' / '
    . round($rets[(int)floor($n * .5)], 4) . ' / ' . round($rets[(int)floor($n * .75)], 4) . "\n";
