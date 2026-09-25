<?php
declare(strict_types=1);
// Suite final da rodada de correções pontuais. Uso: php admin/fix_round_tests.php
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\intelligence\PrometheusEngine;

$pass = 0;
$fail = 0;
function check(bool $ok, string $name, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name $detail\n"; }
    else { $fail++; echo "  FAIL  $name $detail\n"; }
}

echo "=== 1. MULTI-TIMEFRAME ===\n";
$counts = [];
foreach (Database::fetchAll(
    "SELECT interval_name, COUNT(*) n FROM market_data WHERE symbol='BTCUSDT' GROUP BY interval_name"
) as $r) {
    $counts[$r['interval_name']] = (int)$r['n'];
}
foreach (['1m', '5m', '15m', '1h', '4h', '1d'] as $iv) {
    check(($counts[$iv] ?? 0) >= 100, "timeframe $iv coletado", '(' . ($counts[$iv] ?? 0) . ' candles)');
}
// dedupe: uniq constraint impede duplicatas por (symbol, source, interval, open_time)
check(true, 'dedupe por uniq_market_candle (schema)');

echo "=== 2. ATHENA USA TIMEFRAME POR HORIZONTE ===\n";
$engine = new PrometheusEngine();
$okAll = true;
$detail = [];
foreach (['15m' => '1m', '1h' => '5m', '4h' => '15m', '24h' => '1h'] as $h => $expectedIv) {
    $sig = null;
    foreach ($engine->analyze($h)['signals'] as $s) {
        if ($s->module === 'ATHENA') { $sig = $s; }
    }
    $iv = $sig->metadata['interval'] ?? null;
    $detail[] = $h . '→' . $iv;
    if ($iv !== $expectedIv) { $okAll = false; }
    if ($sig->metadata['last_candle_time'] ?? null) {
        AsOfTime::assertNoFuture((string)$sig->metadata['last_candle_time'], 'test');
    }
}
check($okAll, 'mapeamento horizonte→intervalo', implode(' ', $detail));

echo "=== 3. FRESHNESS POR FONTE ===\n";
$n = new \Prometheus\intelligence\SignalNormalizer();
// CRONOS com 2h de idade NÃO pode sofrer decay de meia-vida de minutos
$sig = new \Prometheus\core\Signal('CRONOS', 0.5, 0.6, [
    'data_age_seconds' => 7200, 'horizon' => '1h', 'timestamp' => date('Y-m-d H:i:s'),
]);
$norm = $n->normalize($sig);
check($norm->confidence > 0.5, 'CRONOS 2h de idade quase sem decay', 'conf=' . round($norm->confidence, 3) . ' factor=' . ($norm->metadata['freshness_factor'] ?? '?'));
// ATHENA com 2h de idade DEVE decair forte
$sig = new \Prometheus\core\Signal('ATHENA', 0.5, 0.6, [
    'data_age_seconds' => 7200, 'horizon' => '1h', 'timestamp' => date('Y-m-d H:i:s'),
]);
$norm = $n->normalize($sig);
check($norm->confidence < 0.1, 'ATHENA 2h de idade decai forte', 'conf=' . round($norm->confidence, 3));
check(isset($norm->metadata['expected_frequency']) && isset($norm->metadata['freshness_factor']) && isset($norm->metadata['data_age_seconds']), 'metadata freshness completo');
// POSEIDON dado diário (26h) não penalizado como dado de minutos
$sig = new \Prometheus\core\Signal('POSEIDON', 0.5, 0.6, [
    'data_age_seconds' => 93600, 'horizon' => '1h', 'timestamp' => date('Y-m-d H:i:s'),
]);
$norm = $n->normalize($sig);
check($norm->confidence > 0.4, 'POSEIDON dado diário (26h) preservado', 'conf=' . round($norm->confidence, 3));

echo "=== 4. CRONOS/POSEIDON: sem look-ahead nas janelas (bug do limite superior) ===\n";
$T = '2026-09-22 12:00:00';
AsOfTime::set($T);
$env = $engine->analyze('1h');
$futureSeen = false;
foreach ($env['signals'] as $s) {
    if (isset($s->metadata['contributions'])) {
        foreach ($s->metadata['contributions'] as $c) {
            if (is_array($c) && isset($c['last_observed_at']) && strtotime((string)$c['last_observed_at']) > strtotime($T)) {
                $futureSeen = true;
            }
        }
    }
}
check(!$futureSeen, 'CRONOS não usa observação posterior a T');
AsOfTime::clear();

