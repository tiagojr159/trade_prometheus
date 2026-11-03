<?php
declare(strict_types=1);
// Isolated read-only snapshot: no application bootstrap, migrations or trading jobs.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$c = require __DIR__ . '/../config/database.php';
$db = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['database']};charset=utf8mb4", $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
$dir=__DIR__.'/../storage/research';
if (!is_dir($dir)) mkdir($dir,0775,true);
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$schema=[];
foreach ($tables as $t) $schema[$t]=$db->query("DESCRIBE `$t`")->fetchAll();
file_put_contents($dir.'/schema.json',json_encode($schema,JSON_PRETTY_PRINT));
$queries=[
 'predictions'=>'SELECT p.*,r.final_price,r.actual_direction,r.return_pct,r.directional_hit,r.evaluation_version,r.evaluated_at FROM predictions p LEFT JOIN prediction_results r ON r.prediction_id=p.id ORDER BY p.created_at,p.id',
 'market'=>"SELECT * FROM market_data WHERE symbol='BTCUSDT' AND interval_name IN ('1m','15m') AND close_time >= (SELECT DATE_SUB(MIN(created_at),INTERVAL 2 DAY) FROM predictions) ORDER BY close_time,id",
 'config'=>'SELECT * FROM paper_trading_config',
 'accounts'=>'SELECT * FROM paper_trading_v2_accounts',
 'trades'=>'SELECT * FROM paper_trading_v2_trades ORDER BY exit_at,id',
 'orders'=>'SELECT * FROM paper_trading_v2_orders ORDER BY id',
 'heartbeat'=>'SELECT * FROM paper_trading_heartbeat',
 'equity'=>'SELECT * FROM paper_trading_equity ORDER BY id',
 'decision_counts'=>'SELECT mode,decision,reason_codes,COUNT(*) n,MIN(created_at) first_at,MAX(created_at) last_at FROM paper_trading_decisions GROUP BY mode,decision,reason_codes',
 'logs'=>'SELECT level,event,COUNT(*) n,MIN(created_at) first_at,MAX(created_at) last_at FROM system_logs GROUP BY level,event',
 'clock'=>'SELECT NOW() db_now,UTC_TIMESTAMP() db_utc,@@session.time_zone session_timezone',
 'shadow_summary'=>'SELECT horizon,model_status,COUNT(*) n,MIN(prediction_created_at) first_at,MAX(prediction_created_at) last_at,AVG(classic_hit) classic_hit,AVG(shadow_hit) shadow_hit,AVG(classic_brier) classic_brier,AVG(shadow_brier) shadow_brier FROM athena50_shadow_predictions GROUP BY horizon,model_status',
 'shadow_errors'=>"SELECT event,LEFT(JSON_UNQUOTE(JSON_EXTRACT(context,'$.message')),500) message,created_at FROM system_logs WHERE event IN ('athena50_shadow_failed','athena50_shadow_evaluation_unavailable') ORDER BY id DESC LIMIT 4",
 'shadow_summary'=>'SELECT horizon,model_status,COUNT(*) n,MIN(prediction_created_at) first_at,MAX(prediction_created_at) last_at,AVG(classic_hit) classic_hit,AVG(shadow_hit) shadow_hit,AVG(classic_brier) classic_brier,AVG(shadow_brier) shadow_brier FROM athena50_shadow_predictions GROUP BY horizon,model_status',
 'shadow_errors'=>"SELECT event,LEFT(JSON_UNQUOTE(JSON_EXTRACT(context,'$.message')),500) message,created_at FROM system_logs WHERE event IN ('athena50_shadow_failed','athena50_shadow_evaluation_unavailable') ORDER BY id DESC LIMIT 4",
];
foreach($queries as $name=>$sql){
 $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
 $stmt=$db->query($sql); $f=fopen($dir.'/'.$name.'.json','wb'); fwrite($f,'['); $n=0;
 while($row=$stmt->fetch()){if($n++) fwrite($f,','); fwrite($f,json_encode($row,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));}
 fwrite($f,']'); fclose($f); $stmt->closeCursor();
 echo $name.': '.$n.PHP_EOL;
}
$db->exec('ROLLBACK');
