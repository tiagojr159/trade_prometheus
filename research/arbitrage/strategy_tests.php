<?php
declare(strict_types=1);

use Prometheus\core\Arbitrage\CashAndCarry;
use Prometheus\core\Arbitrage\TriangularArbitrage;
use Prometheus\core\Arbitrage\MevArbitrage;
use Prometheus\core\Arbitrage\BasisAnalysisEngine;
use Prometheus\core\Arbitrage\XemmEventReplay;
use Prometheus\core\Arbitrage\CrossExchangeMarketMaking;
use Prometheus\core\Arbitrage\FundingRateArbitrage;
use Prometheus\core\Arbitrage\StrategyEngine;
use Prometheus\core\Arbitrage\TradingStrategy;
use Prometheus\core\Arbitrage\StatisticalAnalysisEngine;
use Prometheus\core\Arbitrage\ExecutionSimulator;
use Prometheus\core\Arbitrage\PerformanceAnalytics;
use Prometheus\core\Arbitrage\EventDrivenBacktester;
use Prometheus\core\Arbitrage\RiskManagementEngine;
use Prometheus\core\Arbitrage\MarketRegimeDetector;
use Prometheus\core\Arbitrage\OrderBookCollector;
use Prometheus\core\Arbitrage\BlockBootstrap;
use Prometheus\core\Arbitrage\StrategyAllocationEngine;
use Prometheus\core\Arbitrage\MarginStressEngine;
use Prometheus\core\Arbitrage\DexPoolCollector;
use Prometheus\core\Arbitrage\EvmGasCollector;
use Prometheus\core\Arbitrage\DexRouteSimulator;
use Prometheus\core\Arbitrage\DexCexRouteSimulator;
use Prometheus\core\Arbitrage\FundingRateAnalytics;
use Prometheus\core\Arbitrage\XemmFillProbabilityCalibrator;
use Prometheus\core\Arbitrage\XemmQuotePlanner;

$base=dirname(__DIR__,2).'/core/Arbitrage/';
foreach(['TradingStrategy','AbstractStrategy','FundingRateArbitrage','CashAndCarry','CrossExchangeMarketMaking','StatisticalAnalysisEngine','StatisticalArbitrage','SpatialArbitrage','TriangularArbitrage','MevArbitrage','BasisAnalysisEngine','XemmEventReplay','StrategyEngine','ExecutionSimulator','PerformanceAnalytics','BlockBootstrap','StrategyAllocationEngine','EventDrivenBacktester','MarginStressEngine','RiskManagementEngine','MarketRegimeDetector','OrderBookCollector','DexPoolCollector','EvmGasCollector','DexRouteSimulator','DexCexRouteSimulator','FundingRateAnalytics','XemmFillProbabilityCalibrator','XemmQuotePlanner'] as $file) require_once $base.$file.'.php';
$assert=static function(bool $condition,string $name):void { if(!$condition)throw new RuntimeException('FAIL '.$name); echo "PASS $name\n"; };
$assert(count(StrategyEngine::registry())===7,'seven registered strategy modules');

$funding=new FundingRateArbitrage();$assert($funding instanceof TradingStrategy,'funding implements strategy interface');
$fundingSnapshot=['notional'=>100000,'funding_horizon_days'=>1,'taker_fee_rate'=>0.0001,'slippage_rate'=>0.00001];
$fundingSnapshot['funding']=['BTCUSDT'=>[
 'A'=>['rate'=>0.0001,'interval_hours'=>8,'observed_at'=>'2026-01-01 00:00:00'],
 'B'=>['rate'=>0.0004,'interval_hours'=>8,'observed_at'=>'2026-01-01 00:00:00']]];
$book=['bid'=>100,'ask'=>100,'bid_qty'=>5,'ask_qty'=>5,'mark'=>100,
 'bids'=>[['price'=>100,'quantity'=>5]],'asks'=>[['price'=>100,'quantity'=>5]]];
$fundingSnapshot['books']=['BTCUSDT'=>['A'=>$book,'B'=>$book]];
$f=$funding->generateSignals($fundingSnapshot);
$assert(count($f)===2,'funding pair permutations generated');
$op=$f[0]['details']['normalized_daily_spread']>0?$f[0]:$f[1];
$assert(abs($op['details']['normalized_daily_spread']-0.0009)<1e-12,'funding intervals normalized to daily rate');
$assert($op['net_profit']>0&&$op['net_profit']<$op['gross_profit'],'funding income net of fees, basis and slippage');
$fundingSim=$funding->simulateExecution($op,array_merge($fundingSnapshot,['taker_fee_rate'=>0.0001,'books'=>$fundingSnapshot['books']]));
$assert($fundingSim['status']==='HEDGED'&&$fundingSim['funding_income_realized']===0.0,'funding legs are depth-simulated and income stays unsettled');
$assert($fundingSim['mode']==='SIMULATION_ONLY','funding simulation cannot transmit real orders');

$carry=new CashAndCarry();$carrySnapshot=['notional'=>10000,'taker_fee_rate'=>0.001];
$carrySnapshot['basis']=[['symbol'=>'BTCUSDT','spot_ask'=>100,'future_bid'=>100.5,'days_to_expiration'=>30,
 'borrow_cost'=>5,'slippage_cost'=>1,'observed_at'=>'2026-01-01 00:00:00',
 'spot_book'=>['asks'=>[['price'=>100,'quantity'=>200]],'bids'=>[['price'=>99,'quantity'=>200]]],
 'future_book'=>['asks'=>[['price'=>101,'quantity'=>200]],'bids'=>[['price'=>100.5,'quantity'=>200]]]]];
