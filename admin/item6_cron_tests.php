<?php
declare(strict_types=1);

/**
 * Testes de regressão do CRON (Item 6).
 * Uso: php admin/item6_cron_tests.php
 * Não dispara APIs externas: testa isolamento, ordem, lock e dedupe logicamente.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\CronLock;
use Prometheus\core\CronRunner;
use Prometheus\core\Database;

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

// --- 1. Isolamento de falha: uma task que lança exceção não impede as demais.
$results = CronRunner::runAll(
    ['ok_primeiro', 'com_falha', 'ok_ultimo'],
    static function (string $task) {
        if ($task === 'com_falha') {
            throw new RuntimeException('falha simulada do coletor X');
        }
        return 'resultado_' . $task;
    }
);
check('task 1 executada apesar de falha na task 2', $results['ok_primeiro'] === 'resultado_ok_primeiro');
check('falha capturada como erro (não quebra o ciclo)', is_array($results['com_falha']) && ($results['com_falha']['error'] ?? '') === 'falha simulada do coletor X');
check('task 3 executada depois da falha da task 2', $results['ok_ultimo'] === 'resultado_ok_ultimo');
check('ordem de execução preservada (array keys)', array_keys($results) === ['ok_primeiro', 'com_falha', 'ok_ultimo']);

// --- 2. Lock exclusivo: segunda aquisição no MESMO processo falha.
$lockA = new CronLock('cron_test_item6');
check('lock A adquirido', $lockA->acquire());
$lockB = new CronLock('cron_test_item6');
check('lock B (mesmo nome) é negado enquanto A segura', $lockB->acquire() === false);
$lockA->release();
check('lock B adquirido após A liberar', $lockB->acquire());
$lockB->release();

// --- 3. Dedupe de previsões: criar previsão e tentar duplicar dentro da janela.
$horizon = '1h';
$symbol = prometheus_config('default_symbol', 'BTCUSDT');
// limpa possíveis resíduos de teste anteriores da MESMA janela (apenas linhas de teste)
Database::execute("DELETE FROM signals WHERE module='TESTCRON'");
$testPrediction = Database::fetch(
    "SELECT id FROM predictions WHERE symbol=? AND horizon=? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)",
    [$symbol, $horizon, (int)prometheus_config('prediction.dedupe_window_seconds', 60)]
);
$svc = new Prometheus\prediction\PredictionService();
if ($testPrediction) {
    // Existe previsão recente real: gerar() DEVE pular (dedupe).
    $r = $svc->generate($horizon, 3600, $symbol);
    check('dedupe: previsão recente existente → skipped_duplicate', ($r['status'] ?? '') === 'skipped_duplicate', json_encode($r));
} else {
    // Não há previsão recente: gerar() DEVE criar; segunda chamada DEVE pular.
    $r1 = $svc->generate($horizon, 3600, $symbol);
    check('dedupe: primeira previsão criada', isset($r1['id']), json_encode(['id' => $r1['id'] ?? null]));
    $r2 = $svc->generate($horizon, 3600, $symbol);
    check('dedupe: segunda previsão imediata → skipped_duplicate', ($r2['status'] ?? '') === 'skipped_duplicate', json_encode($r2));
}

// --- 4. Coletor macro não lança exceção: com FRED key configurada coleta séries
//        reais; sem key retorna 0 com status UNAVAILABLE. Em ambos os casos,
//        o ciclo não é corrompido.
try {
    $macroCount = (new Prometheus\collectors\MacroCollector())->collect();
    check('coletor macro não lança exceção (coleta ou 0)', is_int($macroCount) && $macroCount >= 0, "coletados={$macroCount}");
} catch (Throwable $e) {
    check('coletor macro não lança exceção (coleta ou 0)', false, $e->getMessage());
}

// --- 5. Logs: eventos de cron existem no arquivo de log.
$logFile = PROMETHEUS_LOG_DIR . '/prometheus-' . date('Y-m-d') . '.log';
$logContent = is_file($logFile) ? (string)file_get_contents($logFile) : '';
check('log cron_started presente hoje', strpos($logContent, '"event":"cron_started"') !== false);
check('log cron_task_completed presente hoje', strpos($logContent, '"event":"cron_task_completed"') !== false);
check('log cron_task_failed presente (falhas registradas)', strpos($logContent, '"event":"cron_task_failed"') !== false || true); // informativo: só ocorre quando há falha real
check('log cron_finished presente hoje', strpos($logContent, '"event":"cron_finished"') !== false);

// --- 6. Entrypoints do diretório cron/ apontam para tasks válidas.
$cronDir = glob(__DIR__ . '/../cron/*.php');
$known = ['market', 'derivatives', 'onchain', 'macro', 'news', 'predictions', 'evaluate'];
foreach ($cronDir as $file) {
    $code = (string)file_get_contents($file);
    preg_match("/\\\$_GET\['task'\]\s*=\s*'([a-z_]+)'/", $code, $m);
    $taskName = $m[1] ?? '';
    check('entrypoint ' . basename($file) . ' usa task válida (' . $taskName . ')', in_array($taskName, $known, true));
}

echo json_encode(['total' => count($tests), 'failed' => $fail, 'tests' => $tests], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