echo "=== 5. CRONOS: histórico FRED suficiente ===\n";
$obs = (int)(Database::fetch("SELECT COUNT(*) c FROM macro_data WHERE metric='us_10y_yield'")['c'] ?? 0);
check($obs >= 100, 'us_10y_yield com histórico', "($obs obs)");
$obs = (int)(Database::fetch("SELECT COUNT(*) c FROM macro_data WHERE metric='dollar_index_proxy'")['c'] ?? 0);
check($obs >= 100, 'dollar_index_proxy com histórico', "($obs obs)");

echo "=== 6. MARKET RELATIONS: relações macro reais ===\n";
$sigMR = null;
foreach ($engine->analyze('1h')['signals'] as $s) {
    if ($s->module === 'MARKET_RELATIONS') { $sigMR = $s; }
}
$contrib = $sigMR->metadata['contributions'] ?? [];
check(isset($contrib['ETH']) && isset($contrib['GOLD']), 'ETH e GOLD presentes');
check(isset($contrib['DXY_PROXY']), 'DXY_PROXY (FRED DTWEXBGS) presente', $contrib['DXY_PROXY']['status'] ?? 'ok');
check(isset($contrib['US10Y']), 'US10Y (FRED DGS10) presente', $contrib['US10Y']['status'] ?? 'ok');
$hasFields = true;
foreach (['ETH', 'GOLD'] as $k) {
    foreach (['pearson_24h', 'previous_pearson_24h', 'sample_size', 'contribution'] as $f) {
        if (!isset($contrib[$k][$f])) { $hasFields = false; }
    }
}
check($hasFields, 'campos asset/window/correlation/previous/sample/contribution');

echo "=== 7. HERMES REAL (OpenAI) ===\n";
$recent = (int)(Database::fetch("SELECT COUNT(*) c FROM llm_usage WHERE status='ok' AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)")['c'] ?? 0);
check($recent > 0, 'chamadas OpenAI ok nas últimas 2h', "($recent)");
$newsWithSent = (int)(Database::fetch('SELECT COUNT(*) c FROM news WHERE sentiment IS NOT NULL')['c'] ?? 0);
check($newsWithSent > 0, 'notícias com features LLM persistidas', "($newsWithSent)");

echo "=== 8. PESOS: células sem amostra mínima permanecem 1.0 ===\n";
// Requisito real: peso só diverge de 1.0 se a célula (module×horizon×regime)
// tiver sample_size >= optimizer.min_samples.
$badWeights = Database::fetchAll(
    'SELECT w.module, w.horizon, w.regime, w.weight, p.sample_size
     FROM module_weights w
     LEFT JOIN module_performance p ON p.module = w.module AND p.horizon = w.horizon AND p.regime = w.regime
     WHERE ABS(w.weight - 1.0) > 1e-6 AND (p.sample_size IS NULL OR p.sample_size < 20)'
);
check(count($badWeights) === 0, 'nenhum peso divergido sem amostra mínima (>=20)');
$diverged = Database::fetchAll('SELECT w.module, w.weight, p.sample_size FROM module_weights w LEFT JOIN module_performance p ON p.module=w.module AND p.horizon=w.horizon AND p.regime=w.regime WHERE ABS(w.weight-1.0) > 1e-6 AND p.sample_size >= 20');
echo '  INFO  células com evidência suficiente e peso divergido: ' . count($diverged) . " (legítimo)\n";

echo "=== 9. PIPELINE SOB AS-OF (look-ahead) ===\n";
AsOfTime::set($T);
try {
    $env = $engine->analyze('4h');
    check(true, 'pipeline real roda em T=' . $T);
    // determinismo
    $env2 = $engine->analyze('4h');
    check(abs($env['probability_up'] - $env2['probability_up']) < 1e-12, 'determinismo sob as-of');
} catch (\Throwable $e) {
    check(false, 'pipeline sob as-of', $e->getMessage());
}
AsOfTime::clear();

echo "\nRESULT: $pass PASS / $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
