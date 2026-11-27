<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\CronLock;
use Prometheus\core\Database;
use Prometheus\core\Arbitrage\StrategyEngine;
use Prometheus\core\Arbitrage\FundingRateCollector;
use Prometheus\core\Arbitrage\EventDrivenBacktester;
use Prometheus\core\Arbitrage\PortfolioLedger;
use Prometheus\core\Arbitrage\OrderBookCollector;
use Prometheus\core\Arbitrage\DatedFuturesCollector;
use Prometheus\core\Arbitrage\XemmEventReplay;
use Prometheus\core\Arbitrage\DexPoolCollector;
use Prometheus\core\Arbitrage\EvmGasCollector;
use Prometheus\core\Arbitrage\DexRouteSimulator;
use Prometheus\core\Arbitrage\DexCexRouteSimulator;

$command=$argv[1]??'';
if(!in_array($command,['schema','scan','collect-funding','collect-books','collect-basis','collect-dex','collect-gas','market-snapshot','backtest','init-portfolios','reconcile-portfolios'],true)){
 fwrite(STDERR,"Uso: php deploy/arbitrage.php schema | scan SNAPSHOT.json | collect-funding [BTCUSDT] | collect-books [BTCUSDT] | collect-basis [BTCUSDT] | collect-dex | collect-gas | market-snapshot [BTCUSDT] | backtest DATASET.json STRATEGY | init-portfolios KEY [CAPITAL] | reconcile-portfolios KEY [--repair]\n");exit(2);
}
$lock=new CronLock('cron_global');if(!$lock->acquire()){fwrite(STDERR,"Cron ou manutenção em execução.\n");exit(1);}
try{
 $db=Database::connection();
 if($command==='schema'){
  $name='arbitrage_schema:'.sha1((string)$db->query('SELECT DATABASE()')->fetchColumn());$q=$db->prepare('SELECT GET_LOCK(?,5)');$q->execute([$name]);
  if((int)$q->fetchColumn()!==1)throw new RuntimeException('SCHEMA_LOCK_UNAVAILABLE');
  try{foreach(['021_arbitrage_intelligence.sql','022_arbitrage_connector_health.sql','023_arbitrage_backtest_equity.sql','024_arbitrage_position_state.sql',
      '025_arbitrage_dex_pool_snapshots.sql','026_arbitrage_chain_gas_snapshots.sql'] as $migration){
   $sql=file_get_contents(PROMETHEUS_ROOT.'/sql/migrations/'.$migration);if($sql===false)throw new RuntimeException('MIGRATION_UNREADABLE');
   $sql=preg_replace('/^\s*--.*$/m','',$sql);foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement)$db->exec($statement);
  }
  }finally{$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);}
  echo "Schema de arbitragem disponível (migrations 021-026). Nenhuma carteira foi alterada.\n";
 }elseif($command==='collect-funding'){
  $result=(new FundingRateCollector())->collect($db,$argv[2]??'BTCUSDT');
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 }elseif($command==='collect-books'){
  $result=(new OrderBookCollector())->collect($db,$argv[2]??'BTCUSDT');
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 }elseif($command==='collect-basis'){
  $result=(new DatedFuturesCollector())->collect($db,$argv[2]??'BTCUSDT');
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 }elseif($command==='collect-dex'){
  $result=(new DexPoolCollector())->collect($db,DexPoolCollector::configuredPools());
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 }elseif($command==='collect-gas'){
  $result=(new EvmGasCollector())->collect($db,EvmGasCollector::configuredEndpoints());
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 }elseif($command==='market-snapshot'){
  $symbol=$argv[2]??'BTCUSDT';$funding=(new FundingRateCollector())->collect($db,$symbol);
  $books=(new OrderBookCollector())->collect($db,$symbol);$dated=(new DatedFuturesCollector())->collect($db,$symbol);
  $dex=(new DexPoolCollector())->collect($db,DexPoolCollector::configuredPools());
  $gas=(new EvmGasCollector())->collect($db,EvmGasCollector::configuredEndpoints());
  $result=['snapshot_at'=>$books['snapshot_at'],'symbol'=>$symbol,'funding'=>$funding['funding'],'books'=>$books['books'],
   'funding_connectors'=>['status'=>$funding['status'],'errors'=>$funding['errors']],
   'book_connectors'=>['status'=>$books['status'],'errors'=>$books['errors']],
   'dated_futures_connectors'=>['status'=>$dated['status'],'errors'=>$dated['errors']],
   'dex_connectors'=>['status'=>$dex['status'],'errors'=>$dex['errors']],
   'gas_connectors'=>['status'=>$gas['status'],'errors'=>$gas['errors']],
   'dex_pools'=>$dex['pools'],'chain_gas'=>$gas['gas'],'dex_route_configs'=>DexRouteSimulator::configuredRoutes(),
   'dex_cex_route_configs'=>DexCexRouteSimulator::configuredRoutes(),
   'prices'=>[],'basis'=>$dated['basis'],'xemm'=>[],'pairs'=>[],'triangular_edges'=>[],'dex_routes'=>[],
   'taker_fee_rate'=>0.001,'slippage_rate'=>0.0005,'notional'=>250,'virtual_equity'=>10000,'funding_horizon_days'=>1];
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 }elseif($command==='init-portfolios'){
  $key=$argv[2]??'research_default';$capital=isset($argv[3])?(float)$argv[3]:10000;
  $ledger=new PortfolioLedger($db);$ids=[];foreach(array_keys(StrategyEngine::registry()) as $strategy)
   $ids[$strategy]=$ledger->ensurePortfolio($strategy,$key,$capital,gmdate('Y-m-d H:i:s'));
  echo json_encode(['status'=>'READY','portfolio_key'=>$key,'initial_virtual_equity'=>$capital,'portfolios'=>$ids],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
 }elseif($command==='reconcile-portfolios'){
  $key=$argv[2]??'research_default';$repair=($argv[3]??'')==='--repair';$ledger=new PortfolioLedger($db);
  $q=$db->prepare('SELECT id,strategy_key FROM arbitrage_strategy_portfolios WHERE portfolio_key=? ORDER BY strategy_key');$q->execute([$key]);$results=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p)$results[$p['strategy_key']]=$ledger->reconcilePortfolio((int)$p['id'],$repair);
  echo json_encode(['status'=>'COMPLETED','portfolio_key'=>$key,'repair_applied'=>$repair,'results'=>$results],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
 }elseif($command==='backtest'){
  $path=$argv[2]??'';if(!is_file($path)||filesize($path)>5242880)throw new InvalidArgumentException('INVALID_DATASET');
  $dataset=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);$strategy=$argv[3]??'';
  if(!is_array($dataset)||!is_array($dataset['events']??null))throw new InvalidArgumentException('INVALID_DATASET');
  $result=$strategy==='CROSS_EXCHANGE_MM'
   ?(new XemmEventReplay())->run($dataset['events'],$dataset['config']??[])
   :(new EventDrivenBacktester())->run($strategy,$dataset['events'],static fn(array $snapshot):array=>$snapshot['signal']??['action'=>'NO_TRADE'],$dataset['config']??[]);
  (new EventDrivenBacktester())->persist($db,$result,gmdate('Y-m-d H:i:s'));
  $ledger=new PortfolioLedger($db);$portfolio=$ledger->ensurePortfolio($strategy,'backtest_'.substr($result['dataset_sha256'],0,16),(float)($dataset['config']['initial_capital']??10000),$result['test_start_utc']);
  foreach($result['trades'] as $i=>$trade)$ledger->recordClosedTrade($portfolio,substr($result['dataset_sha256'],0,32).':'.$strategy.':'.$i,[
   'symbol'=>$trade['symbol'],'gross_pnl'=>$trade['gross_pnl'],'fees'=>$trade['fees'],'funding_received'=>$trade['funding_received']??0,
   'funding_paid'=>$trade['funding_paid']??0,'capital_used'=>$trade['capital_used']??0,
   'slippage'=>$trade['slippage'],'opened_at_utc'=>$trade['opened_at_utc'],'closed_at_utc'=>$trade['closed_at_utc']], 'BACKTEST');
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 }else{
  $path=$argv[2]??'';if(!is_file($path)||filesize($path)>1048576)throw new InvalidArgumentException('INVALID_SNAPSHOT');
  $snapshot=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);if(!is_array($snapshot))throw new InvalidArgumentException('INVALID_SNAPSHOT');
  $result=(new StrategyEngine($db))->scan($snapshot,['max_opportunity_allocation_pct'=>5,'max_exchange_exposure_pct'=>25]);
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 }
}catch(Throwable $e){fwrite(STDERR,'Falha na arbitragem ('.get_class($e).'). Verifique o schema e os dados de entrada.'.PHP_EOL);exit(1);
}finally{$lock->release();}
