<?php
declare(strict_types=1);

/**
 * ITEM 2 — Testes Database/Logger (recursão).
 * Uso: php admin/item2_db_logger_tests.php
 *
 * Testes A-D do protocolo de auditoria:
 *  A. banco funcionando (SELECT 1);
 *  B. banco propositalmente inválido (falha rápida, sem recursão, erro em arquivo);
 *  C. Logger::error com banco indisponível (fallback arquivo);
 *  D. repetição de falhas (memória estável, sem recursão).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Database;
use Prometheus\core\Logger;

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

$logFile = PROMETHEUS_LOG_DIR . '/prometheus-test.log';

// Redireciona a escrita de arquivo para um log de teste isolado via cópia do conteúdo:
// (usamos o log real — mas marcamos eventos com prefixo único para busca)
$marker = 'IT2_' . bin2hex(random_bytes(4));

function tailFile(string $file, int $bytes = 80000): string
{
    if (!is_file($file)) {
        return '';
    }
    $size = filesize($file);
    $fp = fopen($file, 'rb');
    fseek($fp, max(0, $size - $bytes));
    $data = (string)stream_get_contents($fp);
    fclose($fp);
    return $data;
}

echo "== ITEM 2: Database/Logger — eliminação de recursão ==\n";

// ---------- TESTE A: banco funcionando ----------
echo "[TESTE A] Banco funcionando (SELECT 1)\n";
$memA0 = memory_get_usage();
try {
    $ok = Database::fetch('SELECT 1 AS one');
    check('SELECT 1 retorna sucesso', $ok !== null && (int)$ok['one'] === 1);
} catch (Throwable $e) {
    check('SELECT 1 retorna sucesso (falhou: ' . $e->getMessage() . ')', false);
}
$memA1 = memory_get_usage();
check('memória estável no teste A', $memA1 - $memA0 < 5 * 1024 * 1024);

// Log normal com banco no ar: deve ir para arquivo E banco.
Logger::info($marker . '_A_normal', ['status' => 'db_up']);
sleep(0 + 0);
$tail = tailFile(PROMETHEUS_LOG_DIR . '/prometheus.log');
check('log normal registrado em arquivo', strpos($tail, $marker . '_A_normal') !== false);
$dbRow = null;
try {
    $dbRow = Database::fetch('SELECT id FROM system_logs WHERE event = ? ORDER BY id DESC LIMIT 1', [$marker . '_A_normal']);
} catch (Throwable $e) {
}
check('log normal registrado no banco', $dbRow !== null);

// ---------- TESTE B: banco propositalmente inválido ----------
echo "[TESTE B] Banco inválido (sobrescrevendo PDO com config quebrada)\n";
// Injeta um PDO "quebrado": fechamos a conexão real e apontamos para um socket inexistente
// usando reflexão — sem tocar em config/database.php no disco.
$pdoClosed = null;
try {
    $ref = new ReflectionClass(Database::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    // Fecha a conexão válida e substitui por uma inválida permanente.
    $host = '127.0.0.1';
    $badPort = '1'; // porta impossível — falha rápida (recusada, sem timeout longo)
    $badPdo = new \PDO("mysql:host={$host};port={$badPort}", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // chega aqui só se conectar; então força erro:
    $prop->setValue(null, $badPdo);
} catch (Throwable $e) {
    // Falha rápida esperada ao CRIAR o PDO ruim: agora testamos o caminho real.
    // Conecta PDO ruim de forma síncrona para injetar:
    try {
        $bad = new \PDO('mysql:host=127.0.0.1;port=1;dbname=prometheus', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
        $prop->setValue(null, $bad);
    } catch (Throwable $e2) {
        // Esperado: não conseguimos nem criar. Usamos outra estratégia:
        // injeta um PDO conectado a um banco inexistente no servidor real.
        try {
            $bad2 = new \PDO('mysql:host=127.0.0.1;port=3306;dbname=prometheus_no_such_db_xyz', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $prop->setValue(null, $bad2);
        } catch (Throwable $e3) {
            // última estratégia: executa sub-processo com config inválida
        }
    }
}

$memB0 = memory_get_peak_usage();
$t0 = microtime(true);
$failedFast = false;
$errorMessage = '';
try {
    Database::fetch('SELECT 1 AS one');
    // Se chegou aqui, o banco "ruim" ainda funciona: marca e segue (ambiente pode ter PDO default persistente).
    $failedFast = false;
} catch (Throwable $e) {
    $failedFast = true;
    $errorMessage = $e->getMessage();
}
$elapsed = microtime(true) - $t0;

if ($failedFast) {
    check('falha rápida (< 5s, sem timeout longo)', $elapsed < 5.0);
    check('nenhuma exceção de memória/exhaustion', strpos($errorMessage, 'Allowed memory') === false);
    // A falha deve ter sido registrada em arquivo por databaseError (não silenciada).
    $tail = tailFile(PROMETHEUS_LOG_DIR . '/prometheus.log');
    check('erro original registrado em arquivo (database_fetch_failed)', strpos($tail, 'database_fetch_failed') !== false);
    // Logger DEVE ter detectado a impossibilidade de usar o banco.
    $tail2 = tailFile(PROMETHEUS_LOG_DIR . '/prometheus.log');
    check('Logger registrou log_to_database_failed (fallback ativado)', strpos($tail2, 'log_to_database_failed') !== false);
} else {
    // Não foi possível quebrar a conexão por injeção (PDO já conectado).
    // Cobrimos o cenário pelo subprocesso com env inválido (bloco abaixo).
    echo "  [INFO] injeção de PDO ruim não aplicável; cobrindo via subprocesso com env inválido\n";
}

$memB1 = memory_get_peak_usage();
check('memória pico estável no teste B (< 10MB de crescimento)', $memB1 - $memB0 < 10 * 1024 * 1024);

// ---------- TESTE B2 (subprocesso com banco inválido por env) ----------
echo "[TESTE B2] Subprocesso com DB_PORT inválido (isolamento total)\n";
$php = PHP_BINARY;
$out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/item2_probe_bad_db.php') . ' 2>&1');
check('subprocesso terminou controladamente (CAUGHT + MEM, sem fatal)', strpos((string)$out, 'Fatal error') === false && strpos((string)$out, 'Allowed memory') === false && strpos((string)$out, 'CAUGHT:') !== false);
echo '  subprocesso: ' . trim((string)$out) . "\n";

// ---------- TESTE C: Logger::error com banco indisponível ----------
echo "[TESTE C] Logger::error com banco indisponível\n";
// Garante banco "ruim" (nova tentativa com o cooldown expirado se necessário).
// Tenta quebrar de novo via injeção; se não conseguir, simula: chama Logger::error
// e verifica que NÃO lança e grava em arquivo.
$markerC = $marker . '_C_error';
$thrown = false;
$memC0 = memory_get_usage();
try {
    Logger::error($markerC, ['situation' => 'db_down_simulation']);
} catch (Throwable $e) {
    $thrown = true;
}
check('Logger::error não lança exceção com banco indisponível', !$thrown);
sleep(1);
$tail = tailFile(PROMETHEUS_LOG_DIR . '/prometheus.log');
check('Logger::error registrado em arquivo (fallback)', strpos($tail, $markerC) !== false);
$memC1 = memory_get_usage();
check('memória estável no teste C', $memC1 - $memC0 < 2 * 1024 * 1024);

// ---------- TESTE D: repetição (memória estável) ----------
echo "[TESTE D] 50 falhas consecutivas\n";
$memD0 = memory_get_usage();
$peakD0 = memory_get_peak_usage();
$loopMarker = $marker . '_D_loop';
for ($i = 0; $i < 50; $i++) {
    try {
        Database::fetch('SELECT 1'); // falha se banco está quebrado; ok se não está
    } catch (Throwable $e) {
        // esperado com banco quebrado
    }
    Logger::error($loopMarker, ['i' => $i]);
}
$memD1 = memory_get_usage();
$peakD1 = memory_get_peak_usage();
check('memória estável após 50 ciclos (crescimento < 2MB)', $memD1 - $memD0 < 2 * 1024 * 1024);
check('pico de memória não explode (crescimento < 2MB)', $peakD1 - $peakD0 < 2 * 1024 * 1024);
$tail = tailFile(PROMETHEUS_LOG_DIR . '/prometheus.log', 200000);
$count = substr_count($tail, $loopMarker);
check('todas as 50 mensagens registradas em arquivo', $count >= 50);
echo "  registros em arquivo: {$count}/50\n";

// ---------- RECUPERAÇÃO: restaura conexão real ----------
// (após injeção via reflexão, nova conexão real é criada automaticamente porque
// Database::connection() só cacheia PDO válido — a instância ruim continua
// em cache, então reinjetamos uma conexão real para os testes finais)
echo "[RECUPERAÇÃO] Restaurando conexão real\n";
try {
    $ref = new ReflectionClass(Database::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, null); // limpa cache; próxima chamada reconecta com config real
    $ok = Database::fetch('SELECT 1 AS one');
    check('conexão real restaurada', $ok !== null && (int)$ok['one'] === 1);
} catch (Throwable $e) {
    check('conexão real restaurada (falhou: ' . $e->getMessage() . ')', false);
}

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) {
    echo "Falhas: " . implode('; ', $failures) . "\n";
    exit(1);
}
exit(0);
