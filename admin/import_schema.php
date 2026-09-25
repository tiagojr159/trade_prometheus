<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

$sqlFile = PROMETHEUS_ROOT . '/sql/prometheus.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    throw new RuntimeException('Unable to read SQL file: ' . $sqlFile);
}

$statements = [];
$buffer = '';
foreach (preg_split('/\R/', $sql) as $line) {
    $trim = trim($line);
    if ($trim === '' || substr($trim, 0, 2) === '--') {
        continue;
    }
    $buffer .= $line . PHP_EOL;
    if (substr($trim, -1) === ';') {
        $statements[] = trim($buffer);
        $buffer = '';
    }
}
if (trim($buffer) !== '') {
    $statements[] = trim($buffer);
}

$executed = 0;
foreach ($statements as $statement) {
    if (preg_match('/^CREATE\s+DATABASE\b/i', $statement) || preg_match('/^USE\s+/i', $statement)) {
        continue;
    }
    Database::execute($statement);
    $executed++;
}

echo json_encode(['executed' => $executed], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
