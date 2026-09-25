<?php
declare(strict_types=1);
// Etapa 01/02 — probe de ambiente: idempotência do bootstrap + autoload.
chdir(dirname(__DIR__));
require 'bootstrap.php';
require 'bootstrap.php';
require_once 'bootstrap.php';
echo 'bootstrap idempotente: OK', PHP_EOL;
echo 'PHP: ', PHP_VERSION, PHP_EOL;
echo 'TZ: ', date_default_timezone_get(), PHP_EOL;
foreach ([
    'core\\Database',
    'core\\Logger',
    'core\\Signal',
    'core\\Cache',
    'core\\HttpClient',
    'core\\CronRunner',
    'intelligence\\PrometheusEngine',
    'intelligence\\EnsembleEngine',
    'intelligence\\RegimeDetector',
    'intelligence\\SignalNormalizer',
    'intelligence\\OpenAIService',
    'prediction\\PredictionService',
    'prediction\\ProbabilityCalculator',
    'prediction\\HorizonManager',
    'evaluation\\WeightOptimizer',
    'evaluation\\PredictionEvaluator',
    'evaluation\\WalkForwardBacktester',
    'evaluation\\OutOfSampleEvaluator',
    'evaluation\\MetricsCalculator',
    'collectors\\MarketCollector',
    'modules\\AthenaTechnical',
    'modules\\HermesNews',
    'modules\\PoseidonOnChain',
    'modules\\HephaestusDerivatives',
    'modules\\CronosMacro',
    'modules\\MarketRelations',
    'api\\ApiHandler',
] as $short) {
    $cn = 'Prometheus\\' . $short;
    echo $cn, class_exists($cn) ? ' OK' : ' *** FALTA ***', PHP_EOL;
}
$db = Prometheus\core\Database::fetch('SELECT 1 AS ok');
echo 'DB: ', $db['ok'], PHP_EOL;
