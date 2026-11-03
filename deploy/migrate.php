<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/bootstrap.php';
use Prometheus\core\Database;
use Prometheus\core\CronLock;
use Prometheus\core\AuditSchemaMigration;
$lock = new CronLock('cron_global');
if (!$lock->acquire()) { fwrite(STDERR,"Cron em execução; tente novamente.\n"); exit(1); }
try {
    $db = Database::connection();
    $backup = ['created_at'=>date(DATE_ATOM), 'tables'=>[]];
    foreach (['athena50_shadow_predictions','market_data','prediction_results','paper_trading_v2_orders','paper_trading_config','paper_trading_v2_accounts'] as $table) {
        $backup['tables'][$table]['schema'] = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        if (in_array($table,['paper_trading_config','paper_trading_v2_accounts'],true)) $backup['tables'][$table]['rows']=$db->query("SELECT * FROM `$table`")->fetchAll();
    }
    $dir=PROMETHEUS_ROOT.'/storage/backups';
    if (!is_dir($dir) && !mkdir($dir,0775,true)) throw new RuntimeException('Backup directory unavailable.');
    $path=$dir.'/audit_schema_'.date('Ymd_His').'.json';
    if (file_put_contents($path,json_encode($backup,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false) throw new RuntimeException('Backup falhou.');
    echo json_encode(['backup'=>$path,'added_columns'=>AuditSchemaMigration::apply($db)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally { $lock->release(); }
