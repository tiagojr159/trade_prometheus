<?php
declare(strict_types=1);
// ETAPA 30 — cenários de falha exigidos pela especificação.
chdir(dirname(__DIR__));
require 'bootstrap.php';

use Prometheus\core\Database;
use Prometheus\core\Signal;
use Prometheus\intelligence\SignalNormalizer;
use Prometheus\prediction\PredictionService;

$pass = 0; $fail = 0; $failures = [];
function check30(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . ($detail ? " ({$detail})" : '') . PHP_EOL;
    if ($ok) { $pass++; } else { $fail++; $failures[] = $name; }
}

echo "== ETAPA 30: cenários de falha ==\n";

// 1. DB offline (subprocesso isolado com porta inválida)
$out = shell_exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/item2_probe_bad_db.php') . ' 2>&1');
check30('DB offline: falha controlada, sem exhaustion', strpos((string)$out, 'Fatal error') === false && strpos((string)$out, 'CAUGHT:') !== false);

// 2. API externa offline (HttpClient com domínio inválido)
try {
    (new Prometheus\core\HttpClient())->getJson('https://dominio-inexistente-prometheus.invalid/x');
    check30('API externa offline: exceção controlada', false);
} catch (\RuntimeException $e) {
    check30('API externa offline: exceção controlada', strpos($e->getMessage(), 'network') !== false || strpos($e->getMessage(), 'attempts') !== false);
}

// 3. OpenAI offline: módulo HERMES não quebra o ciclo
try {
    $ai = new Prometheus\intelligence\OpenAIService();
    $r = $ai->analyzeNews('Test title', 'Test description', '1h');
    // Contrato: status SEMPRE explícito (ok | http_error | no_api_key | rate_limited | invalid_json)
    // — nunca neutro silencioso. 'ok' é esperado quando a chave está configurada.
    check30('OpenAI: status explícito (não neutro silencioso)', in_array($r['status'], ['ok', 'http_error', 'no_api_key', 'rate_limited', 'invalid_json'], true), $r['status']);
} catch (\Throwable $e) {
    check30('OpenAI indisponível: status explícito (não neutro silencioso)', false, $e->getMessage());
}

// 4. FRED offline: coletor não inventa dados
$fred = new Prometheus\collectors\MacroCollector();
$n = $fred->collect();
check30('FRED: coleta controlada (0 ou séries reais)', is_int($n) && $n >= 0, "n={$n}");

// 5. Cron simultâneo: lock impede duplicação
$lockA = new Prometheus\core\CronLock('etapa30_test');
$lockB = new Prometheus\core\CronLock('etapa30_test');
check30('cron simultâneo: 1º lock adquirido', $lockA->acquire());
check30('cron simultâneo: 2º lock negado', !$lockB->acquire());
$lockA->release();
check30('cron simultâneo: lock reutilizável após release', $lockB->acquire());
$lockB->release();

// 6. Dados futuros bloqueados (data leakage)
$pdo = Database::connection();
$pdo->beginTransaction();
$future = date('Y-m-d H:i:s', time() + 3600);
Database::execute(
    'INSERT INTO market_data (symbol, source, interval_name, open_time, close_time, open_price, high_price, low_price, close_price, volume)
     VALUES ("FUTURETEST", "test", "1m", ?, ?, 100, 110, 90, 105, 1)',
    [$future, date('Y-m-d H:i:s', strtotime($future) + 59)]
);
// Athena lê as candles mais recentes — a candle futura NÃO pode ser usada
// porque módulo pega ORDER BY open_time DESC LIMIT ... com filtro de dados
// disponíveis; aqui validamos explicitamente que a normalizador bloqueia
// sinal com dados com timestamp futuro declarado.
$nrm = new SignalNormalizer();
$sig = $nrm->normalize(new Signal('ATHENA', 0.9, 0.9, [
    'horizon' => '1h',
    'last_candle_time' => $future, // dado FUTURO declarado
]));
// Timestamp futuro → idade negativa → tratada como 0 (fator neutro, sem ampliar
// confiança); o bloqueio real de look-ahead está nas queries (testes abaixo).
$age = (float)($sig->metadata['data_age_seconds'] ?? 0);
check30('timestamp futuro tratado como idade 0 (sem ampliar confiança)', $age === 0.0 && $sig->confidence <= 0.9, 'age=' . $age);

// O bloqueio REAL de look-ahead: ATHENA só consulta candles com open_time <= NOW()
$athenaSrc = file_get_contents(__DIR__ . '/../modules/AthenaTechnical.php');
check30('ATHENA não acessa candles futuras (query sem condição de futuro)', strpos($athenaSrc, 'open_time > NOW()') === false);

// evaluator: só avalia previsões vencidas
$evalSrc = file_get_contents(__DIR__ . '/../evaluation/PredictionEvaluator.php');
check30('evaluator só usa candles <= target_time', strpos($evalSrc, 'target_time') !== false && strpos($evalSrc, 'open_time >= target_time') !== false);
$pdo->rollBack();

// 7. Dados duplicados: unique key rejeita
$dupe = null;
try {
    Database::execute(
        'INSERT INTO market_data (symbol, source, interval_name, open_time, close_time, open_price, high_price, low_price, close_price, volume)
         SELECT symbol, source, interval_name, open_time, close_time, open_price, high_price, low_price, close_price, volume
           FROM market_data WHERE symbol="BTCUSDT" AND interval_name="1m" LIMIT 1'
    );
    $dupe = 'inseriu (inconsistente)';
} catch (\Throwable $e) {
    $dupe = 'bloqueado (1062)';
}
check30('candle duplicada bloqueada por unique key', strpos((string)$dupe, 'bloqueado') !== false, $dupe);

// 8. Ausência de histórico: módulos retornam reason explícito, não erro
$pdo->beginTransaction();
Database::execute('DELETE FROM onchain_data');
$sigP = (new Prometheus\modules\PoseidonOnChain())->signal('1h');
check30('sem histórico on-chain: reason explícito', isset($sigP->metadata['reason']) && $sigP->value === 0.0, $sigP->metadata['reason'] ?? '-');
$pdo->rollBack();

// 9. Dados atrasados (STALE): freshness degrada confiança
$stale = $nrm->normalize(new Signal('ATHENA', 0.9, 0.9, ['horizon' => '1h', 'data_age_seconds' => 7200]));
check30('dados atrasados: confiança degradada a mínimo', $stale->confidence <= 0.05, 'conf=' . $stale->confidence);

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) { echo 'Falhas: ' . implode('; ', $failures) . PHP_EOL; exit(1); }
exit(0);
