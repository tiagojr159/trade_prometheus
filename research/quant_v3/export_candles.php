<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__, 2) . '/bootstrap.php';

use Prometheus\core\Database;

$db = Database::connection();
$db->exec('START TRANSACTION READ ONLY');
try {
    $sql = 'SELECT id,open_time,close_time,open_price,high_price,low_price,close_price,volume,
        available_at,ingested_at,temporal_quality FROM market_data
        WHERE symbol=? AND source=? AND interval_name=?
        ORDER BY open_time,id';
    $stmt = $db->prepare($sql);
    $stmt->execute(['BTCUSDT', 'binance', '1m']);
    echo json_encode(['source' => 'binance', 'symbol' => 'BTCUSDT', 'market_type' => 'SPOT',
        'interval' => '1m', 'dataset_kind' => 'RECORDED_AS_OF', 'legacy_timezone' => PROMETHEUS_TIMEZONE,
        'exported_at_utc' => gmdate('Y-m-d\TH:i:s\Z')], JSON_THROW_ON_ERROR) . PHP_EOL;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode($row, JSON_THROW_ON_ERROR) . PHP_EOL;
    }
    $db->exec('ROLLBACK');
} catch (Throwable $e) {
    $db->exec('ROLLBACK');
    fwrite(STDERR, "Falha ao exportar candles.\n");
    exit(1);
}