$cc=$carry->generateSignals($carrySnapshot);
$assert(count($cc)===1,'cash and carry opportunity generated');
$assert(abs($cc[0]['gross_profit']-50)<1e-8&&abs($cc[0]['net_profit']-4)<1e-8,'cash and carry includes opening and closing fees plus other costs');
$assert(abs($cc[0]['details']['annualized_basis']-0.0608333333333333)<1e-12,'dated basis annualization uses expiration horizon');
$assert(empty($cc[0]['data_quality']),'cash-and-carry rejects incomplete cost-model evidence');
$carrySim=$carry->simulateExecution($cc[0],array_merge($carrySnapshot,['taker_fee_rate'=>0.001]));
$assert($carrySim['status']==='OPENED_SIMULATED_CASH_AND_CARRY'&&$carrySim['convergence_pnl_realized']===0.0,'cash and carry simulates both opening legs without inventing realized convergence');

$xemm=new CrossExchangeMarketMaking();
$makerBook=['bid'=>100.2,'ask'=>101,'bid_qty'=>4,'ask_qty'=>4];
$hedgeBook=['bid'=>99.9,'ask'=>100,'bid_qty'=>5,'ask_qty'=>5];
$hedgeBook['asks']=[['price'=>100,'quantity'=>5]];$hedgeBook['bids']=[['price'=>99.9,'quantity'=>5]];
$xemmRow=['symbol'=>'BTCUSDT','maker_side'=>'SELL','quantity'=>3,'maker_fee_rate'=>0.0001,'taker_fee_rate'=>0.0001,
 'fill_probability'=>0.8,'maker_fill_confirmed'=>true,'latency_ms'=>40,'observed_at'=>'2026-01-01 00:00:00',
 'slippage_cost'=>0.02,'adverse_selection_cost'=>0.01,'maker_book'=>$makerBook,'hedge_book'=>$hedgeBook];
$xemmSnapshot=['xemm'=>[$xemmRow]];
$xm=$xemm->generateSignals($xemmSnapshot);
$assert(count($xm)===1&&$xm[0]['net_profit']>0,'XEMM uses executable bid/ask depth, fill probability and adverse selection');
$assert(empty($xm[0]['data_quality'])&&$xm[0]['details']['fill_probability_status']==='UNVALIDATED','XEMM uncalibrated fill odds cannot pass data-quality gate');
$calFeatures=['maker_side'=>'SELL','spread_bps'=>40.0,'latency_ms'=>40,'depth_ratio'=>0.75];$trainFills=[];$validFills=[];
for($i=0;$i<60;$i++){$trainFills[]=$calFeatures+['filled'=>$i<48,'available_at_utc'=>gmdate('Y-m-d H:i:s',strtotime('2026-01-01 00:00:00 UTC')+$i*60)];
 $validFills[]=$calFeatures+['filled'=>$i<48,'available_at_utc'=>gmdate('Y-m-d H:i:s',strtotime('2026-01-02 00:00:00 UTC')+$i*60)];}
$fillCal=(new XemmFillProbabilityCalibrator())->estimate($trainFills,$validFills,$calFeatures,'2026-01-03 00:00:00');
$assert($fillCal['status']==='CALIBRATED'&&abs($fillCal['probability']-0.8)<1e-12&&$fillCal['validation_brier_score']>=0,
 'XEMM fill estimator uses an empirical feature cohort and passes a chronological calibration holdout');
$futureFill=$validFills;$futureFill[]=$calFeatures+['filled'=>true,'available_at_utc'=>'2026-01-04 00:00:00'];
$futureCal=(new XemmFillProbabilityCalibrator())->estimate($trainFills,$futureFill,$calFeatures,'2026-01-03 00:00:00');
$assert($futureCal['status']==='FUTURE_LABELS_REJECTED'&&$futureCal['probability']===null,
 'XEMM fill estimator rejects future labels instead of leaking them into calibration');
$xemmCalRow=$xemmRow+['spread_bps'=>40.0,'available_at_utc'=>'2026-01-03 00:00:00','fill_probability_calibrated'=>false];
$xemmCalRow['observed_at']='2026-01-03 00:00:00';$xemmCalRow['maker_book']['observed_at']='2026-01-03 00:00:00';
$xemmCalRow['hedge_book']['observed_at']='2026-01-03 00:00:00';
$xemmCalSnapshot=['snapshot_at'=>'2026-01-03 00:00:00','xemm'=>[$xemmCalRow],
 'xemm_fill_history'=>['training'=>$trainFills,'validation'=>$validFills]];
$xemmCalSignal=$xemm->generateSignals($xemmCalSnapshot);
$assert(count($xemmCalSignal)===1&&!empty($xemmCalSignal[0]['data_quality'])
 &&$xemmCalSignal[0]['details']['fill_probability_status']==='CALIBRATED',
 'XEMM strategy uses only a validated historical fill-probability estimate');
$quoteBooks=['BTCUSDT'=>['M'=>['bid'=>100,'ask'=>101,'bid_qty'=>5,'ask_qty'=>5,'bids'=>[['price'=>100,'quantity'=>5]],
 'asks'=>[['price'=>101,'quantity'=>5]],'observed_at'=>'2026-01-03 00:00:00'],
 'H'=>['bid'=>102,'ask'=>103,'bid_qty'=>5,'ask_qty'=>5,'bids'=>[['price'=>102,'quantity'=>5]],'asks'=>[['price'=>103,'quantity'=>5]],
 'observed_at'=>'2026-01-03 00:00:00']]];
$quotePlan=(new XemmQuotePlanner())->plan(['snapshot_at'=>'2026-01-03 00:00:00','books'=>$quoteBooks],['symbol'=>'BTCUSDT','maker_venue'=>'M',
 'hedge_venue'=>'H','maker_side'=>'BUY','quantity'=>2,'maker_fee_rate'=>0.0001,'taker_fee_rate'=>0.001,'slippage_bps'=>0.5,
 'adverse_selection_cost'=>0.01,'minimum_net_profit_usd'=>0,'quote_id'=>'paper:q1']);
$assert($quotePlan['status']==='POST_CANDIDATE'&&$quotePlan['signal']['price']===100.0&&$quotePlan['signal']['quantity']===2.0
 &&$quotePlan['fill_probability']===null&&!empty($quotePlan['post_only_price']),
 'XEMM quote planner proposes passive paper intent from depth and full costs without inventing fill probability');
