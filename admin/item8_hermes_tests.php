<?php
declare(strict_types=1);

/**
 * Testes de regressão do Item 8 — HERMES / OpenAIService.
 * Uso: php admin/item8_hermes_tests.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Database;
use Prometheus\intelligence\OpenAIService;
use Prometheus\modules\HermesNews;

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

echo "== ITEM 8: HERMES / OpenAI ==\n";

// --- 1. Sem API key: status explícito, nunca neutro silencioso ---
echo "[1] Sem API key — status explícito\n";
$svc = new OpenAIService();
$keys = require PROMETHEUS_ROOT . '/config/api_keys.php';
$hadKey = !empty($keys['openai_api_key']);

// Força ambiente sem key para teste determinístico.
$backupEnv = getenv('OPENAI_API_KEY');
putenv('OPENAI_API_KEY=');
$before = Database::fetch('SELECT COUNT(*) AS c FROM llm_usage')['c'] ?? 0;
$res = $svc->analyzeNews('Bitcoin ETF approved', 'SEC approves spot ETF', '1h');
$after = Database::fetch('SELECT COUNT(*) AS c FROM llm_usage')['c'] ?? 0;
check('status = no_api_key', $res['status'] === 'no_api_key');
check('features null (não 0 disfarçado)', $res['sentiment'] === null && $res['direction'] === null);
check('horizon presente', $res['horizon'] === '1h');
check('uso registrado em llm_usage', (int)$after === (int)$before + 1);
check('último registro status no_api_key', (Database::fetch('SELECT status FROM llm_usage ORDER BY id DESC LIMIT 1')['status'] ?? '') === 'no_api_key');

if ($backupEnv !== false) {
    putenv('OPENAI_API_KEY=' . $backupEnv);
}

// --- 2. Validação de JSON estruturado (via reflexão) ---
echo "[2] Validação de resposta\n";
$rm = new ReflectionMethod(OpenAIService::class, 'validateFeatures');
$rm->setAccessible(true);

$valid = $rm->invoke($svc, ['sentiment' => -0.8, 'relevance' => 0.9, 'impact' => 0.7, 'direction' => -0.6, 'summary' => 'bearish']);
check('JSON válido aceito', is_array($valid) && $valid['sentiment'] === -0.8);

$clamped = $rm->invoke($svc, ['sentiment' => 5, 'relevance' => -2, 'impact' => '0.5', 'direction' => 99, 'summary' => 'x']);
check('valores fora do range são clamped', is_array($clamped) && $clamped['sentiment'] === 1.0 && $clamped['relevance'] === 0.0 && $clamped['direction'] === 1.0);

$missing = $rm->invoke($svc, ['sentiment' => 0.5]);
check('campo faltante rejeitado (null)', $missing === null);

$nonNumeric = $rm->invoke($svc, ['sentiment' => 'bullish', 'relevance' => 0.5, 'impact' => 0.5, 'direction' => 0.5]);
check('valor não numérico rejeitado', $nonNumeric === null);

$ej = new ReflectionMethod(OpenAIService::class, 'extractJson');
$ej->setAccessible(true);
check('extrai JSON de markdown fence', $ej->invoke($svc, "```json\n{\"a\":1}\n```") === '{"a":1}');
check('extrai JSON de texto poluído', $ej->invoke($svc, 'Sure! Here: {"b":2} hope it helps') === '{"b":2}');
check('JSON limpo inalterado', $ej->invoke($svc, '{"c":3}') === '{"c":3}');

// --- 3. Rate limit ---
echo "[3] Rate limit\n";
$maxCalls = (int)prometheus_config('llm.max_calls_per_hour', 30);
$rmSlot = new ReflectionMethod(OpenAIService::class, 'acquireSlot');
$rmSlot->setAccessible(true);
// Simula chamadas 'ok' até o limite usando purpose de teste.
Database::execute('DELETE FROM llm_usage WHERE purpose = "test_rl"');
for ($i = 0; $i < $maxCalls; $i++) {
    Database::execute(
        'INSERT INTO llm_usage (purpose, model, status, created_at) VALUES ("test_rl", "test", "ok", NOW())'
    );
}
check('slot negado no limite', $rmSlot->invoke($svc, 'test_rl') === false);
Database::execute('DELETE FROM llm_usage WHERE purpose = "test_rl"');
check('slot liberado sem chamadas', $rmSlot->invoke($svc, 'test_rl') === true);

// --- 4. HermesNews com banco real ---
echo "[4] HermesNews (integração)\n";
$hermes = new HermesNews();
$sig = $hermes->signal('1h');
check('signal em [-1,1]', $sig->value >= -1.0 && $sig->value <= 1.0);
check('confidence em [0,1]', $sig->confidence >= 0.0 && $sig->confidence <= 1.0);
check('metadata tem status LLM', isset($sig->metadata['llm_status_24h']));
check('metadata tem horizonte', isset($sig->metadata['horizon']));
echo '  HERMES: signal=' . round($sig->value, 4) . ' confidence=' . round($sig->confidence, 4) . ' meta=' . json_encode($sig->metadata) . "\n";

// Caso sem notícias: retorna neutro com razão explícita, não silencioso.
Database::execute('CREATE TEMPORARY TABLE IF NOT EXISTS news_tmp AS SELECT * FROM news WHERE 1=0');
// (verificação de razão: forçando window sem notícias é invasivo; checamos apenas contrato acima)

// --- 5. Contrato do OpenAIService ---
echo "[5] Contrato\n";
check('result() sempre tem status', isset($res['status']));
check('result() sempre tem horizon', isset($res['horizon']));
$rmCost = new ReflectionMethod(OpenAIService::class, 'estimateCost');
$rmCost->setAccessible(true);
check('custo estimado: modelo conhecido > 0 com tokens', $rmCost->invoke($svc, 'gpt-4.1-mini', 1000000, 500000) > 0);
check('custo estimado: modelo desconhecido = 0', $rmCost->invoke($svc, 'modelo-futuro-xyz', 1000, 1000) === 0.0);

// --- 6. Custo acumulado registrado ---
$costSum = (float)(Database::fetch('SELECT COALESCE(SUM(cost_usd),0) AS s FROM llm_usage')['s'] ?? 0);
echo '  Custo acumulado llm_usage: $' . $costSum . "\n";

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) {
    echo "Falhas: " . implode('; ', $failures) . "\n";
    exit(1);
}
exit(0);
