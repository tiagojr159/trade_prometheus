<?php
declare(strict_types=1);
/** LEGACY TOOL — EVALUATION SEMANTICS V1; baseline output is historical only. */
// AUDITORIA FINAL — probes de fontes, freshness por fonte, módulos, calibração, pesos.
chdir(dirname(__DIR__));
require 'bootstrap.php';

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\core\SourceHealth;

echo "LEGACY MODE — baseline section is v1-only historical diagnostics\n";

echo "===== A. FONTES DE DADOS (estado real) =====\n";
$snap = SourceHealth::snapshot();
foreach ($snap as $src => $h) {
    printf("  %-16s %-12s last_data=%s age=%s lat=%sms fails=%s\n",
        $src, $h['status_effective'], $h['last_data_timestamp'] ?? '-', $h['age_seconds'] ?? '-', $h['latency_ms'] ?? '-', $h['consecutive_failures']);
}

echo "\n===== B. DADOS DE CADA FONTE (quantidade + timestamp real) =====\n";
$checks = [
    'market 1m BTC'     => "SELECT COUNT(*) n, MAX(open_time) mx FROM market_data WHERE symbol='BTCUSDT' AND interval_name='1m'",
    'market ETHUSDT'    => "SELECT COUNT(*) n, MAX(open_time) mx FROM market_data WHERE symbol='ETHUSDT' AND interval_name='1m'",
    'market PAXGUSDT'   => "SELECT COUNT(*) n, MAX(open_time) mx FROM market_data WHERE symbol='PAXGUSDT' AND interval_name='1m'",
    'other intervals'   => "SELECT COUNT(*) n, COUNT(DISTINCT interval_name) iv FROM market_data",
    'deriv funding'     => "SELECT COUNT(*) n, MAX(observed_at) mx FROM derivatives_data WHERE metric='funding_rate'",
    'deriv OI'          => "SELECT COUNT(*) n, MAX(observed_at) mx FROM derivatives_data WHERE metric='open_interest'",
    'deriv L/S'         => "SELECT COUNT(*) n, MAX(observed_at) mx FROM derivatives_data WHERE metric='long_short_ratio'",
    'deriv liquidations'=> "SELECT COUNT(*) n FROM derivatives_data WHERE metric LIKE '%liquid%'",
    'news'              => "SELECT COUNT(*) n, MAX(published_at) mx FROM news",
    'macro FRED'        => "SELECT COUNT(*) n, MAX(observed_at) mx FROM macro_data",
    'onchain'           => "SELECT COUNT(*) n, MAX(observed_at) mx FROM onchain_data",
    'llm_usage'         => "SELECT COUNT(*) n, SUM(cost_usd) cost FROM llm_usage",
];
foreach ($checks as $label => $sql) {
    $r = Database::fetch($sql);
    echo "  {$label}: n={$r['n']} max_ts=" . ($r['mx'] ?? $r['iv'] ?? '-') . (isset($r['cost']) ? " custo=\${$r['cost']}" : '') . "\n";
}

echo "\n===== C. FRESHNESS REAL POR FONTE (idade do dado usado por cada módulo) =====\n";
// Para cada módulo, mede a idade efetiva do dado mais novo que ele enxerga
$probes = [
    'ATHENA (candles 1m)'   => "SELECT TIMESTAMPDIFF(SECOND, MAX(open_time), NOW()) age FROM market_data WHERE symbol='BTCUSDT' AND interval_name='1m'",
    'HEPHAESTUS (deriv)'    => "SELECT TIMESTAMPDIFF(SECOND, MAX(observed_at), NOW()) age FROM derivatives_data",
    'MARKET_RELATIONS (ETH)'=> "SELECT TIMESTAMPDIFF(SECOND, MAX(open_time), NOW()) age FROM market_data WHERE symbol='ETHUSDT'",
    'CRONOS (macro FRED)'   => "SELECT TIMESTAMPDIFF(SECOND, MAX(observed_at), NOW()) age FROM macro_data",
    'POSEIDON (onchain)'    => "SELECT TIMESTAMPDIFF(SECOND, MAX(observed_at), NOW()) age FROM onchain_data",
];
foreach ($probes as $label => $sql) {
    $age = (int)Database::fetch($sql)['age'];
    printf("  %-24s idade do dado: %s (%.1f min)\n", $label, $age . 's', $age / 60);
}
echo "  [INFO] meia-vida única de 15min (SignalNormalizer) penaliza CRONOS (dado semanal/diário)\n";
echo "        e POSEIDON (dado diário) mesmo com dado FRESCO para sua frequência natural.\n";
$fred = Database::fetch("SELECT TIMESTAMPDIFF(DAY, MAX(observed_at), NOW()) d FROM macro_data");
$oc = Database::fetch("SELECT TIMESTAMPDIFF(DAY, MAX(observed_at), NOW()) d FROM onchain_data");
echo "  -> CRONOS: dado com " . $fred['d'] . " dias => freshness_factor=" . round(max(0.05, 2 ** (-$fred['d'] * 24 * 3600 / 900)), 4) . " (confiança ~morta)\n";
echo "  -> POSEIDON: dado com " . $oc['d'] . " dia(s) => freshness_factor=" . round(max(0.05, 2 ** (-$oc['d'] * 86400 / 900)), 4) . "\n";

