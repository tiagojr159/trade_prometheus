<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Database;
use Prometheus\core\DirectionPolicy;
use Prometheus\core\AsOfTime;

mt_srand(42);
echo "CURRENT V2 BASELINE COMPARISON — not predictive validation\n";
$rows = Database::fetchAll(
    'SELECT p.id, p.horizon, p.created_at, p.initial_price, p.probability_up,
            r.final_price, r.actual_direction, r.evaluation_version
     FROM predictions p JOIN prediction_results r ON r.prediction_id=p.id
     WHERE p.symbol=? AND r.evaluation_version=2 AND r.actual_direction IN ("UP","DOWN")
     ORDER BY p.created_at, p.id',
    [prometheus_config('default_symbol', 'BTCUSDT')]
);
$metrics = [];
$horizonCounts = [];
foreach ($rows as $r) {
    $time = (string)$r['created_at'];
    AsOfTime::set($time);
    $candles = Database::fetchAll(
        'SELECT close_time, close_price FROM market_data WHERE symbol=? AND interval_name=? AND close_time<=? AND ingested_at IS NOT NULL AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . ' AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at)) ORDER BY open_time DESC LIMIT 20',
        ['BTCUSDT', prometheus_config('collector.market_interval', '1m'), $time]
    );
    AsOfTime::clear();
    if (count($candles) < 2) continue;
    $latest = (float)$candles[0]['close_price'];
    $prior = (float)$candles[1]['close_price'];
    $previousDirection = $latest >= $prior ? 'UP' : 'DOWN';
    $closes = array_map(static fn($c) => (float)$c['close_price'], $candles);
    $technical = count($closes) >= 20
        ? (array_sum(array_slice($closes, 0, 5)) / 5 >= array_sum($closes) / 20 ? 'UP' : 'DOWN')
        : $previousDirection;
    $prometheus = DirectionPolicy::predictFromUp((float)$r['probability_up'])['direction'];
    if (!in_array($prometheus, ['UP', 'DOWN'], true)) continue;
    $actual = (string)$r['actual_direction'];
    $random = mt_rand() / mt_getrandmax() < 0.5 ? 'UP' : 'DOWN';
    $predictions = [
        'PROMETHEUS' => [$prometheus, (float)$r['probability_up']],
        'ALWAYS_UP' => ['UP', 1.0],
        'PREV_DIRECTION' => [$previousDirection, 0.5],
        'RANDOM_50_50' => [$random, 0.5],
        'TECH_SIMPLE' => [$technical, 0.5],
    ];
    $horizonCounts[$r['horizon']] = ($horizonCounts[$r['horizon']] ?? 0) + 1;
    foreach ($predictions as $model => [$direction, $pUp]) {
        $keyset = [$r['horizon'], 'TOTAL'];
        foreach ($keyset as $key) {
            $metrics[$key][$model]['n'] = ($metrics[$key][$model]['n'] ?? 0) + 1;
            $hit = DirectionPolicy::hit($direction, $actual);
            if ($hit === null) continue;
            $metrics[$key][$model]['hits'] = ($metrics[$key][$model]['hits'] ?? 0) + $hit;
            $metrics[$key][$model]['brier'] = ($metrics[$key][$model]['brier'] ?? 0.0) + DirectionPolicy::brier($pUp, $actual);
            $metrics[$key][$model]['logloss'] = ($metrics[$key][$model]['logloss'] ?? 0.0) + DirectionPolicy::logLoss($pUp, $actual);
        }
    }
}
foreach (['15m','1h','4h','24h','TOTAL'] as $horizon) {
    echo "HORIZON {$horizon}:" . PHP_EOL;
    printf("MODEL,N,ACCURACY,BRIER,LOGLOSS\n");
    foreach (['PROMETHEUS','ALWAYS_UP','PREV_DIRECTION','RANDOM_50_50','TECH_SIMPLE'] as $model) {
        $m = $metrics[$horizon][$model] ?? null;
        if (!$m || !$m['n']) { echo "$model,0,,,,\n"; continue; }
        printf("%s,%d,%.4f,%.4f,%.4f\n", $model,$m['n'],$m['hits']/$m['n'],$m['brier']/$m['n'],$m['logloss']/$m['n']);
    }
}
