<?php
declare(strict_types=1);

/**
 * Testes de regressão do bootstrap (Item 3).
 * Uso: php admin/item3_bootstrap_tests.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

$tests = [];
$fail = 0;

function check(string $name, bool $ok, $detail = null): void
{
    global $tests, $fail;
    if (!$ok) {
        $fail++;
    }
    $tests[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

// --- 1. Bootstrap ainda NÃO carregado neste processo: prometheus_config() não existe.
check('prometheus_config() inexistente antes do bootstrap', !function_exists('prometheus_config'));
check('PROMETHEUS_BOOTSTRAPPED indefinido antes do bootstrap', !defined('PROMETHEUS_BOOTSTRAPPED'));

// --- 2. Carregar bootstrap uma vez.
$boot = require dirname(__DIR__) . '/bootstrap.php';
check('bootstrap.php retorna true no primeiro require', $boot === true);
check('PROMETHEUS_BOOTSTRAPPED definido após 1º require', defined('PROMETHEUS_BOOTSTRAPPED') && PROMETHEUS_BOOTSTRAPPED === true);
check('função prometheus_config() disponível', function_exists('prometheus_config'));

// --- 3. Carregar bootstrap duas vezes (require simples, não _once).
$boot2 = require dirname(__DIR__) . '/bootstrap.php';
check('segundo require do bootstrap não reinicializa (retorna void)', $boot2 === null);
$autoloadCount = 0;
foreach (spl_autoload_functions() ?: [] as $fn) {
    if ($fn instanceof Closure) {
        $file = (string)(new ReflectionFunction($fn))->getFileName();
        if (strpos($file, 'bootstrap.php') !== false) {
            $autoloadCount++;
        }
    }
}
check('autoloader via bootstrap.php registrado exatamente 1 vez', $autoloadCount === 1);

// --- 4. prometheus_config(): geral, pontilhado, default, chave inexistente.
check("prometheus_config('app_name') === PROMETHEUS", prometheus_config('app_name') === 'PROMETHEUS');
check("prometheus_config('horizons.1h') === 3600", prometheus_config('horizons.1h') === 3600);
check('prometheus_config() sem chave retorna array completo', is_array(prometheus_config()) && array_key_exists('default_symbol', prometheus_config()));
check("prometheus_config('chave.inexistente', 'fallback') === 'fallback'", prometheus_config('chave.inexistente', 'fallback') === 'fallback');
check("prometheus_config('collector.market_limit') === 500", prometheus_config('collector.market_limit') === 500);

// --- 5. Idempotência real: config não é recarregada (mutar $GLOBALS não some no re-require).
$GLOBALS['PROMETHEUS_CONFIG']['__probe'] = 'sentinel';
require dirname(__DIR__) . '/bootstrap.php'; // terceira carga: deve manter o array em memória
check('re-require não recarrega config (mantém array em memória)', prometheus_config('__probe') === 'sentinel');
unset($GLOBALS['PROMETHEUS_CONFIG']['__probe']);

// --- 6. Autoload das principais classes.
$classes = [
    'Prometheus\\core\\Database',
    'Prometheus\\core\\Logger',
    'Prometheus\\core\\Cache',
    'Prometheus\\core\\CronLock',
    'Prometheus\\core\\HttpClient',
    'Prometheus\\core\\Signal',
    'Prometheus\\collectors\\MarketCollector',
    'Prometheus\\collectors\\DerivativesCollector',
    'Prometheus\\collectors\\OnChainCollector',
    'Prometheus\\collectors\\MacroCollector',
    'Prometheus\\collectors\\NewsCollector',
    'Prometheus\\indicators\\RSI',
    'Prometheus\\indicators\\EMA',
    'Prometheus\\indicators\\MACD',
    'Prometheus\\indicators\\ATR',
    'Prometheus\\indicators\\BollingerBands',
    'Prometheus\\indicators\\Volume',
    'Prometheus\\modules\\AthenaTechnical',
    'Prometheus\\modules\\HermesNews',
    'Prometheus\\modules\\PoseidonOnChain',
    'Prometheus\\modules\\HephaestusDerivatives',
    'Prometheus\\modules\\CronosMacro',
    'Prometheus\\modules\\MarketRelations',
    'Prometheus\\intelligence\\EnsembleEngine',
    'Prometheus\\intelligence\\RegimeDetector',
    'Prometheus\\intelligence\\SignalNormalizer',
    'Prometheus\\intelligence\\PrometheusEngine',
    'Prometheus\\prediction\\PredictionService',
    'Prometheus\\prediction\\ProbabilityCalculator',
    'Prometheus\\evaluation\\PredictionEvaluator',
    'Prometheus\\evaluation\\WeightOptimizer',
];
foreach ($classes as $class) {
    $exists = class_exists($class) || interface_exists($class);
    check("autoload: {$class}", $exists);
}

// --- 7. Entrypoints de admin também sobrevivem a require_once duplo (smoke via sintaxe já feita no lint).

echo json_encode([
    'total' => count($tests),
    'failed' => $fail,
    'tests' => $tests,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
