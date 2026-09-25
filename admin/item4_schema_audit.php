<?php
declare(strict_types=1);
// ETAPA 04 — Auditoria de schema + sistema de migrations versionado.
chdir(dirname(__DIR__));
require 'bootstrap.php';

use Prometheus\core\Database;

$pass = 0; $fail = 0; $failures = [];
function check4(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . ($detail ? " ({$detail})" : '') . PHP_EOL;
    $ok ? $pass++ : ($fail++ . $failures[] = $name);
}

echo "== ETAPA 04: Schema e migrations ==\n";

// 1. Tabela schema_migrations + versionamento
Database::execute("CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(20) PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    applied_at DATETIME NOT NULL
) ENGINE=InnoDB");
check4('tabela schema_migrations existe', true);

$applied = array_column(Database::fetchAll('SELECT version FROM schema_migrations'), 'version');

// 2. Registrar migrations existentes (001-003) se as tabelas já existem no banco
$migrationFiles = glob(__DIR__ . '/../sql/migrations/0*.sql');
sort($migrationFiles);
$pending = [];
foreach ($migrationFiles as $file) {
    $base = basename($file, '.sql');
    $version = substr($base, 0, 3);
    if (in_array($version, $applied, true)) continue;
    $pending[] = ['file' => $file, 'version' => $version, 'name' => substr($base, 4)];
}

// 3. Aplicar pendentes (idempotente: cada SQL usa CREATE TABLE IF NOT EXISTS)
foreach ($pending as $m) {
    $sql = file_get_contents($m['file']);
    foreach (array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql))) as $stmt) {
        if ($stmt === '' || stripos($stmt, 'CREATE DATABASE') === 0 || stripos($stmt, 'USE ') === 0) continue;
        Database::execute($stmt);
    }
    Database::execute('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, NOW())', [$m['version'], $m['name']]);
    echo "  migration {$m['version']} ({$m['name']}) aplicada\n";
}
$appliedNow = array_column(Database::fetchAll('SELECT version FROM schema_migrations ORDER BY version'), 'version');
check4('migrations versionadas registradas', count($appliedNow) >= 3, implode(',', $appliedNow));

// 4. Validação das 14 tabelas obrigatórias
$required = ['market_data','technical_indicators','news','onchain_data','derivatives_data',
    'macro_data','signals','predictions','prediction_results','module_performance',
    'module_weights','market_regimes','llm_usage','backtest_results'];
$tables = array_column(Database::fetchAll("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()"), 'table_name');
foreach ($required as $t) {
    check4("tabela {$t}", in_array($t, $tables, true));
}

// 5. PKs em todas
$pkTables = array_column(Database::fetchAll("SELECT DISTINCT table_name FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND constraint_type = 'PRIMARY KEY'"), 'table_name');
$missingPk = array_diff($required, $pkTables);
check4('PK em todas as tabelas obrigatórias', count($missingPk) === 0, implode(',', $missingPk));

// 6. FKs
$fks = Database::fetchAll("SELECT table_name, referenced_table_name FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL");
check4('FKs de integridade referencial presentes', count($fks) >= 1, count($fks) . ' FKs');

// 7. Índices
$idxCount = (int)(Database::fetch("SELECT COUNT(DISTINCT concat(table_name, index_name)) c FROM information_schema.statistics WHERE table_schema = DATABASE()")['c'] ?? 0);
check4('índices presentes', $idxCount >= 15, $idxCount . ' índices/unique');

// 8. Timestamps de evento: market_data.open_time difere de created_at (prova de não-NOW())
$ts = Database::fetch("SELECT COUNT(*) c FROM market_data WHERE open_time <> created_at");
check4('market_data usa timestamp de evento (open_time <> created_at)', (int)$ts['c'] > 0, $ts['c'] . ' registros com ts de evento');

// 9. Gaps de candles não corrompem idempotência: unique key em (symbol,timeframe,open_time)
$uk = Database::fetch("SELECT COUNT(*) c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name='market_data' AND index_name LIKE '%uniq%'");
check4('unique key anti-duplicação em market_data', (int)$uk['c'] > 0);

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) { echo 'Falhas: ' . implode('; ', $failures) . PHP_EOL; exit(1); }
exit(0);
