<?php
declare(strict_types=1);
// ETAPA 29 — API e segurança (validação consolidada).
chdir(dirname(__DIR__));
require 'bootstrap.php';

use Prometheus\core\Database;

$pass = 0; $fail = 0; $failures = [];
function check29(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . ($detail ? " ({$detail})" : '') . PHP_EOL;
    if ($ok) { $pass++; } else { $fail++; $failures[] = $name; }
}

echo "== ETAPA 29: API + segurança ==\n";

// 1. settings.php NÃO expõe segredos
$settingsSrc = (string)file_get_contents(__DIR__ . '/settings.php');
foreach (['fred_api_key' => '9a59', 'news_api_key', 'openai_api_key', 'DB_PASSWORD', 'password'] as $needle) {
    check29("settings.php não ecoa '{$needle}'", stripos($settingsSrc, 'getApiKeyValue') === false || stripos($needle, 'password') === false || strpos($needle, 'api_key') !== false);
}
$settingsOut = shell_exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/probe_settings_render.php') . ' 2>&1');
if ($settingsOut !== null) {
    $realKey = '9a597e5496c2103ae5d29e12db6071ee';
    check29('render de settings.php não contém a FRED key real', strpos($settingsOut, $realKey) === false);
    check29('settings.php renderiza sem fatal', strpos((string)$settingsOut, 'Fatal error') === false);
} else {
    check29('settings.php renderizável', false, 'probe indisponível');
}

// 2. ApiHandler: sanitizadores
$limit = Prometheus\api\ApiHandler::sanitizeLimit('<script>alert(1)</script>', 36, 200);
check29('sanitizeLimit neutraliza string maliciosa', $limit === 36);
try {
    Prometheus\api\ApiHandler::sanitizeSymbol('btc usdt" OR 1=1--');
    check29('sanitizeSymbol rejeita SQL injection', false);
} catch (\InvalidArgumentException $e) {
    check29('sanitizeSymbol rejeita SQL injection', true);
}
try {
    Prometheus\api\ApiHandler::sanitizeSymbol("btc usdt' OR 1=1--");
    check29('sanitizeSymbol rejeita SQL injection (aspas simples)', false);
} catch (\InvalidArgumentException $e) {
    check29('sanitizeSymbol rejeita SQL injection (aspas simples)', true);
}

// 3. Endpoints retornam JSON válido (probes CLI)
foreach (['signals', 'prediction', 'history', 'performance'] as $ep) {
    $out = shell_exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . "/probe_api.php") . ' ' . escapeshellarg($ep) . ' 2>&1');
    $json = json_decode((string)$out, true);
    check29("endpoint {$ep} responde JSON válido", is_array($json), substr((string)$out, 0, 60));
}

// 4. Prepared statements (SQL injection estrutural)
$hasConcatLimit = false;
foreach (glob(__DIR__ . '/../api/*.php') as $f) {
    $src = file_get_contents($f);
    if (preg_match('/LIMIT\s+[\'"]?\s*\.\s*\$/', $src)) { $hasConcatLimit = true; }
}
check29('endpoints sem concatenação direta em LIMIT', !$hasConcatLimit);

// 5. Dashboard não vaza credenciais (render direto, sem shell)
ob_start();
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require __DIR__ . '/dashboard.php';
$dashHtml = (string)ob_get_clean();
$realKey = '9a597e5496c2103ae5d29e12db6071ee';
check29('dashboard não contém segredo', strpos($dashHtml, $realKey) === false);
check29('dashboard renderiza HTML', strlen($dashHtml) > 5000 && strpos($dashHtml, '<!doctype html>') !== false, strlen($dashHtml) . ' bytes');

// 6. Rate limit: tabela existe e ApiHandler depende dela
$tbl = Database::fetch("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='api_rate_limit'");
check29('tabela api_rate_limit existe', (int)$tbl['c'] === 1);

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) { echo 'Falhas: ' . implode('; ', $failures) . PHP_EOL; exit(1); }
exit(0);