$xemmSim=$xemm->simulateExecution($xm[0],$xemmSnapshot);
$assert($xemmSim['status']==='HEDGED'&&$xemmSim['mode']==='SIMULATION_ONLY','XEMM simulates immediate hedge only after a confirmed maker fill');
$risk=$xemm->evaluateRisk(array_merge($xm[0],['net_profit'=>-1,'stale'=>true]));
$assert(!$risk['approved']&&in_array('STALE_MARKET_DATA',$risk['reasons'],true)&&in_array('NON_POSITIVE_NET_EDGE',$risk['reasons'],true),'risk rejects stale and negative edge');

$statistics=new StatisticalAnalysisEngine();$logsB=[];$logsA=[];
for($i=0;$i<160;$i++){$x=0.0007*$i+0.02*sin($i/9);$logsB[]=$x;$logsA[]=1.3*$x+0.004*sin($i*0.83)+0.001*sin($i*0.27);}
$pair=$statistics->analyzePair($logsA,$logsB);
$assert(count($pair['kalman_beta_series'])===160&&is_finite((float)$pair['kalman_beta']),'Kalman hedge ratio tracks the pair series');
$assert($pair['adf_method']==='ENGLE_GRANGER_ADF_SELECTED_LAG_MACKINNON_APPROX'&&$pair['adf_p_value']!==null
 &&$pair['adf_lag']>=0&&$pair['adf_lag_criterion']==='BIC'&&$pair['adf_p_value']>=0&&$pair['adf_p_value']<=1,
 'Engle-Granger ADF selects BIC lag and reports bounded MacKinnon p-value');
$pairAic=$statistics->analyzePair($logsA,$logsB,['adf_max_lag'=>2,'adf_lag_criterion'=>'aic']);
$assert($pairAic['adf_lag_criterion']==='AIC'&&$pairAic['adf_lag']<=2&&$pairAic['adf_critical_method']==='MACKINNON_2010_FINITE_SAMPLE_EG_NO_TREND_N2_RESPONSE_SURFACE',
 'Engle-Granger exposes configured AIC lag cap and finite-sample critical response');
$assert($pair['half_life_bars']===null||$pair['half_life_bars']>0,'half-life is finite positive or explicitly unavailable');
$breakValues=array_merge(array_fill(0,50,0.0),array_fill(0,50,1.0));
$breakScan=(new StatisticalAnalysisEngine())->structuralBreakDetails($breakValues,20,3.5);
$assert($breakScan['detected']&&$breakScan['method']==='MAX_STANDARDIZED_MEAN_SHIFT'&&!$breakScan['multiple_testing_corrected'],
 'structural break scan identifies a persistent mean shift and reports exploratory multiple-testing status');
$fundingRows=[];$fundingStart=strtotime('2026-01-01 00:00:00 UTC');
for($i=0;$i<=24*90;$i++)$fundingRows[]=['funding_rate'=>0.0001,'interval_hours'=>8,'observed_at_utc'=>gmdate('Y-m-d H:i:s',$fundingStart+$i*3600)];
$reversal=FundingRateAnalytics::reversalEstimate($fundingRows);
$assert($reversal['reversal_probability']>0&&$reversal['reversal_probability']<0.05&&$reversal['reversal_observations']>=80
 &&$reversal['reversal_probability_status']==='OOS_VALIDATED_EMPIRICAL'&&$reversal['test_brier_score']!==null,
 'funding reversal probability is fitted on earlier episodes and emitted only after chronological validation and test');
$flipRows=[];for($i=0;$i<=24*90;$i++)$flipRows[]=['funding_rate'=>((int)floor($i/24)%2===0?0.0001:-0.0001),'interval_hours'=>8,
 'observed_at_utc'=>gmdate('Y-m-d H:i:s',$fundingStart+$i*3600)];
$flipEstimate=FundingRateAnalytics::reversalEstimate($flipRows);
$assert($flipEstimate['reversal_probability']>0.95&&$flipEstimate['reversal_events']===$flipEstimate['reversal_observations'],
 'funding empirical reversal estimate counts forward sign flips inside the defined horizon');
$insufficient=FundingRateAnalytics::reversalEstimate(array_slice($fundingRows,0,24*20));
$assert($insufficient['reversal_probability']===null&&$insufficient['reversal_probability_status']==='INSUFFICIENT_HISTORY',
 'funding reversal estimator does not invent probabilities from short history');

$execution=new ExecutionSimulator();$fill=$execution->marketOrder([['price'=>100,'quantity'=>0.4],['price'=>101,'quantity'=>0.6]],'BUY',0.8,0.001,2);
$assert($fill['status']==='FILLED'&&abs($fill['vwap']-100.5)<1e-8&&$fill['fee']>0&&$fill['slippage_cost']>0,'depth replay computes VWAP, partial levels, fees and slippage');
$partial=$execution->marketOrder([['price'=>100,'quantity'=>0.2]],'BUY',1,0.001,0);
$assert($partial['status']==='PARTIAL'&&abs($partial['quantity_unfilled']-0.8)<1e-12,'depth replay preserves unfilled quantity');
$normalized=OrderBookCollector::levels([['101','2'],['99','3'],['0','1'],['100','0']],false);
$assert(count($normalized)===2&&$normalized[0]['price']===101.0&&$normalized[1]['price']===99.0,'orderbook adapter normalizes and sorts executable price levels');

$metrics=PerformanceAnalytics::calculate([['net_pnl'=>100],['net_pnl'=>-50],['net_pnl'=>25]],[
 ['at'=>'2026-01-01 00:00:00','equity'=>1000],['at'=>'2026-01-02 00:00:00','equity'=>1100],
 ['at'=>'2026-01-03 00:00:00','equity'=>1050],['at'=>'2026-01-04 00:00:00','equity'=>1075]]);
