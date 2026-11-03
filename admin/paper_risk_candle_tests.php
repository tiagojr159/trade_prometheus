<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__).'/bootstrap.php';
use Prometheus\core\Database;
use Prometheus\core\CronLock;
use Prometheus\core\PaperTrader;
$lock=new CronLock('cron_global');
if (!$lock->acquire()) throw new RuntimeException('Cron em execução.');
$db=Database::connection();
try {
    $row=$db->query('SELECT p.*,d.id AS decision_id,o.market_data_id AS entry_market_data_id FROM predictions p JOIN paper_trading_decisions d ON d.prediction_id=p.id AND d.mode="PROMETHEUS" JOIN paper_trading_v2_orders o ON o.decision_id=d.id AND o.event_type="OPEN_LONG" ORDER BY o.id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Sem ordem LONG para teste isolado.');
    $market=$db->query('SELECT id,close_price,open_time,close_time,available_at,ingested_at FROM market_data WHERE symbol="BTCUSDT" AND interval_name="1m" ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$market || (int)$market['id']===(int)$row['entry_market_data_id']) throw new RuntimeException('Candle independente indisponível.');
    $config=$db->query('SELECT * FROM paper_trading_config WHERE mode="PROMETHEUS"')->fetch(PDO::FETCH_ASSOC);
    $account=$db->query('SELECT * FROM paper_trading_v2_accounts WHERE mode="PROMETHEUS"')->fetch(PDO::FETCH_ASSOC);
    $db->beginTransaction();
    $account['position_side']='LONG';$account['entry_prediction_id']=$row['id'];$account['entry_market_data_id']=$row['entry_market_data_id'];
    $account['entry_at']=date('Y-m-d H:i:s',time()-4200);$account['entry_price']=$market['close_price'];
    $account['quantity_btc']=0.0001;$account['entry_fee_usd']=0.01;$account['entry_slippage_usd']=0.002;
    $account['entry_reason']='Teste transacional';$account['reserved_cash']=0;
    $trader=new PaperTrader();$method=new ReflectionMethod(PaperTrader::class,'closeOnMarketRisk');$method->setAccessible(true);
    $args=[$db,'PROMETHEUS',&$account,$market,$config,'Tempo máximo de teste.'];
    if (!$method->invokeArgs($trader,$args)) throw new RuntimeException('Saída por risco não aconteceu.');
    if ($account['position_side']!=='FLAT') throw new RuntimeException('Posição não fechou.');
    $event=$db->query('SELECT * FROM paper_trading_risk_events ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $order=$db->query('SELECT * FROM paper_trading_v2_orders ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $trade=$db->query('SELECT * FROM paper_trading_v2_trades ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$event || !$order || !$trade || (int)$order['risk_event_id']!==(int)$event['id'] || (int)$trade['exit_market_data_id']!==(int)$market['id']) throw new RuntimeException('Auditoria de risco incompleta.');
    $db->rollBack();echo "PASS: saída por candle, ordem e trade ligados ao evento de risco; rollback completo.\n";
} finally { if ($db->inTransaction()) $db->rollBack(); $lock->release(); }
