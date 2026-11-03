<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/bootstrap.php';
use Prometheus\core\Database;
$db=Database::connection();
$checks=[
 'clock'=>$db->query('SELECT NOW() AS db_now, UTC_TIMESTAMP() AS db_utc')->fetch(),
 'shadow'=>$db->query('SELECT model_status,COUNT(*) AS n FROM athena50_shadow_predictions GROUP BY model_status')->fetchAll(),
 'latest_prediction'=>$db->query('SELECT id,created_at FROM predictions ORDER BY id DESC LIMIT 1')->fetch(),
 'market'=>$db->query('SELECT close_time,ingested_at FROM market_data WHERE symbol="BTCUSDT" AND interval_name="1m" ORDER BY open_time DESC LIMIT 1')->fetch(),
 'risk_events'=>(int)$db->query('SELECT COUNT(*) FROM paper_trading_risk_events')->fetchColumn(),
 'gaps'=>(int)$db->query('SELECT COUNT(*) FROM prediction_evaluation_gaps')->fetchColumn(),
 'heartbeats'=>$db->query('SELECT mode,status,last_finished_at,last_error FROM paper_trading_heartbeat WHERE mode NOT LIKE "LEGACY%" ORDER BY mode')->fetchAll(),
];
echo json_encode($checks,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