$assert(abs($metrics['profit_factor']-2.5)<1e-9&&abs($metrics['win_rate']-2/3)<1e-9,'performance metrics use net PnL and closed trades');
$assert($metrics['max_drawdown_pct']>4.5&&$metrics['sharpe']!==null&&$metrics['sortino']!==null,'equity analytics calculate drawdown, Sharpe and Sortino');
$bootA=BlockBootstrap::analyze([['net_pnl'=>10,'capital_used'=>100],['net_pnl'=>-5,'capital_used'=>100],['net_pnl'=>8,'capital_used'=>100]],1000,300,2,'fixture');
$bootB=BlockBootstrap::analyze([['net_pnl'=>10,'capital_used'=>100],['net_pnl'=>-5,'capital_used'=>100],['net_pnl'=>8,'capital_used'=>100]],1000,300,2,'fixture');
$assert($bootA['status']==='ESTIMATED_BLOCK_BOOTSTRAP'&&$bootA===$bootB&&$bootA['trials']===300,'block bootstrap is deterministic and reports out-of-sample risk distribution');

$guard=(new RiskManagementEngine())->evaluate(['net_profit'=>2,'capital_pct'=>6,'exchange_exposure_pct'=>30,'data_quality'=>true],[],
 ['equity'=>1000,'drawdown_pct'=>11,'gross_exposure'=>980,'recent_failures'=>3]);
$assert(!$guard['approved']&&count($guard['reasons'])>=4,'portfolio risk blocks allocation, venue exposure, drawdown and circuit breaker');
$venueRisk=(new RiskManagementEngine())->evaluate(['strategy'=>'FUNDING_RATE','net_profit'=>10,'capital_required'=>200,'capital_pct'=>2,
 'venue_exposures_pct'=>['A'=>2,'B'=>2],'data_quality'=>true],['max_exchange_exposure_pct'=>25],['equity'=>10000,'venue_exposure_pct'=>['A'=>24]]);
$assert(!$venueRisk['approved']&&in_array('EXCHANGE_EXPOSURE_LIMIT',$venueRisk['reasons'],true),'venue limits include existing exposure per exchange');
$sourceGuard=(new RiskManagementEngine())->evaluate(['strategy'=>'FUNDING_RATE','net_profit'=>2,'capital_pct'=>1,'data_quality'=>true,
 'details'=>['rate_sources'=>['LAST_SETTLED_RATE','CURRENT_ESTIMATE']]]);
$assert(!$sourceGuard['approved']&&in_array('FUNDING_RATE_SOURCE_MISMATCH',$sourceGuard['reasons'],true),'risk rejects non-comparable settled and forecast funding rates');
$marginPosition=['venue'=>'A','symbol'=>'BTCUSDT','side'=>'LONG','entry_price'=>100,'quantity'=>1,'collateral'=>20,
 'maintenance_margin_rate'=>0.05,'liquidation_fee_rate'=>0.005];
$marginEngine=new MarginStressEngine();
$marginSafe=$marginEngine->evaluate([$marginPosition],['gap_5pct'=>['A'=>0.05]]);
$marginBreach=$marginEngine->evaluate([$marginPosition],['gap_20pct'=>['A'=>0.20]]);
$assert($marginSafe['approved']&&$marginSafe['results'][0]['liquidation_price']>0&&$marginBreach['status']==='LIQUIDATION_RISK',
 'isolated-margin stress computes liquidation price and adverse-shock breach');
$marginGuard=(new RiskManagementEngine())->evaluate(['strategy'=>'FUNDING_RATE','net_profit'=>10,'capital_required'=>100,
 'capital_pct'=>1,'data_quality'=>true,'margin_positions'=>[$marginPosition],'margin_scenarios'=>['gap_20pct'=>['A'=>0.20]]]);
$assert(!$marginGuard['approved']&&in_array('LIQUIDATION_STRESS',$marginGuard['reasons'],true),
 'risk gate rejects opportunities liquidated by explicit margin stress scenarios');
$crossPositions=[['venue'=>'A','symbol'=>'BTCUSDT','side'=>'LONG','entry_price'=>100,'quantity'=>1,
 'maintenance_margin_rate'=>0.05,'liquidation_fee_rate'=>0.005],['venue'=>'A','symbol'=>'ETHUSDT','side'=>'SHORT','entry_price'=>100,'quantity'=>1,
 'maintenance_margin_rate'=>0.05,'liquidation_fee_rate'=>0.005]];
$crossMargin=$marginEngine->evaluateCrossMargin($crossPositions,['market_down_10pct'=>['A'=>-0.10]],['A'=>12]);
$assert($crossMargin['approved']&&abs($crossMargin['results'][0]['portfolio_pnl'])<1e-12
 &&$crossMargin['results'][0]['margin_buffer']>0,'cross-margin stress aggregates portfolio PnL and shared venue collateral across offsetting positions');
$crossGuard=(new RiskManagementEngine())->evaluate(['strategy'=>'FUNDING_RATE','net_profit'=>10,'capital_required'=>100,
 'capital_pct'=>1,'data_quality'=>true,'cross_margin_positions'=>[$marginPosition],'cross_margin_scenarios'=>['market_down_20pct'=>['A'=>-0.20]],
 'cross_margin_collateral_by_venue'=>['A'=>20]]);
$assert(!$crossGuard['approved']&&in_array('CROSS_MARGIN_LIQUIDATION_STRESS',$crossGuard['reasons'],true),
 'risk engine rejects opportunities that breach explicit cross-margin portfolio stress');
$regime=(new MarketRegimeDetector())->detect(['prices'=>range(100,119)]);
$assert($regime['regime']==='BULLISH_LOW_VOLATILITY','market regime derives trend and volatility from provided price history');
$allocationInput=['FUNDING_RATE'=>[],'SPATIAL'=>[]];
for($d=0;$d<30;$d++){$date=gmdate('Y-m-d',strtotime('2026-01-01 UTC')+$d*86400);
 $allocationInput['FUNDING_RATE'][$date]=0.001*sin($d/3);$allocationInput['SPATIAL'][$date]=0.002*cos($d/4);}
