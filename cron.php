<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Prometheus\core\CronLock;
use Prometheus\core\CronRunner;
use Prometheus\core\Logger;
use Prometheus\core\PaperTrader;
use Prometheus\collectors\DerivativesCollector;
use Prometheus\collectors\MacroCollector;
use Prometheus\collectors\MarketCollector;
use Prometheus\collectors\NewsCollector;
use Prometheus\collectors\OnChainCollector;
use Prometheus\evaluation\PredictionEvaluator;
use Prometheus\evaluation\WeightOptimizer;
use Prometheus\prediction\PredictionService;

/**
 * PROMETHEUS cron runner.
 *
 * Uso:
 *   php cron.php                 → ciclo completo (ordem fixa)
 *   php cron.php market          → task única
 *   php cron.php all             → igual ciclo completo
 *
 * Garantias:
 * - Lock exclusivo por task E lock global do ciclo (não permite 'all'
 *   concorrente com task individual da mesma cadeia).
 * - Ordem de execução fixa: coleta → previsões → avaliação.
 * - Falha em um coletor não corrompe o ciclo (CronRunner isola por task).
 * - Prevenção de previsão duplicada (dedupe por minuto no PredictionService).
 * - Logs de início, conclusão (com duração) e falha de cada task.
 */

$knownTasks = ['market', 'derivatives', 'onchain', 'macro', 'news', 'predictions', 'evaluate', 'optimize', 'paper_trade'];
$fullCycle = ['market', 'derivatives', 'onchain', 'macro', 'news', 'predictions', 'evaluate', 'optimize', 'paper_trade'];
$symbol = prometheus_config('default_symbol', 'BTCUSDT');

$task = $argv[1] ?? $_GET['task'] ?? 'all';
if ($task === 'all') {
    $tasks = $fullCycle;
} elseif (in_array($task, $knownTasks, true)) {
    $tasks = [$task];
} else {
    http_response_code(400);
    header('Content-Type: application/json');
    Logger::error('cron_unknown_task', ['task' => $task]);
    echo json_encode(['status' => 'error', 'message' => 'Unknown task: ' . $task, 'known' => $knownTasks], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$run = static function (string $name) use ($symbol) {
    switch ($name) {
        case 'market':
            return (new MarketCollector())->collectAllTimeframes($symbol);
        case 'derivatives':
            return (new DerivativesCollector())->collect($symbol);
        case 'onchain':
            return (new OnChainCollector())->collect();
        case 'macro':
            return (new MacroCollector())->collect();
        case 'news':
            return (new NewsCollector())->collect();
        case 'predictions':
            return (new PredictionService())->generateAll($symbol);
        case 'evaluate':
            return (new PredictionEvaluator())->evaluateDue();
        case 'optimize':
            // Etapa 28 — otimização de pesos roda APÓS avaliação no ciclo completo.
            return (new WeightOptimizer())->optimize();
        case 'paper_trade':
            return (new PaperTrader())->run($symbol);
        default:
            return null;
    }
};

// Lock global do ciclo: impede execução simultânea de tasks sobrepostas
// (ex.: 'all' rodando junto com 'market' disparado por outro agendador).
$globalLock = new CronLock('cron_global');
$taskLock = new CronLock('cron_' . $task);
if (!$globalLock->acquire() || !$taskLock->acquire()) {
    $globalLock->release();
    $taskLock->release();
    $result = ['status' => 'locked', 'task' => $task, 'message' => 'Another cron execution is already running.'];
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(409);
        header('Content-Type: application/json');
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

Logger::info('cron_started', ['task' => $task, 'tasks' => $tasks, 'pid' => getmypid()]);
try {
    $result = CronRunner::runAll($tasks, $run);
    Logger::info('cron_finished', ['task' => $task, 'result' => $result]);
} finally {
    $taskLock->release();
    $globalLock->release();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
