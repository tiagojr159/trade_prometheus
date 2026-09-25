<?php
declare(strict_types=1);
// Item 12 — baselines sob a semântica v2, MESMO dataset para todos os modelos:
// mesmos timestamps, initial_prices, target_prices, horizontes e target UP/DOWN.
// RANDOM usa seed determinística (mt_srand fixo) para reprodutibilidade.
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Database;
use Prometheus\core\DirectionPolicy;
use Prometheus\core\AsOfTime;

mt_srand(42); // seed determinística

$rows = Database::fetchAll(
    'SELECT p.id, p.horizon, p.created_at, p.target_time, p.initial_price, p.predicted_direction,
            p.probability_up, p.confidence,
            r.final_price, r.actual_direction, r.directional_hit, r.evaluation_version
     FROM predictions p
     JOIN prediction_results r ON r.prediction_id = p.id
     WHERE r.evaluation_version = 2 AND r.actual_direction IN ("UP","DOWN")
     ORDER BY p.created_at ASC'
);
if (!$rows) {
    echo "NO_V2_RESULTS\n";
    exit(1);
}

// Comparação JUSTA: todos os modelos avaliados no MESMO subconjunto —
// previsões que o PROMETHEUS emitiu voto direcional binário (UP/DOWN).
// O where acima garante isso (29 primeiras previsões direcionais avaliadas).
$models = [];
foreach (['PROMETHEUS', 'ALWAYS_UP', 'PREV_DIRECTION', 'RANDOM_50_50', 'TECH_SIMPLE'] as $m) {
    $models[$m] = ['n' => 0, 'correct' => 0, 'brier' => 0.0, 'logloss' => 0.0];
}
$prevActual = null;
$n = count($rows);

foreach ($rows as $r) {
    $initial = (float)$r['initial_price'];
    $final = (float)$r['final_price'];
    $ret = ($final - $initial) / max(1e-12, $initial);
    // The persisted v2 actual_direction is the official outcome for this sample.
    $actual = (string)$r['actual_direction'];
    $y = $actual === 'UP' ? 1.0 : 0.0;

    // TECH_SIMPLE: SMA5 vs SMA20 sobre candles 1m <= target_time (sem futuro)
    AsOfTime::set((string)$r['created_at']);
    $closes = array_map('floatval', array_column(
        Database::fetchAll(
            'SELECT close_price FROM market_data WHERE symbol=? AND interval_name=? AND close_time <= ? AND ingested_at IS NOT NULL AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . ' AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at)) ORDER BY open_time DESC LIMIT 20',
            ['BTCUSDT', prometheus_config('collector.market_interval', '1m'), $r['created_at']]
        ),
        'close_price'
    ));
    AsOfTime::clear();
    $tech = 'UP';
    if (count($closes) >= 20) {
        $sma5 = array_sum(array_slice($closes, 0, 5)) / 5;
        $sma20 = array_sum(array_slice($closes, 0, 20)) / 20;
        $tech = $sma5 >= $sma20 ? 'UP' : 'DOWN';
    }

    // Direção do PROMETHEUS: re-derivada da P(up) persistida (semântica v2,
    // mesma para registros antigos SIDEWAYS — re-derivação determinística da
    // informação que já existia em created_at).
    $promDir = DirectionPolicy::predictFromUp((float)$r['probability_up'])['direction'];
    if ($promDir === 'INDETERMINATE') {
        continue; // degenerado: nenhum modelo pontua neste timestamp
    }
    $preds = [
        'PROMETHEUS' => [$promDir, (float)$r['probability_up']],
        'ALWAYS_UP' => ['UP', 0.5],               // probabilidade não informacional
        'PREV_DIRECTION' => [$prevActual ?? $actual, 0.5],
        'RANDOM_50_50' => [mt_rand() / mt_getrandmax() < 0.5 ? 'UP' : 'DOWN', 0.5],
        'TECH_SIMPLE' => [$tech, 0.5],
    ];

    foreach ($preds as $m => [$pred, $pUp]) {
        $hit = DirectionPolicy::hit($pred, $actual);
        if ($hit === null) {
            continue; // nunca deve ocorrer (todos UP/DOWN aqui)
        }
        $models[$m]['n']++;
        $models[$m]['correct'] += $hit;
        $models[$m]['brier'] += DirectionPolicy::brier($pUp, $actual);
        $models[$m]['logloss'] += DirectionPolicy::logLoss($pUp, $actual);
    }
    $prevActual = $actual;
}

echo "CURRENT V2 ALIGNMENT REPORT — not a predictive validation claim\n";
echo "CURRENT V2 PROBE — lineage filtered; not predictive validation\n";
echo "MODEL,N,UP_PRED,DOWN_PRED,ACCURACY,BRIER,LOGLOSS,PERIODO\n";
$upPred = ['PROMETHEUS' => 0, 'ALWAYS_UP' => $n, 'PREV_DIRECTION' => 0, 'RANDOM_50_50' => 0, 'TECH_SIMPLE' => 0];
// recontagem de up_pred por modelo
$prevActual = null;
mt_srand(42);
foreach ($rows as $r) {
    AsOfTime::set((string)$r['created_at']);
    $closes = array_map('floatval', array_column(
        Database::fetchAll('SELECT close_price FROM market_data WHERE symbol=? AND interval_name=? AND close_time <= ? AND ingested_at IS NOT NULL AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . ' AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at)) ORDER BY open_time DESC LIMIT 20',
            ['BTCUSDT', prometheus_config('collector.market_interval', '1m'), $r['created_at']]),
        'close_price'));
    AsOfTime::clear();
    $tech = 'UP';
    if (count($closes) >= 20) {
        $tech = (array_sum(array_slice($closes, 0, 5)) / 5) >= (array_sum(array_slice($closes, 0, 20)) / 20) ? 'UP' : 'DOWN';
    }
    $all = [
        'PROMETHEUS' => \Prometheus\core\DirectionPolicy::predictFromUp((float)$r['probability_up'])['direction'],
        'ALWAYS_UP' => 'UP',
        'PREV_DIRECTION' => $prevActual ?? $r['actual_direction'],
        'RANDOM_50_50' => mt_rand() / mt_getrandmax() < 0.5 ? 'UP' : 'DOWN',
        'TECH_SIMPLE' => $tech,
    ];
    foreach ($all as $m => $d) {
        if ($d === 'UP') $upPred[$m]++;
    }
    $prevActual = $r['actual_direction'];
}

foreach ($models as $m => $s) {
    if ($s['n'] === 0) { echo "$m,0,,,,,\n"; continue; }
    printf(
        "%s,%d,%d,%d,%.4f,%.4f,%.4f,%s ~ %s\n",
        $m, $s['n'], $upPred[$m], $s['n'] - $upPred[$m],
        $s['correct'] / $s['n'], $s['brier'] / $s['n'], $s['logloss'] / $s['n'],
        $rows[0]['created_at'], $rows[$n - 1]['created_at']
    );
}
echo "\n(sem SIDEWAYS: target binário v2; FLAT excluído por definição; seed RANDOM = 42)\n";