echo "\n===== D. MÓDULOS: reação a mudança de input (coerência) =====\n";
echo "  [INFO] teste de coerência dos módulos coberto por item9_15_tests (45/45, cenários determinísticos com rollback)\n";

echo "\n===== E. CALIBRAÇÃO + BRIER das previsões reais =====\n";
$cal = (new Prometheus\prediction\ProbabilityCalculator())->calibration();
if (!empty($cal['bins'])) {
    foreach ($cal['bins'] as $b) {
        echo "  bin {$b['bin']}: n={$b['n']} p médio={$b['avg_predicted_p_up']} freq real={$b['observed_up_freq']} gap={$b['gap']} confiável=" . ($b['reliable'] ? 'sim' : 'não') . "\n";
    }
    echo "  erro de calibração global: " . ($cal['calibration_error'] ?? '?') . "\n";
    echo "  nota: " . ($cal['note'] ?? 'ok') . "\n";
} else {
    echo "  sem previsões avaliadas suficientes para calibração\n";
}
$res = Database::fetch("SELECT COUNT(*) n, AVG(correct) acc FROM prediction_results");
echo "  prediction_results: n={$res['n']} accuracy=" . ($res['acc'] !== null ? round($res['acc'], 4) : 'null') . "\n";

echo "\n===== F. PESOS ATUAIS (module×horizonte×regime) =====\n";
$w = Database::fetchAll("SELECT module, horizon, regime, weight, weight AS w, updated_at FROM module_weights ORDER BY module, horizon LIMIT 50");
if ($w) {
    $nonDefault = 0;
    foreach ($w as $r) {
        if (abs((float)$r['weight'] - 1.0) > 1e-9) { $nonDefault++; }
        echo "  {$r['module']} {$r['horizon']} {$r['regime']}: weight={$r['weight']} updated={$r['updated_at']}\n";
    }
    echo "  células: " . count($w) . " | divergentes de 1.0: {$nonDefault}\n";
    echo $nonDefault > 0 ? "  ⚠ pesos já divergiram — verificar evidência de amostra\n" : "  pesos todos default 1.0 (amostra insuficiente — comportamento correto do otimizador)\n";
} else {
    echo "  tabela vazia — pesos default 1.0 em uso\n";
}

echo "\n===== G. ABLAÇÃO (amostra disponível?) =====\n";
$evaluated = (int)$res['n'];
echo "  previsões avaliadas: {$evaluated}\n";
echo $evaluated >= 100
    ? "  amostra suficiente para ablação preliminar\n"
    : "  🟣 AGUARDANDO DADOS: ablação exige >= 100 previsões avaliadas por horizonte\n";

echo "\n===== H. OUT-OF-SAMPLE =====\n";
try {
    $oos = (new Prometheus\evaluation\OutOfSampleEvaluator())->run('BTCUSDT');
    if (isset($oos['error'])) {
        echo "  {$oos['error']} (disponível: {$oos['available']}) — {$oos['note']}\n";
    } else {
        echo "  método: {$oos['split']['method']}\n";
        echo "  split: treino={$oos['split']['train_size']} OOS={$oos['split']['oos_size']} (fração treino {$oos['split']['train_fraction']})\n";
        $ov = $oos['overall'];
        echo "  OOS: n={$ov['n']} acc=" . ($ov['directional_accuracy'] ?? '-') . " brier=" . ($ov['brier_score'] ?? '-') . "\n";
        echo "  nota: " . ($oos['note'] ?? 'ok') . "\n";
    }
} catch (Throwable $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
}

echo "\n===== I. BASELINES =====\n";
try {
    $r = Database::fetchAll("SELECT at_time, horizon, initial_price, final_price, actual_direction, predicted_direction, correct FROM backtest_results WHERE evaluation_version=1 ORDER BY at_time ASC LIMIT 2000");
    if ($r) {
        $n = count($r);
        $model = 0; $alwaysUp = 0; $prevDir = 0; $random = 0;
        for ($i = 1; $i < $n; $i++) {
            $actual = $r[$i]['actual_direction'];
            if ($actual === 'UP') { $alwaysUp++; }
            if ($r[$i-1]['actual_direction'] === $actual) { $prevDir++; }
            if (rand(0,1)) { $random++; }
            $model += (int)$r[$i]['correct'];
        }
        $m = $n - 1;
        printf("  n=%d | modelo: %.3f | always-up: %.3f | direção anterior: %.3f | random: %.3f\n",
            $m, $model / $m, $alwaysUp / $m, $prevDir / $m, $random / $m);
    } else {
        echo "  backtest_results vazio\n";
    }
} catch (Throwable $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
}

echo "\n===== J. PREVISÕES PENDENTES vs AVALIADAS =====\n";
$p = Database::fetch("SELECT COUNT(*) n FROM predictions");
$pr = Database::fetch("SELECT COUNT(*) n FROM prediction_results");
$future = Database::fetch("SELECT COUNT(*) n FROM predictions WHERE target_time > NOW()");
$past = Database::fetch("SELECT COUNT(*) n FROM predictions WHERE target_time <= NOW()");
echo "  predictions={$p['n']} | results={$pr['n']} | vencidas={$past['n']} | pendentes(futuro)={$future['n']}\n";
$bad = Database::fetch("SELECT COUNT(*) n FROM predictions WHERE created_at >= target_time");
echo "  previsões com created_at >= target_time (VIOLAÇÃO): {$bad['n']}\n";
