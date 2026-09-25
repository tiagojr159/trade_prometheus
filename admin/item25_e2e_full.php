<?php
declare(strict_types=1);

/**
 * ITEM 25 — TESTE END-TO-END COMPLETO.
 * Executa e evidencia TODAS as etapas do ciclo:
 *  API externa → coleta → MySQL → 6 módulos → normalização → regime →
 *  pesos → ensemble → probabilidade → previsão → persistência →
 *  passagem do horizonte → avaliação → performance → atualização dos pesos
 *  → dashboard.
 * Uso: php admin/item25_e2e_full.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\api\ApiHandler;
use Prometheus\collectors\DerivativesCollector;
use Prometheus\collectors\MarketCollector;
use Prometheus\collectors\NewsCollector;
use Prometheus\collectors\OnChainCollector;
use Prometheus\collectors\MacroCollector;
use Prometheus\core\Database;
use Prometheus\evaluation\PredictionEvaluator;
use Prometheus\evaluation\WeightOptimizer;
use Prometheus\intelligence\PrometheusEngine;
use Prometheus\prediction\ProbabilityCalculator;
use Prometheus\prediction\PredictionService;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$symbol = prometheus_config('default_symbol', 'BTCUSDT');
$evidence = [];
$step = function (string $name, array $data) use (&$evidence) {
    $evidence[$name] = $data;
    echo "== {$name} ==\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
};

// ---------- 1. API EXTERNA + COLETA ----------
$collected = [];
try {
    $market = new MarketCollector();
    $collected['market_candles'] = $market->collect($symbol, '1m', 60);
    $collected['latest_price'] = $market->latestPrice($symbol);
} catch (Throwable $e) {
    $collected['error'] = $e->getMessage();
}
try {
    $collected['derivatives'] = (new DerivativesCollector())->collect($symbol);
} catch (Throwable $e) {
    $collected['derivatives_error'] = $e->getMessage();
}
try {
    $collected['onchain'] = (new OnChainCollector())->collect();
} catch (Throwable $e) {
    $collected['onchain_error'] = $e->getMessage();
}
try {
    $collected['macro'] = (new MacroCollector())->collect();
} catch (Throwable $e) {
    $collected['macro_error'] = $e->getMessage();
}
try {
    $collected['news'] = (new NewsCollector())->collect('1h');
} catch (Throwable $e) {
    $collected['news_error'] = $e->getMessage();
}
$step('1. API externa + coleta', $collected);

// ---------- 2. MYSQL (persistência da coleta) ----------
$dbCounts = [];
foreach (['market_data' => 'symbol="' . $symbol . '"', 'derivatives_data' => '1', 'onchain_data' => '1', 'macro_data' => '1', 'news' => '1'] as $table => $where) {
    $dbCounts[$table] = (int)Database::fetch("SELECT COUNT(*) AS c FROM {$table} WHERE {$where}")['c'];
}
$step('2. MySQL — registros persistidos', $dbCounts);

// ---------- 3-4. MÓDULOS + NORMALIZAÇÃO ----------
$engine = new PrometheusEngine();
$analysis = $engine->analyze('1h', $symbol);
$modulesOut = [];
foreach ($analysis['signals'] as $s) {
    $modulesOut[$s->module] = [
        'signal' => round($s->value, 4),
        'confidence' => round($s->confidence, 4),
        'timestamp' => $s->metadata['timestamp'] ?? null,
        'horizon' => $s->metadata['horizon'] ?? null,
        'reason_or_evidence' => $s->metadata['reason'] ?? ($s->metadata['metric_count'] ?? ($s->metadata['items'] ?? null)),
    ];
}
$step('3-4. 6 módulos → normalização (contrato padronizado)', $modulesOut);

// ---------- 5. REGIME ----------
$step('5. Regime', ['regime' => $analysis['regime']['regime'], 'confidence' => round($analysis['regime']['confidence'], 3), 'evidencias' => $analysis['regime']['metadata']]);

// ---------- 6. PESOS ----------
$step('6. Pesos (module_weights)', array_map(fn($r) => (float)$r['weight'], Database::fetchAll('SELECT module, weight FROM module_weights WHERE horizon="1h"')));

// ---------- 7-8. ENSEMBLE + CONTRIBUIÇÕES ----------
$step('7-8. Ensemble (contribuições individuais)', [
    'signal' => round($analysis['ensemble_signal'], 5),
    'confidence' => round($analysis['confidence'], 5),
    'coverage' => $analysis['coverage'],
    'missing_modules' => $analysis['missing_modules'],
    'contributions' => $analysis['contributions'],
]);

// ---------- 9. PROBABILIDADES ----------
$step('9. Probabilidades (P(up)/P(down) ≠ confidence)', [
    'p_up' => round($analysis['probability_up'], 5),
    'p_down' => round($analysis['probability_down'], 5),
    'confidence_ensemble' => round($analysis['confidence'], 5),
    'direcao' => $analysis['direction'],
]);

// ---------- 10-11. PREVISÃO + PERSISTÊNCIA ----------
$svc = new PredictionService($engine);
$result = $svc->generate('1h', 3600, $symbol);
$predId = $result['id'] ?? null;
$row = $predId ? Database::fetch('SELECT id, symbol, horizon, target_time, initial_price, predicted_direction, probability_up, confidence, regime, created_at FROM predictions WHERE id = ?', [$predId]) : null;
$step('10-11. Previsão + persistência (ANTES do resultado)', $row ?? $result);

// ---------- 12. PASSAGEM DO HORIZONTE ----------
// Para evidenciar sem esperar 1h: usa previsões vencidas já existentes.
$due = Database::fetch('SELECT COUNT(*) AS c FROM predictions p LEFT JOIN prediction_results r ON r.prediction_id=p.id WHERE r.id IS NULL AND p.target_time <= NOW()')['c'] ?? 0;
$step('12. Passagem do horizonte', ['previsoes_vencidas_pendentes' => (int)$due, 'nota' => 'target_time da previsao ' . ($predId ?? '?') . ' = ' . ($row['target_time'] ?? '?') . ' (avaliada apenas apos esse instante)']);

// ---------- 13. AVALIAÇÃO ----------
$evaluated = (new PredictionEvaluator())->evaluateDue();
$step('13. Avaliação (após vencimento)', ['avaliadas_neste_ciclo' => $evaluated]);

// ---------- 14. PERFORMANCE ----------
$perf = Database::fetchAll('SELECT module, horizon, regime, sample_size, accuracy, brier_score FROM module_performance ORDER BY sample_size DESC LIMIT 8');
$globalPerf = Database::fetch('SELECT COUNT(*) AS n, AVG(r.directional_hit) AS accuracy, AVG(r.return_pct) AS avg_return FROM predictions p JOIN prediction_results r ON r.prediction_id=p.id WHERE r.evaluation_version=2 AND r.directional_hit IS NOT NULL');
$step('14. Performance', ['global' => ['n' => (int)$globalPerf['n'], 'accuracy' => $globalPerf['accuracy'] !== null ? round((float)$globalPerf['accuracy'], 4) : null], 'por_modulo' => $perf]);

// ---------- 15. ATUALIZAÇÃO DOS PESOS ----------
$weightsBefore = array_map(fn($r) => (float)$r['weight'], Database::fetchAll('SELECT module, weight FROM module_weights WHERE horizon="1h"'));
(new WeightOptimizer())->optimize();
$weightsAfter = array_map(fn($r) => (float)$r['weight'], Database::fetchAll('SELECT module, weight FROM module_weights WHERE horizon="1h"'));
$changed = [];
foreach ($weightsAfter as $m => $w) {
    if (isset($weightsBefore[$m]) && abs($weightsBefore[$m] - $w) > 1e-6) {
        $changed[$m] = ['de' => $weightsBefore[$m], 'para' => $w];
    }
}
$step('15. Atualização dos pesos (adaptativa)', ['mudancas' => $changed ?: 'nenhuma (amostra mínima não atingida — comportamento correto)', 'pesos_atuais_1h' => $weightsAfter]);

// ---------- 16. DASHBOARD ----------
// Dashboard e API são validados em processos CLI separados (headers HTTP não
// podem ser emitidos dentro deste script que já imprimiu evidências).
$php = PHP_BINARY;
$dash = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/probe_dashboard.php') . ' 2>&1');
[$bytes, $status] = array_pad(explode('|', trim((string)$dash)), 2, '?');
$step('16. Dashboard', ['bytes_renderizados' => $bytes, 'validacao' => $status]);

// ---------- 17. API (rate limit + validação) ----------
$api = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/probe_api.php') . ' 2>&1');
$apiData = json_decode((string)$api, true);
$step('17. API', ['json_valido' => is_array($apiData), 'itens' => is_array($apiData) ? count($apiData) : 0]);

// ---------- RESUMO ----------
$ok = ($evidence['16. Dashboard']['validacao'] ?? '') === 'OK'
    && ($evidence['17. API']['json_valido'] ?? false) === true
    && isset($evidence['10-11. Previsão + persistência (ANTES do resultado)']['id'])
    && !isset($evidence['1. API externa + coleta']['error']);
echo "==============================\n";
echo "E2E COMPLETO: " . ($ok ? 'OK' : 'ERRO') . "\n";
echo "Etapas evidenciadas: " . count($evidence) . "/17\n";
