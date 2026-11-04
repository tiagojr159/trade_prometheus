<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Database;

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'STRICT_ALL_TABLES')");
$dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
if ($dbName === '') {
    throw new RuntimeException('No active database selected.');
}

$tables = [
    'paper_trading_config', 'paper_trading_v2_accounts', 'paper_trading_decisions',
    'paper_trading_v2_orders', 'paper_trading_v2_trades', 'paper_trading_equity',
    'paper_trading_heartbeat',
];
$backup = [
    'created_at' => date(DATE_ATOM),
    'database' => $dbName,
    'tables' => [],
];

foreach ($tables as $table) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([$dbName, $table]);
    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException("Required table is missing: {$table}. Apply migrations 011 and 012 first.");
    }
    $quoted = '`' . $table . '`';
    $backup['tables'][$table]['schema'] = $db->query("SHOW CREATE TABLE {$quoted}")->fetch(PDO::FETCH_NUM)[1];
    if (in_array($table, ['paper_trading_config', 'paper_trading_v2_accounts', 'paper_trading_heartbeat'], true)) {
        $backup['tables'][$table]['rows'] = $db->query("SELECT * FROM {$quoted}")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $backup['tables'][$table]['row_count'] = (int)$db->query("SELECT COUNT(*) FROM {$quoted}")->fetchColumn();
    }
}

$backupDir = PROMETHEUS_ROOT . '/storage/backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    throw new RuntimeException('Cannot create storage/backups.');
}
$backupPath = $backupDir . '/paper_tournament_' . date('Ymd_His') . '.json';
$json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (file_put_contents($backupPath, $json, LOCK_EX) === false) {
    throw new RuntimeException('Failed to write the pre-migration backup. No migration was run.');
}

$migrationDir = PROMETHEUS_ROOT . '/sql/migrations';
$migrationFiles = [
    '013_paper_strategy_tournament.sql',
    '014_paper_tournament_allow_sample_orders.sql',
    '015_paper_trading_twelve_strategies.sql',
    '016_paper_heartbeat_waiting_data.sql',
];

$runStatement = static function (string $statement) use ($db): void {
    if (trim($statement) !== '') {
        $db->exec($statement);
    }
};

foreach ($migrationFiles as $file) {
    $path = $migrationDir . '/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read migration {$file}.");
    }
    fwrite(STDOUT, "Applying {$file}...\n");

    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $statement) {
        $runStatement($statement);
    }
}

$expected = ['PROMETHEUS', 'MULTIHORIZON', 'INVERSE', 'MOMENTUM', 'MEAN_REVERSION', 'BREAKOUT', 'ADAPTIVE', 'SPOT_GRID', 'SPOT_DCA', 'FUNDING_BIAS', 'REBALANCE', 'VWAP_TREND'];
$stmt = $db->query('SELECT mode FROM paper_trading_config ORDER BY mode');
$modes = $stmt->fetchAll(PDO::FETCH_COLUMN);
$missing = array_values(array_diff($expected, $modes));
if ($missing) {
    throw new RuntimeException('Migrations ran, but tournament verification failed. Missing modes: ' . implode(', ', $missing) . ". Backup: {$backupPath}");
}

$enum = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paper_trading_heartbeat' AND COLUMN_NAME = 'status'")->fetchColumn();
if (!is_string($enum) || strpos($enum, 'WAITING_DATA') === false) {
    throw new RuntimeException("Tournament modes verified, but WAITING_DATA is missing from the heartbeat status enum. Backup: {$backupPath}");
}

fwrite(STDOUT, "Upgrade complete. Database: {$dbName}\n");
fwrite(STDOUT, 'Verified strategy accounts: ' . count($expected) . "\n");
fwrite(STDOUT, "Backup: {$backupPath}\n");