$allocator=new StrategyAllocationEngine();$sameWindow=['FUNDING_RATE'=>'2026-01-01|2026-01-30','SPATIAL'=>'2026-01-01|2026-01-30'];
$allocation=$allocator->recommend($allocationInput,['classification'=>'BACKTEST','partition'=>'TEST','minimum_observations'=>20,'max_weight_pct'=>80,'evaluation_window_keys'=>$sameWindow]);
$assert($allocation['status']==='RECOMMENDATION_READY'&&abs(array_sum($allocation['weights_pct'])-100)<0.001,'risk parity allocation uses synchronized out-of-sample covariance');
$ranker=(new ReflectionClass(StrategyEngine::class))->newInstanceWithoutConstructor();
$ranked=$ranker->rankCandidates([
 ['net_profit'=>15,'capital_required'=>100,'data_quality'=>true,'risk'=>['approved'=>true],
  'details'=>['liquidity_available_usd'=>1000,'historical_sharpe'=>1.2,'fill_probability'=>0.8,'fill_probability_status'=>'CALIBRATED']],
 ['net_profit'=>8,'capital_required'=>100,'data_quality'=>true,'risk'=>['approved'=>true],
  'details'=>['liquidity_available_usd'=>300,'historical_sharpe'=>0.4,'fill_probability'=>0.9,'fill_probability_status'=>'UNVALIDATED']]
]);
$assert($ranked[0]['score']>$ranked[1]['score']&&!isset($ranked[1]['components']['factors']['probability'])
 &&!empty($ranked[0]['components']['probability_requires_calibration']),
 'opportunity ranking normalizes return, probability, liquidity, persistence, Sharpe and quality without using unvalidated probabilities');
$differentWindow=$sameWindow;$differentWindow['SPATIAL']='2026-01-02|2026-01-30';
$assert($allocator->recommend($allocationInput,['classification'=>'BACKTEST','partition'=>'TEST','evaluation_window_keys'=>$differentWindow])['status']==='UNSYNCHRONIZED_EVALUATION_WINDOWS','allocation rejects incomparable test windows');
$blockedAllocation=$allocator->recommend($allocationInput,['classification'=>'PAPER','partition'=>'TRAIN']);
$assert($blockedAllocation['status']==='REJECTED_NON_OOS_INPUT','allocation rejects in-sample or non-backtest returns');
$triangular=new TriangularArbitrage();$triangularSnapshot=['capital'=>100,'capital_pct'=>1,'snapshot_at'=>'2026-01-01 00:00:00','triangular_edges'=>[
 ['venue'=>'X','from'=>'USDT','to'=>'BTC','executable_rate'=>2,'fee_rate'=>0.001,'depth_qty'=>200,'min_quantity'=>0.01,'quantity_step'=>0.01,'market'=>'BTCUSDT'],
 ['venue'=>'X','from'=>'BTC','to'=>'ETH','executable_rate'=>0.51,'fee_rate'=>0.001,'depth_qty'=>250,'min_quantity'=>0.01,'quantity_step'=>0.01,'market'=>'ETHBTC'],
 ['venue'=>'X','from'=>'ETH','to'=>'USDT','executable_rate'=>1,'fee_rate'=>0.001,'depth_qty'=>150,'min_quantity'=>0.01,'quantity_step'=>0.01,'market'=>'ETHUSDT'],
 ['venue'=>'Y','from'=>'ETH','to'=>'USDT','executable_rate'=>1.1,'fee_rate'=>0,'depth_qty'=>1000,'market'=>'ETHUSDT']]];
$cycles=$triangular->generateSignals($triangularSnapshot);
$assert(count($cycles)===1&&$cycles[0]['net_profit']>0&&$cycles[0]['details']['venue']==='X','triangular cycles stay on one venue and check each leg size/depth');
$triangularSnapshot['triangular_edges'][2]['min_quantity']=200;
$assert($triangular->generateSignals($triangularSnapshot)===[],'triangular cycle rejects a leg below minimum order size');
$dexPayload=['data'=>['type'=>'pool','id'=>'eth_0xpool','attributes'=>['address'=>'0xpool','name'=>'WETH / USDC',
 'base_token_price_usd'=>'2000','quote_token_price_usd'=>'1','base_token_balance'=>'10','quote_token_balance'=>'20000',
 'reserve_in_usd'=>'40000','volume_usd'=>['h24'=>'5000'],'pool_fee_percentage'=>'0.05'],
 'relationships'=>['base_token'=>['data'=>['id'=>'eth_weth']],'quote_token'=>['data'=>['id'=>'eth_usdc']],
  'dex'=>['data'=>['id'=>'uniswap_v3']]]],
 'included'=>[['id'=>'eth_weth','attributes'=>['address'=>'0xweth','symbol'=>'WETH']],
  ['id'=>'eth_usdc','attributes'=>['address'=>'0xusdc','symbol'=>'USDC']]]];
$dexPool=DexPoolCollector::normalizePool(['network'=>'eth','pool_address'=>'0xpool'],$dexPayload);
$assert($dexPool['base_token_symbol']==='WETH'&&$dexPool['quote_token_symbol']==='USDC'
 &&$dexPool['reserve_usd']===40000.0&&$dexPool['details']['data_quality'],'DEX pool adapter normalizes pool price, balances and observed reserve');
$assert(EvmGasCollector::hexToDecimal('3b9aca00')==='1000000000'
 &&EvmGasCollector::hexToDecimal('ffffffffffffffff')==='18446744073709551615','EVM gas adapter converts RPC hex wei without integer overflow');
$routeAt=gmdate('Y-m-d H:i:s');$poolA=['network'=>'eth','amm_model'=>'CONSTANT_PRODUCT','pool_address'=>'pool-a','pool_label'=>'Pool A',
 'base_token_address'=>'0xbase','quote_token_address'=>'0xquote','base_token_balance'=>100.0,'quote_token_balance'=>200000.0,
 'base_token_price_usd'=>2000.0,'quote_token_price_usd'=>1.0,'observed_at_utc'=>$routeAt];
