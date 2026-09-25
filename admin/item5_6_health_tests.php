<?php
declare(strict_types=1);
// ETAPAS 05+06 — testes de SourceHealth + HttpClient resiliente.
chdir(dirname(__DIR__));
require 'bootstrap.php';

use Prometheus\core\Database;
use Prometheus\core\SourceHealth;
use Prometheus\core\HttpClient;

$pass = 0; $fail = 0; $failures = [];
function check56(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . ($detail ? " ({$detail})" : '') . PHP_EOL;
    if ($ok) { $pass++; } else { $fail++; $failures[] = $name; }
}

echo "== ETAPA 05: SourceHealth ==\n";

// limpa registro de teste
Database::execute("DELETE FROM source_health WHERE source = 'test_probe'");

$t0 = SourceHealth::begin('test_probe');
SourceHealth::success('test_probe', '2026-09-23 10:00:00', $t0);
$row = SourceHealth::get('test_probe');
check56('success grava status OK', $row['status'] === 'OK');
check56('latency registrada', (int)$row['latency_ms'] >= 0);
check56('last_data_timestamp = timestamp do evento (não NOW)', $row['last_data_timestamp'] === '2026-09-23 10:00:00');

// STALE: dado com idade > threshold
$old = Database::fetch("SELECT last_success FROM source_health WHERE source='test_probe'");
Database::execute("UPDATE source_health SET last_data_timestamp = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE source='test_probe'");
$snap = SourceHealth::snapshot();
check56('dados velhos marcados STALE', $snap['test_probe']['status_effective'] === 'STALE', 'age=' . $snap['test_probe']['age_seconds']);
Database::execute("UPDATE source_health SET last_data_timestamp = NOW() WHERE source='test_probe'");
$snap = SourceHealth::snapshot();
check56('dado fresco permanece OK', $snap['test_probe']['status_effective'] === 'OK');

// falhas consecutivas
SourceHealth::failure('test_probe', 'erro simulado 1');
SourceHealth::failure('test_probe', 'erro simulado 2');
$row = SourceHealth::get('test_probe');
check56('failure incrementa consecutive_failures', (int)$row['consecutive_failures'] === 2);
check56('failure preserva last_success anterior', $row['last_success'] !== null);
check56('failure marca status ERROR', $row['status'] === 'ERROR');

SourceHealth::unavailable('test_probe', 'sem api key');
check56('unavailable registrado', SourceHealth::get('test_probe')['status'] === 'UNAVAILABLE');

SourceHealth::noData('test_probe', 'fonte ok mas nada coletado');
check56('no_data registrado', SourceHealth::get('test_probe')['status'] === 'NO_DATA');

// sucesso reseta falhas
$t0 = SourceHealth::begin('test_probe');
SourceHealth::success('test_probe', null, $t0);
check56('sucesso zera consecutive_failures', (int)SourceHealth::get('test_probe')['consecutive_failures'] === 0);

// coexistência fonte OK + módulo NO_DATA
SourceHealth::success('test_probe', null, SourceHealth::begin('test_probe'));
SourceHealth::noData('test_probe', 'llm sem key');
$snap = SourceHealth::snapshot();
check56('fonte pode estar NO_DATA mesmo com coleta ativa', $snap['test_probe']['status_effective'] === 'NO_DATA');

echo "== ETAPA 06: HttpClient resiliente ==\n";
$http = new HttpClient();

// 1. Endpoint real de sucesso
$binance = $http->getJson('https://api.binance.com/api/v3/ping');
check56('GET real (binance ping) OK', $binance === []);

// 2. 404 (4xx): falha imediata, sem retry
$t0ms = microtime(true);
try {
    $http->getJson('https://api.binance.com/api/v3/fake_endpoint_xyz');
    check56('404 lança exceção', false);
} catch (\RuntimeException $e) {
    $elapsed = microtime(true) - $t0ms;
    check56('404 lança exceção', true);
    check56('404 falha imediata (sem retry, < 3s)', $elapsed < 3.0, round($elapsed, 2) . 's');
}

// 3. Domínio inexistente: retry com backoff e falha controlada
$t0ms = microtime(true);
try {
    $http->getJson('https://nao-existe-prometheus-test.invalid/api');
    check56('domínio inválido lança exceção', false);
} catch (\RuntimeException $e) {
    $elapsed = microtime(true) - $t0ms;
    check56('domínio inválido lança exceção', true);
    check56('retry com backoff executado (>= 2 tentativas, > 0.5s)', $elapsed >= 0.5, round($elapsed, 2) . 's');
    check56('falha controlada (sem fatal)', strpos($e->getMessage(), 'attempts') !== false || strpos($e->getMessage(), 'network') !== false);
}

// 4. Validação de payload JSON inválido
try {
    // endpoint que devolve HTML/texto, não JSON
    $http->getJson('https://api.binance.com/');
    check56('JSON inválido detectado', false);
} catch (\RuntimeException $e) {
    check56('JSON inválido detectado', strpos($e->getMessage(), 'Invalid JSON') !== false || strpos($e->getMessage(), 'status=') !== false);
}

// limpeza
Database::execute("DELETE FROM source_health WHERE source = 'test_probe'");

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) { echo 'Falhas: ' . implode('; ', $failures) . PHP_EOL; exit(1); }
exit(0);
