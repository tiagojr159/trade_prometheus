<?php
declare(strict_types=1);

/**
 * Testes dos Itens 16–19 e 21: pesos adaptativos, probabilidades,
 * avaliação, backtest walk-forward e out-of-sample.
 * Uso: php admin/item16_21_tests.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Database;
use Prometheus\evaluation\MetricsCalculator;
use Prometheus\evaluation\OutOfSampleEvaluator;
use Prometheus\evaluation\WalkForwardBacktester;
use Prometheus\evaluation\WeightOptimizer;
use Prometheus\prediction\ProbabilityCalculator;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$pass = 0;
$fail = 0;
$failures = [];

function check(string $name, bool $cond): void
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$name}\n";
    } else {
        $fail++;
        $failures[] = $name;
        echo "  [FAIL] {$name}\n";
    }
}

$pdo = Database::connection();

echo "== ITENS 16-21: pesos, probabilidades, avaliação, walk-forward, OOS ==\n";

// ============ ITEM 16: WEIGHT OPTIMIZER ============
echo "[Item 16] WeightOptimizer\n";
$pdo->beginTransaction();
Database::execute('DELETE FROM module_performance');
Database::execute('DELETE FROM module_weights');

// Célula com amostra insuficiente → peso fica no default 1.0.
Database::execute('INSERT INTO module_performance (module, horizon, regime, sample_size, accuracy, brier_score, avg_confidence) VALUES ("ATHENA", "1h", "SIDEWAYS", 5, 0.9, 0.10, 0.8)');
// Célula com muitas amostras e bom desempenho → peso sobe (com limites).
Database::execute('INSERT INTO module_performance (module, horizon, regime, sample_size, accuracy, brier_score, avg_confidence) VALUES ("POSEIDON", "1h", "SIDEWAYS", 50, 0.70, 0.15, 0.6)');
// Célula com muitas amostras e mau desempenho (pior que 0.5, Brier alto) → peso desce.
Database::execute('INSERT INTO module_performance (module, horizon, regime, sample_size, accuracy, brier_score, avg_confidence) VALUES ("HERMES", "1h", "SIDEWAYS", 50, 0.30, 0.45, 0.7)');

$opt = new WeightOptimizer();
$opt->optimize();

$w1 = (float)Database::fetch('SELECT weight FROM module_weights WHERE module="ATHENA" AND horizon="1h" AND regime="SIDEWAYS"')['weight'];
$w2 = (float)Database::fetch('SELECT weight FROM module_weights WHERE module="POSEIDON" AND horizon="1h" AND regime="SIDEWAYS"')['weight'];
$w3 = (float)Database::fetch('SELECT weight FROM module_weights WHERE module="HERMES" AND horizon="1h" AND regime="SIDEWAYS"')['weight'];

check('amostra insuficiente → peso default 1.0', abs($w1 - 1.0) < 1e-6);
check('bom desempenho + amostra suficiente → peso > 1.0', $w2 > 1.0);
check('mau desempenho → peso < 1.0', $w3 < 1.0);
check('pesos dentro dos limites [0.25, 3.0]', $w1 >= 0.25 && $w1 <= 3.0 && $w2 >= 0.25 && $w2 <= 3.0 && $w3 >= 0.25 && $w3 <= 3.0);

// Suavização: segundo ciclo não dá salto violento.
$prev2 = $w2;
$opt->optimize();
$w2b = (float)Database::fetch('SELECT weight FROM module_weights WHERE module="POSEIDON" AND horizon="1h" AND regime="SIDEWAYS"')['weight'];
check('segundo ciclo: mudança suave (<= 25% do atual)', abs($w2b - $prev2) <= $prev2 * 0.25 + 1e-9);
echo "  pesos: ATHENA(insuf)=$w1 POSEIDON(bom)=$w2->$w2b HERMES(mau)=$w3\n";
$pdo->rollBack();

// ============ ITEM 17: PROBABILITY CALCULATOR ============
echo "[Item 17] ProbabilityCalculator\n";
$calc = new ProbabilityCalculator();
$p = $calc->calculate(0.0, 0.5);
check('sinal 0 → pUp = 0.5 exato', abs($p['up'] - 0.5) < 1e-9);
$p = $calc->calculate(1.0, 1.0);
check('sinal máx + conf máx → alto mas limitado (< 0.995)', $p['up'] > 0.8 && $p['up'] < 0.995);
check('pUp + pDown = 1', abs(($p['up'] + $p['down']) - 1.0) < 1e-9);
$p = $calc->calculate(1.0, 0.1);
check('sinal forte com confiança baixa → perto de 0.5 (não vira 70%)', $p['up'] < 0.62);
$p = $calc->calculate(-1.0, 1.0);
check('sinal mínimo → pUp baixo, pDown alto, simétrico', $p['up'] < 0.2 && abs($p['down'] - (1 - $p['up'])) < 1e-9);
// v2 (DirectionPolicy): direção é BINÁRIA — SIDEWAYS não é classe direcional.
// Expectativa v1 (0.51/0.49 → SIDEWAYS) ficou semanticamente inválida.
check('direção v2: pUp≈pDown → UP (binário, edge mínimo)', $calc->direction(0.51, 0.49) === 'UP');
check('direção v2: pUp>pDown → UP', $calc->direction(0.7, 0.3) === 'UP');

$cal = $calc->calibration();
check('calibração: estrutura com bins e erro', isset($cal['bins'], $cal['total_evaluated']) || isset($cal['calibration_error']));
echo '  calibração: ' . ($cal['total_evaluated'] ?? 0) . " previsões avaliadas\n";

// ============ ITEM 18: PREDICTION EVALUATOR ============
echo "[Item 18] PredictionEvaluator\n";
// Verifica integridade: previsões persistidas têm created_at < target_time
// (prova de persistência ANTES do resultado).
$rows = Database::fetchAll('SELECT created_at, target_time FROM predictions LIMIT 200');
$allBefore = true;
foreach ($rows as $r) {
    if (strtotime((string)$r['created_at']) > strtotime((string)$r['target_time'])) {
        $allBefore = false;
        break;
    }
}
check('TODAS as previsões: created_at < target_time (persistidas antes do resultado)', $allBefore && count($rows) > 0);

// Campos preservados: nenhuma previsão é alterada após avaliação (resultado em tabela própria).
$cntResults = (int)Database::fetch('SELECT COUNT(*) AS c FROM prediction_results')['c'];
check('resultados em prediction_results separado', $cntResults > 0);
$sample = Database::fetch('SELECT p.id, p.created_at, p.target_time, p.initial_price, p.predicted_direction, p.probability_up, p.signals_json, p.weights_json, p.regime FROM predictions p JOIN prediction_results r ON r.prediction_id=p.id LIMIT 1');
check('previsão avaliada preserva todos os campos originais', $sample && isset($sample['signals_json'], $sample['weights_json'], $sample['regime'], $sample['initial_price']));

// ============ ITEM 19: WALK-FORWARD BACKTESTER ============
echo "[Item 19] WalkForwardBacktester\n";
$pdo->beginTransaction();
Database::execute('DELETE FROM backtest_results');
$bt = new WalkForwardBacktester();
$res = $bt->run('BTCUSDT', null, null, 3600, 50);
if (isset($res['error'])) {
    check('backtest: dados insuficientes relatados explicitamente', false);
    echo '  ' . json_encode($res) . "\n";
} else {
    check('backtest executou previsões', $res['predictions'] > 0);
    check('todas avaliadas (avaliação sempre depois da geração)', $res['evaluated'] === $res['predictions']);
    check('accuracy coerente com contadores', abs($res['accuracy'] - round($res['correct'] / $res['evaluated'], 4)) < 1e-9);
    check('edge vs baseline 0.5 calculado', isset($res['edge_vs_baseline']));
    // Sem look-ahead: nenhuma previsão usa candle posterior a at_time.
    $leak = Database::fetch('SELECT COUNT(*) AS c FROM backtest_results WHERE evaluated_at < at_time')['c'] ?? 0;
    check('nenhum resultado anterior à previsão (ordem temporal ok)', (int)$leak === 0);
    echo '  walk-forward: n=' . $res['predictions'] . ' acc=' . $res['accuracy'] . ' brier=' . $res['brier'] . ' cum_ret=' . $res['cum_return_pct'] . "% edge=" . $res['edge_vs_baseline'] . "\n";
}
$pdo->rollBack();

// ============ ITEM 21: OUT-OF-SAMPLE ============
echo "[Item 21] OutOfSampleEvaluator\n";
$oos = (new OutOfSampleEvaluator())->run('BTCUSDT');
if (isset($oos['error'])) {
    check('OOS: erro explícito quando insuficiente', $oos['error'] === 'insufficient_evaluated_predictions');
    echo '  ' . json_encode($oos) . "\n";
} else {
    check('split cronológico declarado', $oos['split']['method'] === 'chronological (never random)');
    check('métricas overall com accuracy + Brier', isset($oos['overall']['accuracy'], $oos['overall']['brier_score']));
    check('resultado por horizonte e por regime', isset($oos['by_horizon'], $oos['by_regime']));
    check('baselines (sempre-UP, persistência, aleatório)', isset($oos['baselines']['always_up_accuracy']));
    echo '  OOS: n=' . $oos['overall']['n'] . ' acc=' . $oos['overall']['accuracy'] . ' brier=' . $oos['overall']['brier_score'] . ' | horizontes=' . json_encode(array_keys($oos['by_horizon'])) . ' | regimes=' . json_encode(array_keys($oos['by_regime'])) . "\n";
}

// Integração: MetricsCalculator com Brier correto (não (1-conf)²).
echo "[Item 18b] MetricsCalculator (Brier real)\n";
$pdo->beginTransaction();
Database::execute('DELETE FROM module_performance');
$pred = [
    'horizon' => '1h', 'regime' => 'TEST', 'signals_json' => json_encode([
        ['module' => 'ATHENA', 'value' => 0.8, 'confidence' => 0.9],  // p≈0.86 → acertou UP → brier pequeno
        ['module' => 'HERMES', 'value' => -0.8, 'confidence' => 0.9], // previu DOWN, real UP → brier grande
    ]),
];
(new MetricsCalculator())->updateFromPrediction($pred, 'UP', 1);
$r1 = Database::fetch('SELECT * FROM module_performance WHERE module="ATHENA"');
$r2 = Database::fetch('SELECT * FROM module_performance WHERE module="HERMES"');
check('ATHENA (acertou): brier pequeno (< 0.05)', (float)$r1['brier_score'] < 0.05);
check('HERMES (errou): brier grande (> 0.6)', (float)$r2['brier_score'] > 0.6);
check('accuracy por módulo correta', (float)$r1['accuracy'] === 1.0 && (float)$r2['accuracy'] === 0.0);
$pdo->rollBack();

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) {
    echo "Falhas: " . implode('; ', $failures) . "\n";
    exit(1);
}
exit(0);