$poolB=['network'=>'eth','amm_model'=>'CONSTANT_PRODUCT','pool_address'=>'pool-b','pool_label'=>'Pool B',
 'base_token_address'=>'0xbase','quote_token_address'=>'0xquote','base_token_balance'=>100.0,'quote_token_balance'=>206000.0,
 'base_token_price_usd'=>2060.0,'quote_token_price_usd'=>1.0,'observed_at_utc'=>$routeAt];
$sim=(new DexRouteSimulator())->simulate(['input_quote_amount'=>1000,'buy_fee_rate'=>0.003,'sell_fee_rate'=>0.003,'gas_units'=>150000,
 'native_token_price_usd'=>3000,'as_of_utc'=>$routeAt],$poolA,$poolB,['gas_price_wei'=>'20000000000','observed_at_utc'=>$routeAt]);
$assert($sim['status']==='SIMULATED'&&$sim['deterministic_net_after_gas']>0&&$sim['pool_fee_cost']>0
 &&abs(($sim['gross_edge']-$sim['price_impact_cost']-$sim['pool_fee_cost'])-$sim['cost_reconciliation']['actual_pnl_before_gas_usd'])<1e-7,
 'DEX route simulator prices two explicitly configured constant-product pools and reconciles curve, fees and gas');
$poolB['amm_model']='UNISWAP_V3';$unsupported=(new DexRouteSimulator())->simulate(['input_quote_amount'=>1000,'buy_fee_rate'=>0.003,'sell_fee_rate'=>0.003,
 'gas_units'=>150000,'native_token_price_usd'=>3000,'as_of_utc'=>$routeAt],$poolA,$poolB,['gas_price_wei'=>'20000000000','observed_at_utc'=>$routeAt]);
$assert($unsupported['status']==='REJECTED'&&in_array('POOL_MODEL_UNSUPPORTED',$unsupported['rejection_reasons'],true),
 'DEX route simulator refuses concentrated-liquidity pools without a supported curve model');
$poolB['amm_model']='CONSTANT_PRODUCT';
$integratedMev=(new MevArbitrage())->generateSignals(['snapshot_at'=>$routeAt,'dex_pools'=>[$poolA,$poolB],
 'chain_gas'=>['eth'=>['gas_price_wei'=>'20000000000','observed_at_utc'=>$routeAt]],'dex_route_configs'=>[['network'=>'eth','buy_pool_address'=>'pool-a',
  'sell_pool_address'=>'pool-b','input_quote_amount'=>1000,'buy_fee_rate'=>0.003,'sell_fee_rate'=>0.003,'gas_units'=>150000,'native_token_price_usd'=>3000]]]);
$assert(count($integratedMev)===1&&$integratedMev[0]['details']['simulation_model']==='CONSTANT_PRODUCT'
 &&$integratedMev[0]['action']==='OBSERVE_AND_BACKTEST_ONLY','MEV strategy consumes configured DEX pool and gas observations as research-only simulation');
$hybridBook=['symbol'=>'WETHUSDT','bids'=>[['price'=>2050.0,'quantity'=>100]],'asks'=>[['price'=>2051.0,'quantity'=>100]],'observed_at'=>$routeAt];
$hybridConfig=['network'=>'eth','pool_address'=>'pool-a','direction'=>'BUY_DEX_SELL_CEX','symbol'=>'WETHUSDT','cex_venue'=>'BINANCE_SPOT',
 'quote_input_amount'=>1000,'dex_fee_rate'=>0.003,'cex_fee_rate'=>0.001,'cex_slippage_bps'=>2,'adverse_selection_cost_usd'=>0.5,
 'quote_token_price_usd'=>1,'gas_units'=>150000,'native_token_price_usd'=>3000];
$hybrid=(new DexCexRouteSimulator())->simulate($hybridConfig+['as_of_utc'=>$routeAt],$poolA,$hybridBook,['gas_price_wei'=>'20000000000','observed_at_utc'=>$routeAt]);
$assert($hybrid['status']==='SIMULATED'&&$hybrid['execution_mode']==='OBSERVATION_ONLY'&&$hybrid['cex_fee_cost']>0&&$hybrid['slippage_cost']>0
 &&abs(($hybrid['gross_edge']-$hybrid['price_impact_cost']-$hybrid['pool_fee_cost']-$hybrid['cex_fee_cost']-$hybrid['slippage_cost'])-$hybrid['net_before_gas_and_adverse_cost'])<1e-7,
 'DEX-to-CEX simulation reconciles pool impact, pool fee, CEX fees and depth slippage');
$hybridSignal=(new MevArbitrage())->generateSignals(['snapshot_at'=>$routeAt,'dex_pools'=>[$poolA],
 'books'=>['WETHUSDT'=>['BINANCE_SPOT'=>$hybridBook]],'chain_gas'=>['eth'=>['gas_price_wei'=>'20000000000','observed_at_utc'=>$routeAt]],
 'dex_cex_route_configs'=>[$hybridConfig]]);
$assert(count($hybridSignal)===1&&$hybridSignal[0]['details']['simulation_model']==='CONSTANT_PRODUCT_PLUS_CEX_DEPTH'
 &&$hybridSignal[0]['classification']==='OBSERVATION_ONLY','MEV strategy integrates DEX/CEX route through normalized snapshot and remains non-executing');
$basisCandidate=(new BasisAnalysisEngine())->candidate('BTCUSDT','BYBIT_SPOT','BYBIT_DATED_BTCUSDT-270625',
 ['bid'=>100,'ask'=>101,'bid_qty'=>2,'ask_qty'=>2,'bids'=>[['price'=>100,'quantity'=>2]],'asks'=>[['price'=>101,'quantity'=>2]],'observed_at'=>'2026-01-01 00:00:00'],
 ['bid'=>103,'ask'=>104,'bid_qty'=>2,'ask_qty'=>2,'bids'=>[['price'=>103,'quantity'=>2]],'asks'=>[['price'=>104,'quantity'=>2]],'observed_at'=>'2026-01-01 00:00:00'],
 '2026-02-01 00:00:00','2026-01-01 00:00:00');
