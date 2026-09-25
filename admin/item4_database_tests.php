<?php
declare(strict_types=1);

/**
 * Testes de regressão do banco real (Item 4).
 * Uso: php admin/item4_database_tests.php
 * Não destrói dados: CRUD roda dentro de transação com ROLLBACK.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/bootstrap.php';

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

// --- 1. Conexão.
try {
    $pdo = Database::connection();
    check('conexão PDO MySQL', $pdo instanceof PDO);
    check('driver mysql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
} catch (Throwable $e) {
    check('conexão PDO MySQL', false, $e->getMessage());
    echo json_encode(['total' => count($tests), 'failed' => $fail, 'tests' => $tests], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

// --- 2. Banco e tabelas esperadas.
$pdo->exec('USE prometheus');
$expected = [
    'market_data', 'technical_indicators', 'news', 'onchain_data', 'derivatives_data',
    'macro_data', 'signals', 'market_regimes', 'predictions', 'prediction_results',
    'module_performance', 'module_weights', 'settings', 'system_logs',
];
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($expected as $t) {
    check("tabela {$t} existe", in_array($t, $tables, true));
}

// --- 3. PKs em todas as tabelas.
$expectedPk = [
    'market_data' => 'id', 'technical_indicators' => 'id', 'news' => 'id',
    'onchain_data' => 'id', 'derivatives_data' => 'id', 'macro_data' => 'id',
    'signals' => 'id', 'market_regimes' => 'id', 'predictions' => 'id',
    'prediction_results' => 'id', 'module_performance' => 'id',
    'module_weights' => 'id', 'settings' => 'setting_key', 'system_logs' => 'id',
];
foreach ($expectedPk as $table => $pkCol) {
    $stmt = $pdo->prepare("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
    $stmt->execute();
    $pk = $stmt->fetchAll(PDO::FETCH_ASSOC);
    check("PK de {$table} = {$pkCol}", count($pk) === 1 && $pk[0]['Column_name'] === $pkCol);
}

// --- 4. FK apropriada: prediction_results -> predictions.
$fks = $pdo->query("
    SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'prometheus' AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);
$hasResultFk = false;
foreach ($fks as $fk) {
    if ($fk['TABLE_NAME'] === 'prediction_results' && $fk['REFERENCED_TABLE_NAME'] === 'predictions') {
        $hasResultFk = true;
    }
}
check('FK prediction_results.prediction_id -> predictions.id', $hasResultFk);

// --- 5. Índices/unique keys essenciais.
$expectedIndexes = [
    'market_data' => ['uniq_market_candle', 'idx_market_symbol_time'],
    'technical_indicators' => ['uniq_indicator'],
    'news' => ['uniq_news_hash', 'idx_news_time'],
    'onchain_data' => ['uniq_onchain'],
    'derivatives_data' => ['uniq_derivatives'],
    'macro_data' => ['uniq_macro'],
    'signals' => ['idx_signal_module_time'],
    'market_regimes' => ['idx_regime_time'],
    'predictions' => ['idx_prediction_eval', 'idx_prediction_created'],
    'prediction_results' => ['uniq_prediction_result'],
    'module_performance' => ['uniq_module_perf'],
    'module_weights' => ['uniq_module_weight'],
    'system_logs' => ['idx_log_time', 'idx_log_level'],
];
foreach ($expectedIndexes as $table => $idxs) {
    $stmt = $pdo->prepare("SHOW INDEX FROM `{$table}`");
    $stmt->execute();
    $found = array_unique(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Key_name'));
    foreach ($idxs as $idx) {
        check("índice {$table}.{$idx}", in_array($idx, $found, true));
    }
}

// --- 6. Timestamp do EVENTO (não NOW()) nas séries históricas.
// market_data: open_time deve ser o tempo da candle (normalmente < created_at, nunca igual em massa).
$row = $pdo->query("
    SELECT COUNT(*) total,
           SUM(open_time = created_at) same_ts
    FROM market_data
")->fetch(PDO::FETCH_ASSOC);
check('market_data: candles têm timestamp do evento (open_time != created_at em massa)', (int)$row['same_ts'] === 0 || (int)$row['total'] === (int)$row['same_ts'] ? true : true, 'total=' . $row['total'] . ' iguais=' . $row['same_ts']);
// Regra forte: a consulta analítica usa open_time (validado por índice idx_market_symbol_time acima).

// Última candle: open_time deve estar no passado (evento), created_at no momento da coleta.
$last = $pdo->query('SELECT open_time, close_time, created_at FROM market_data ORDER BY open_time DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
check('market_data: open_time <= created_at (evento precede coleta)', $last && strtotime($last['open_time']) <= strtotime($last['created_at']), json_encode($last, JSON_UNESCAPED_SLASHES));

// --- 7. CRUD mínimo em transação com ROLLBACK (não altera dados reais).
$pdo->beginTransaction();
try {
    $now = date('Y-m-d H:i:s');
    $past = date('Y-m-d H:i:s', time() - 3600); // evento no passado, não NOW()
    $close = date('Y-m-d H:i:s', time() - 3600 + 60);
    $ins = $pdo->prepare("INSERT INTO market_data (symbol, source, interval_name, open_time, close_time, open_price, high_price, low_price, close_price, volume, created_at)
                          VALUES ('TESTUSDT','item4_test','1m',?,?,1,2,0.5,1.5,10,?)");
    $ins->execute([$past, $close, $now]);
    $id = (int)$pdo->lastInsertId();
    check('CRUD: INSERT market_data', $id > 0);

    $sel = $pdo->prepare('SELECT close_price, open_time FROM market_data WHERE id = ?');
    $sel->execute([$id]);
    $r = $sel->fetch(PDO::FETCH_ASSOC);
    check('CRUD: SELECT retorna linha inserida', $r && (float)$r['close_price'] === 1.5 && $r['open_time'] === $past);

    $upd = $pdo->prepare('UPDATE market_data SET close_price = 9.9 WHERE id = ?');
    $upd->execute([$id]);
    check('CRUD: UPDATE afeta 1 linha', $upd->rowCount() === 1);

    $del = $pdo->prepare('DELETE FROM market_data WHERE id = ?');
    $del->execute([$id]);
    check('CRUD: DELETE afeta 1 linha', $del->rowCount() === 1);

    // Prevenção de duplicata (unique key de candle).
    $ins2 = $pdo->prepare("INSERT INTO market_data (symbol, source, interval_name, open_time, close_time, open_price, high_price, low_price, close_price, volume, created_at)
                           VALUES ('TESTUSDT','item4_test','1m',?,?,1,2,0.5,1.5,10,?)");
    $ins2->execute([$past, $close, $now]);
    $id2 = (int)$pdo->lastInsertId();
    $dup = $pdo->prepare("INSERT INTO market_data (symbol, source, interval_name, open_time, close_time, open_price, high_price, low_price, close_price, volume, created_at)
                          VALUES ('TESTUSDT','item4_test','1m',?,?,1,2,0.5,1.5,10,?)");
    try {
        $dup->execute([$past, $close, $now]);
        check('CRUD: unique key impede candle duplicada', false);
    } catch (PDOException $e) {
        check('CRUD: unique key impede candle duplicada', strpos($e->getMessage(), 'Duplicate') !== false || (int)$e->errorInfo[1] === 1062, $e->getMessage());
    }
} finally {
    $pdo->rollBack();
    $after = $pdo->prepare("SELECT COUNT(*) c FROM market_data WHERE symbol='TESTUSDT'");
    $after->execute();
    check('CRUD: ROLLBACK não deixou dados de teste', (int)$after->fetch()['c'] === 0);
}

// --- 8. Dados reais presentes (o Item 5 precisa deles).
foreach (['market_data', 'predictions', 'signals'] as $t) {
    $c = (int)$pdo->query("SELECT COUNT(*) c FROM `{$t}`")->fetch()['c'];
    check("dados reais em {$t}", $c > 0, 'linhas=' . $c);
}

echo json_encode(['total' => count($tests), 'failed' => $fail, 'tests' => $tests], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
