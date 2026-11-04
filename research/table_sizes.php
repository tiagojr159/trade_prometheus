<?php
declare(strict_types=1);

// Auditoria somente leitura para apoiar o plano de retenção SQL.
$config = require dirname(__DIR__) . '/config/database.php';
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['database'], $config['charset']);
$pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$tables = $pdo->query("SELECT TABLE_NAME, TABLE_ROWS, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb, ROUND(DATA_LENGTH / 1024 / 1024, 2) AS data_mb, ROUND(INDEX_LENGTH / 1024 / 1024, 2) AS index_mb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY DATA_LENGTH + INDEX_LENGTH DESC")->fetchAll(PDO::FETCH_ASSOC);
echo "TABLE\tROWS_ESTIMATE\tSIZE_MB\tDATA_MB\tINDEX_MB\n";
foreach ($tables as $table) {
    printf("%s\t%s\t%s\t%s\t%s\n", $table['TABLE_NAME'], $table['TABLE_ROWS'], $table['size_mb'], $table['data_mb'], $table['index_mb']);
}

echo "\nTEMPORAL_RANGES\n";
$timeColumns = [
    'system_logs' => 'created_at', 'llm_usage' => 'created_at', 'market_data' => 'open_time',
    'technical_indicators' => 'candle_time', 'signals' => 'created_at', 'market_regimes' => 'created_at',
    'news' => 'created_at', 'onchain_data' => 'observed_at', 'derivatives_data' => 'observed_at', 'macro_data' => 'observed_at',
    'backtest_results' => 'at_time', 'athena50_shadow_predictions' => 'created_at', 'predictions' => 'created_at',
    'paper_trading_decisions' => 'created_at', 'paper_trading_equity' => 'created_at',
];
$existing = array_column($tables, 'TABLE_NAME');
foreach ($timeColumns as $table => $column) {
    if (!in_array($table, $existing, true)) continue;
    $quotedTable = '`' . str_replace('`', '``', $table) . '`';
    $quotedColumn = '`' . str_replace('`', '``', $column) . '`';
    $stmt = $pdo->query("SELECT COUNT(*) AS n, MIN($quotedColumn) AS oldest, MAX($quotedColumn) AS newest FROM $quotedTable");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    printf("%s.%s\t%s\t%s\t%s\n", $table, $column, $row['n'], $row['oldest'] ?? 'NULL', $row['newest'] ?? 'NULL');
}