$assert($basisCandidate!==null&&$basisCandidate['basis']>0&&$basisCandidate['annualized_basis']>0&&!$basisCandidate['cost_model_complete'],'dated basis analysis uses executable side and does not invent missing costs');
$mev=(new MevArbitrage())->generateSignals(['dex_routes'=>[['route'=>'pool-a>pool-b','observed_at'=>'2026-01-01 00:00:00',
 'gross_edge'=>100,'gas_cost'=>2,'price_impact_cost'=>3,'slippage_cost'=>4,'revert_cost'=>20,'revert_probability'=>0.25,
 'revert_probability_calibrated'=>true]]]);
$assert(count($mev)===1&&abs($mev[0]['net_profit']-61)<1e-9&&$mev[0]['details']['revert_probability_status']==='CALIBRATED','MEV observation weights expected edge by calibrated revert probability');

$events=[];$origin=strtotime('2026-01-01 00:00:00 UTC');
for($i=0;$i<35;$i++){$at=gmdate('Y-m-d H:i:s',$origin+$i);$bid=100+$i*0.5;$ask=$bid+0.2;
 $events[]=['as_of_utc'=>$at,'available_at_utc'=>$at,'snapshot'=>['tick'=>$i,'books'=>['X'=>['BTCUSDT'=>['bid'=>$bid,'ask'=>$ask]]],
  'book_depth'=>['X'=>['BTCUSDT'=>['bids'=>[['price'=>$bid,'quantity'=>20]],'asks'=>[['price'=>$ask,'quantity'=>20]]]]]]];}
$seen=[];$bt=(new EventDrivenBacktester())->run('SPATIAL',$events,static function(array $snapshot,array $history,string $at)use(&$seen):array{
 $tick=$snapshot['tick'];$seen[]=['tick'=>$tick,'history_count'=>count($history),'last'=>$history?end($history)['as_of_utc']:null,'at'=>$at];
 if($tick===28)return ['action'=>'OPEN_LONG','venue'=>'X','symbol'=>'BTCUSDT','quantity'=>10];
 if($tick===30)return ['action'=>'CLOSE_LONG'];return ['action'=>'NO_TRADE'];
},['initial_capital'=>10000,'allocation_pct'=>5,'fee_rate'=>0.001,'slippage_bps'=>2,'latency_seconds'=>1]);
$assert($bt['status']==='COMPLETED'&&$bt['trade_count']===1,'walk-forward backtest replays delayed simulated fills');
$assert(count($bt['equity'])===7&&$bt['equity'][0]['at']===$bt['test_start_utc'],'backtest equity series excludes training and validation periods');
$assert($bt['trades'][0]['net_pnl']>0&&$bt['test_start_utc']===$events[28]['as_of_utc'],'backtest reports only out-of-sample net trade');
$assert($bt['metrics']['block_bootstrap']['status']==='ESTIMATED_BLOCK_BOOTSTRAP','backtest includes block-bootstrap outcome ranges');
$assert(!empty($seen)&&end($seen)['last']<$seen[count($seen)-1]['at'],'signal callback receives only prior event history');
$futureEvents=$events;$futureEvents[0]['available_at_utc']=gmdate('Y-m-d H:i:s',$origin+60);$futureRejected=false;
try{(new EventDrivenBacktester())->run('SPATIAL',$futureEvents,static fn():array=>['action'=>'NO_TRADE']);}catch(RuntimeException $e){$futureRejected=$e->getMessage()==='LOOKAHEAD_OR_UNAVAILABLE_EVENT';}
$assert($futureRejected,'walk-forward replay rejects information unavailable at the event timestamp');
$fitWindowCount=0;$validationWindowCount=0;
$walkModel=(new EventDrivenBacktester())->runWalkForwardModel('SPATIAL',$events,
 static function(array $training,string $trainEnd)use(&$fitWindowCount):array{$fitWindowCount=count($training);return ['trained_through'=>$trainEnd];},
 static function(array $model,array $validation,string $trainEnd,string $validationEnd)use(&$validationWindowCount):array{
  $validationWindowCount=count($validation);return ['approved'=>$validationWindowCount>0,'score'=>0.0,'train_end'=>$trainEnd,'validation_end'=>$validationEnd];},
 static function(array $model,array $validationResult,array $snapshot,array $history,string $at):array{return ['action'=>'NO_TRADE'];});
$assert($fitWindowCount===21&&$validationWindowCount===7&&$walkModel['model_validation']['status']==='PASSED'
 &&!in_array('NO_PARAMETER_FITTING',$walkModel['limitations'],true),
 'walk-forward model API fits only train, validates in the next time block, and replays predictions only on test');
$xemmEvents=[];
for($i=0;$i<35;$i++){$at=gmdate('Y-m-d H:i:s',$origin+$i);$snap=['books'=>['Y'=>['BTCUSDT'=>['bid'=>100.5,'ask'=>100.6]]],
 'book_depth'=>['Y'=>['BTCUSDT'=>['bids'=>[['price'=>100.5,'quantity'=>10]],'asks'=>[['price'=>100.6,'quantity'=>10]]]]]];
 if($i===28){$snap['books']['X']['BTCUSDT']=['bid'=>100,'ask'=>100.1,'bid_qty'=>5,'ask_qty'=>5,'observed_at'=>$at];$snap['books']['Y']['BTCUSDT']['observed_at']=$at;
  $snap['xemm_quote_config']=['symbol'=>'BTCUSDT','maker_venue'=>'X','hedge_venue'=>'Y','maker_side'=>'BUY','quantity'=>2,
   'maker_fee_rate'=>0,'taker_fee_rate'=>0,'slippage_bps'=>0,'adverse_selection_cost'=>0,'minimum_net_profit_usd'=>0,'quote_id'=>'q1'];}
 if($i===29)$snap['maker_fills']=[['quote_id'=>'q1','quantity'=>2,'price'=>100,'filled_at_utc'=>$at,'available_at_utc'=>$at]];
 if($i===31)$snap['xemm_signal']=['action'=>'POST','quote_id'=>'q2','symbol'=>'BTCUSDT','maker_venue'=>'X','hedge_venue'=>'Y','maker_side'=>'SELL',
  'price'=>101,'quantity'=>1,'maker_fee_rate'=>0,'observed_at_utc'=>$at,'available_at_utc'=>$at];
 $xemmEvents[]=['as_of_utc'=>$at,'available_at_utc'=>$at,'snapshot'=>$snap];}
$xemmReplay=(new XemmEventReplay())->run($xemmEvents,['initial_capital'=>10000,'allocation_pct'=>5,'hedge_fee_rate'=>0,'slippage_bps'=>0,
 'latency_seconds'=>1,'cancel_latency_seconds'=>0,'max_quote_age_seconds'=>2,'bootstrap_trials'=>100]);
$assert($xemmReplay['trade_count']===1&&$xemmReplay['maker_fill_count']===1&&abs($xemmReplay['trades'][0]['net_pnl']-1)<1e-9,'XEMM event replay hedges confirmed maker fills after latency');
$assert($xemmReplay['quotes']['q2']['status']==='CANCELLED'&&$xemmReplay['quotes']['q2']['cancel_reason']==='STALE','XEMM event replay cancels stale unfilled quotes');
$partialXemmEvents=$xemmEvents;$partialXemmEvents[30]['snapshot']['book_depth']['Y']['BTCUSDT']['bids'][0]['quantity']=1;
$partialXemm=(new XemmEventReplay())->run($partialXemmEvents,['initial_capital'=>10000,'allocation_pct'=>5,'hedge_fee_rate'=>0,'slippage_bps'=>0,
 'latency_seconds'=>1,'cancel_latency_seconds'=>0,'max_quote_age_seconds'=>2,'bootstrap_trials'=>100]);
$assert($partialXemm['trade_count']===2&&abs(array_sum(array_column($partialXemm['trades'],'net_pnl'))-1)<1e-9
 &&($partialXemm['rejections']['PARTIAL_HEDGE_RETRY']??0)===1,'XEMM replay retries residual hedge quantity across later events');
$failedEvents=$events;$failedEvents[29]['snapshot']['exchange_status']['X']='DOWN';
$failed=(new EventDrivenBacktester())->run('SPATIAL',$failedEvents,static function(array $snapshot):array{
 if(($snapshot['tick']??-1)===28)return ['action'=>'OPEN_LONG','venue'=>'X','symbol'=>'BTCUSDT','quantity'=>10];
 return ['action'=>'NO_TRADE'];
},['initial_capital'=>10000,'allocation_pct'=>5,'fee_rate'=>0.001,'slippage_bps'=>2,'latency_seconds'=>1]);
$assert(($failed['rejections']['EXCHANGE_UNAVAILABLE']??0)===1&&$failed['trade_count']===0,'backtest simulates venue outage and records failed fill');
$fundingEvents=$events;$fundingEvents[30]['snapshot']['funding_settlements']=[['settlement_id'=>'X:BTC:30','venue'=>'X','symbol'=>'BTCUSDT',
 'settled_at_utc'=>$fundingEvents[30]['as_of_utc'],'available_at_utc'=>$fundingEvents[30]['available_at_utc'],'rate'=>0.0001,'mark_price'=>115]];
$perp=(new EventDrivenBacktester())->run('FUNDING_RATE',$fundingEvents,static function(array $snapshot):array{
 if(($snapshot['tick']??-1)===28)return ['action'=>'OPEN_PERP_LONG','venue'=>'X','symbol'=>'BTCUSDT','quantity'=>10];
 if(($snapshot['tick']??-1)===31)return ['action'=>'CLOSE_PERP'];return ['action'=>'NO_TRADE'];
},['initial_capital'=>10000,'allocation_pct'=>5,'fee_rate'=>0.001,'slippage_bps'=>2,'latency_seconds'=>1]);
$assert($perp['trade_count']===1&&$perp['trades'][0]['funding_paid']>0,'perpetual backtest books only available funding settlements into closed PnL');
$fundingEvents[30]['snapshot']['funding_settlements'][0]['rate']=-0.0001;
$perpCredit=(new EventDrivenBacktester())->run('FUNDING_RATE',$fundingEvents,static function(array $snapshot):array{
 if(($snapshot['tick']??-1)===28)return ['action'=>'OPEN_PERP_LONG','venue'=>'X','symbol'=>'BTCUSDT','quantity'=>10];
 if(($snapshot['tick']??-1)===31)return ['action'=>'CLOSE_PERP'];return ['action'=>'NO_TRADE'];
},['initial_capital'=>10000,'allocation_pct'=>5,'fee_rate'=>0.001,'slippage_bps'=>2,'latency_seconds'=>1]);
$assert($perpCredit['trade_count']===1&&$perpCredit['trades'][0]['funding_received']>0,'negative funding is credited to a perpetual long with settlement timing');
$liquidationEvents=$events;$liquidationEvents[30]['snapshot']['books']['X']['BTCUSDT']=['bid'=>249,'ask'=>250];
$liquidationEvents[30]['snapshot']['book_depth']['X']['BTCUSDT']=['bids'=>[['price'=>249,'quantity'=>20]],'asks'=>[['price'=>250,'quantity'=>20]]];
$liquidated=(new EventDrivenBacktester())->run('FUNDING_RATE',$liquidationEvents,static function(array $snapshot):array{
 if(($snapshot['tick']??-1)===28)return ['action'=>'OPEN_PERP_SHORT','venue'=>'X','symbol'=>'BTCUSDT','quantity'=>10];
 return ['action'=>'NO_TRADE'];
},['initial_capital'=>10000,'allocation_pct'=>5,'fee_rate'=>0,'slippage_bps'=>0,'latency_seconds'=>1,
 'margin_profiles'=>['X'=>['maintenance_margin_rate'=>0.005,'liquidation_fee_rate'=>0.001]]]);
$assert($liquidated['trade_count']===1&&count($liquidated['liquidations'])===1&&$liquidated['trades'][0]['liquidated']===true
 &&$liquidated['kill_switch_triggered'],'backtest force-liquidates a perp after an explicit venue margin breach');
echo "All arbitrage strategy checks passed.\n";
