<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

use PDO;

/** Walk-forward replay. The signal callback only receives the current and past snapshots. */
final class EventDrivenBacktester
{
    private ExecutionSimulator $execution;
    public function __construct(?ExecutionSimulator $execution=null){$this->execution=$execution??new ExecutionSimulator();}

    public function run(string $strategyKey,array $events,callable $signalGenerator,array $config=[]): array
    {
        if(!isset(StrategyEngine::registry()[$strategyKey]))throw new \InvalidArgumentException('UNKNOWN_STRATEGY');
        if(count($events)<30)throw new \InvalidArgumentException('BACKTEST_HISTORY_TOO_SHORT');
        usort($events,static fn(array $a,array $b):int=>strcmp((string)($a['as_of_utc']??''),(string)($b['as_of_utc']??'')));
        $n=count($events);$trainEnd=max(1,(int)floor($n*0.6));$validationEnd=max($trainEnd+1,(int)floor($n*0.8));
        if($validationEnd >= $n)throw new \InvalidArgumentException('INVALID_WALK_FORWARD_SPLIT');
        $fee=(float)($config['fee_rate']??0.001);$slippage=(float)($config['slippage_bps']??2.0);
        $initial=(float)($config['initial_capital']??10000);$allocation=min(5,max(0,(float)($config['allocation_pct']??5)))/100;
        if($initial<=0||$fee<0||$slippage<0)throw new \InvalidArgumentException('INVALID_BACKTEST_CONFIG');
        $cash=$initial;$position=null;$pending=null;$history=[];$trades=[];$liquidations=[];$equity=[];$decisions=0;$rejections=[];$latency=(int)($config['latency_seconds']??1);
        $peak=$initial;$maxDrawdown=0.0;$killSwitch=false;$consecutiveFailures=0;$maxDrawdownLimit=(float)($config['max_portfolio_drawdown_pct']??10);
        $circuitFailures=max(1,(int)($config['circuit_breaker_failures']??3));
        for($i=0;$i<$n;$i++){
            $event=$events[$i];$at=$this->utc($event['as_of_utc']??null);$available=$this->utc($event['available_at_utc']??null);
            if($at===null||$available===null||$available>$at)throw new \RuntimeException('LOOKAHEAD_OR_UNAVAILABLE_EVENT');
            $snapshot=$event['snapshot']??[];
            if($pending!==null&&$i>$pending['signal_index']&&$at >= $pending['eligible_epoch']){
                $venueState=strtoupper((string)($snapshot['exchange_status'][$pending['venue']]??'UP'));
                $book=$snapshot['book_depth'][$pending['venue']][$pending['symbol']]??null;
                $isOpen=in_array($pending['action'],['OPEN_LONG','OPEN_PERP_LONG','OPEN_PERP_SHORT'],true);
                $buying=$pending['action']==='OPEN_LONG'||$pending['action']==='OPEN_PERP_LONG'
                    ||(!$isOpen&&($position['side']??'LONG')==='SHORT');
                $levels=$book?($buying?($book['asks']??[]):($book['bids']??[])):[];
                $fill=$venueState==='DOWN'?['quantity_filled'=>0.0]:$this->execution->marketOrder($levels,
                    $buying?'BUY':'SELL',$pending['quantity'],$fee,$slippage);
                if(($fill['quantity_filled']??0)>0){
                    $consecutiveFailures=0;
                    if($isOpen){
                        $isPerp=in_array($pending['action'],['OPEN_PERP_LONG','OPEN_PERP_SHORT'],true);
                        $side=$pending['action']==='OPEN_PERP_SHORT'?'SHORT':'LONG';
                        $reserved=$isPerp?$fill['gross_notional']:0.0;
                        $spend=$fill['gross_notional']+$fill['fee']+$fill['slippage_cost'];
                        if($spend<=$cash){$cash-=$spend;$position=['symbol'=>$pending['symbol'],'venue'=>$pending['venue'],
                            'quantity'=>$fill['quantity_filled'],'entry_vwap'=>$fill['vwap'],'entry_fees'=>$fill['fee'],'entry_slippage'=>$fill['slippage_cost'],
                            'mark_price'=>$fill['vwap'],'opened_at_utc'=>$at,'capital_used'=>$spend,'instrument_type'=>$isPerp?'PERP':'SPOT',
                            'side'=>$side,'margin_reserved'=>$reserved,'funding_received'=>0.0,'funding_paid'=>0.0,'funding_ids'=>[]];}
                    }elseif($position!==null){
                        $qty=min($position['quantity'],$fill['quantity_filled']);$fraction=$qty/$position['quantity'];
                        $direction=($position['side']??'LONG')==='SHORT'?-1:1;
                        $gross=$direction*($fill['vwap']-$position['entry_vwap'])*$qty;
                        $exitCash=$position['instrument_type']==='PERP'
                            ?$position['margin_reserved']*$fraction+$gross-$fill['fee']-$fill['slippage_cost']
                            :$fill['vwap']*$qty-$fill['fee']-$fill['slippage_cost'];$cash+=$exitCash;
                        $entryFee=$position['entry_fees']*$fraction;$entrySlippage=$position['entry_slippage']*$fraction;
                        $fundingReceived=(float)$position['funding_received']*$fraction;$fundingPaid=(float)$position['funding_paid']*$fraction;
                        $net=$gross-$entryFee-$fill['fee']-$entrySlippage-$fill['slippage_cost']+$fundingReceived-$fundingPaid;
                        $trades[]=['symbol'=>$position['symbol'],'net_pnl'=>$net,
                            'gross_pnl'=>$gross,'fees'=>$entryFee+$fill['fee'],'slippage'=>$entrySlippage+$fill['slippage_cost'],
                            'funding_received'=>$fundingReceived,'funding_paid'=>$fundingPaid,
                            'capital_used'=>$position['capital_used']*$fraction,'opened_at_utc'=>$position['opened_at_utc'],'closed_at_utc'=>$at];
                        if($qty >= $position['quantity']-1e-12)$position=null;
                        else{$position['quantity']-=$qty;$position['entry_fees']-=$entryFee;$position['entry_slippage']-=$entrySlippage;$position['capital_used']*=(1-$fraction);
                            $position['funding_received']-=$fundingReceived;$position['funding_paid']-=$fundingPaid;
                            $position['margin_reserved']*=(1-$fraction);}
                    }
                }else{$rejections[]=$venueState==='DOWN'?'EXCHANGE_UNAVAILABLE':'NO_EXECUTABLE_DEPTH';$consecutiveFailures++;if($consecutiveFailures>=$circuitFailures)$killSwitch=true;}
                $pending=null;
            }
            if($position!==null&&($position['instrument_type']??'SPOT')==='PERP'){
                foreach(($snapshot['funding_settlements']??[]) as $settlement){
                    $settlementAt=$this->utc($settlement['settled_at_utc']??null);
                    $settlementAvailable=isset($settlement['available_at_utc'])?$this->utc($settlement['available_at_utc']):$available;
                    $settlementId=(string)($settlement['settlement_id']??'');
                    if($settlementAt===null||$settlementAvailable===null||$settlementAt>$at||$settlementAvailable>$at
                        ||$settlementAt<=$this->utc($position['opened_at_utc'])||$settlementId===''||in_array($settlementId,$position['funding_ids'],true)
                        ||($settlement['venue']??null)!==$position['venue']||($settlement['symbol']??null)!==$position['symbol']
                        ||!is_numeric($settlement['rate']??null))continue;
                    $price=is_numeric($settlement['mark_price']??null)?(float)$settlement['mark_price']:(float)$position['mark_price'];
                    if($price<=0)continue;$amount=$position['quantity']*$price*abs((float)$settlement['rate']);
                    $receive=(($position['side']==='SHORT'&&(float)$settlement['rate']>0)
                        ||($position['side']==='LONG'&&(float)$settlement['rate']<0));
                    if($receive){$position['funding_received']+=$amount;$cash+=$amount;}
                    else{$position['funding_paid']+=$amount;$cash-=$amount;}
                    $position['funding_ids'][]=$settlementId;
                }
            }
            if($killSwitch&&$position!==null&&$pending===null&&$i<$n-1)$pending=['action'=>($position['instrument_type']??'SPOT')==='PERP'?'CLOSE_PERP':'CLOSE_LONG',
                'venue'=>$position['venue'],'symbol'=>$position['symbol'],'quantity'=>$position['quantity'],
                'signal_index'=>$i,'eligible_epoch'=>$at+$latency];
            if(!$killSwitch&&$i >= $validationEnd && $i<$n-1 && $pending===null){
                // Give the caller only the information available at this event; future records are never passed.
                $signal=$signalGenerator($snapshot,$history,gmdate('Y-m-d H:i:s',$at));
                $decisions++;
                if(is_array($signal)&&in_array(($signal['action']??''),['OPEN_LONG','OPEN_PERP_LONG','OPEN_PERP_SHORT','CLOSE_LONG','CLOSE_PERP'],true)){
                    $action=$signal['action'];
                    $opening=in_array($action,['OPEN_LONG','OPEN_PERP_LONG','OPEN_PERP_SHORT'],true);
                    $closing=in_array($action,['CLOSE_LONG','CLOSE_PERP'],true)&&$position!==null
                        &&(($action==='CLOSE_PERP')===($position['instrument_type']==='PERP'));
                    if(($opening&&$position===null&&$cash>$initial*0.01)||$closing){
                        $venue=(string)($signal['venue']??($position['venue']??''));$symbol=(string)($signal['symbol']??($position['symbol']??''));
                        $reference=$snapshot['books'][$venue][$symbol]??null;
                        $buying=in_array($action,['OPEN_LONG','OPEN_PERP_LONG'],true)||($action==='CLOSE_PERP'&&($position['side']??'LONG')==='SHORT');
                        $price=$buying?(float)($reference['ask']??0):(float)($reference['bid']??0);
                        $qty=$opening?min((float)($signal['quantity']??0),$cash*$allocation/max($price,1e-12)):(float)$position['quantity'];
                        if($qty>0)$pending=['action'=>$action,'venue'=>$venue,'symbol'=>$symbol,'quantity'=>$qty,
                            'signal_index'=>$i,'eligible_epoch'=>$at+$latency];
                    }
                }
            }
            $mark=0.0;if($position!==null){$quote=$snapshot['books'][$position['venue']][$position['symbol']]??[];
                $b=($position['side']??'LONG')==='SHORT'?($quote['ask']??null):($quote['bid']??null);
                if(is_numeric($b)&&(float)$b>0)$position['mark_price']=(float)$b;
                if(($position['instrument_type']??'SPOT')==='PERP'&&isset($config['margin_profiles'][$position['venue']])){
                    $profile=$config['margin_profiles'][$position['venue']];
                    $marginCheck=(new MarginStressEngine())->evaluateAtMark(['venue'=>$position['venue'],'symbol'=>$position['symbol'],
                        'side'=>$position['side'],'entry_price'=>$position['entry_vwap'],'quantity'=>$position['quantity'],
                        'collateral'=>max(0.0,$position['margin_reserved']+$position['funding_received']-$position['funding_paid']),
                        'maintenance_margin_rate'=>(float)($profile['maintenance_margin_rate']??-1),
                        'liquidation_fee_rate'=>(float)($profile['liquidation_fee_rate']??-1)],(float)$position['mark_price']);
                    if(!$marginCheck['approved']){$venueState=strtoupper((string)($snapshot['exchange_status'][$position['venue']]??'UP'));
                        $book=$snapshot['book_depth'][$position['venue']][$position['symbol']]??[];
                        $closingSide=$position['side']==='SHORT'?'BUY':'SELL';
                        $levels=$venueState==='DOWN'?[]:($closingSide==='BUY'?($book['asks']??[]):($book['bids']??[]));
                        $liquidationFill=$this->execution->marketOrder($levels,$closingSide,(float)$position['quantity'],$fee,$slippage);
                        if(($liquidationFill['quantity_filled']??0)>=(float)$position['quantity']-1e-12){
                            $qty=(float)$position['quantity'];$direction=$position['side']==='SHORT'?-1:1;
                            $gross=$direction*((float)$liquidationFill['vwap']-(float)$position['entry_vwap'])*$qty;
                            $liqFee=(float)$liquidationFill['gross_notional']*(float)$profile['liquidation_fee_rate'];
                            $net=$gross-(float)$position['entry_fees']-(float)$liquidationFill['fee']
                                -(float)$position['entry_slippage']-(float)$liquidationFill['slippage_cost']-$liqFee
                                +(float)$position['funding_received']-(float)$position['funding_paid'];
                            $cash+=(float)$position['margin_reserved']+$gross-(float)$liquidationFill['fee']
                                -(float)$liquidationFill['slippage_cost']-$liqFee;
                            $trades[]=['symbol'=>$position['symbol'],'net_pnl'=>$net,'gross_pnl'=>$gross,
                                'fees'=>(float)$position['entry_fees']+(float)$liquidationFill['fee']+$liqFee,
                                'slippage'=>(float)$position['entry_slippage']+(float)$liquidationFill['slippage_cost'],
                                'funding_received'=>(float)$position['funding_received'],'funding_paid'=>(float)$position['funding_paid'],
                                'capital_used'=>(float)$position['capital_used'],'opened_at_utc'=>$position['opened_at_utc'],
                                'closed_at_utc'=>$at,'liquidated'=>true,'liquidation_fee'=>$liqFee];
                            $liquidations[]=['venue'=>$position['venue'],'symbol'=>$position['symbol'],'side'=>$position['side'],
                                'at_utc'=>$at,'mark_price'=>(float)$position['mark_price'],'close_vwap'=>(float)$liquidationFill['vwap'],
                                'margin_buffer'=>(float)$marginCheck['margin_buffer'],'liquidation_price'=>$marginCheck['liquidation_price'],
                                'fee'=>$liqFee,'status'=>'LIQUIDATED_SIMULATED'];$position=null;$killSwitch=true;
                        }else{$rejections[]=$venueState==='DOWN'?'LIQUIDATION_VENUE_UNAVAILABLE':'LIQUIDATION_DEPTH_INSUFFICIENT';$killSwitch=true;}
                    }
                }
                if($position!==null){
                $mark=(float)($position['mark_price']??$position['entry_vwap'])*$position['quantity'];
                if(($position['instrument_type']??'SPOT')==='PERP')$mark=(float)$position['margin_reserved']+
                    (($position['side']??'LONG')==='SHORT'?((float)$position['entry_vwap']-(float)$position['mark_price']):((float)$position['mark_price']-(float)$position['entry_vwap']))*$position['quantity'];}}
            $currentEquity=$cash+$mark;$peak=max($peak,$currentEquity);$drawdown=$peak>0?($peak-$currentEquity)/$peak*100:0;$maxDrawdown=max($maxDrawdown,$drawdown);
            $equity[]=['at'=>gmdate('Y-m-d H:i:s',$at),'equity'=>$currentEquity];
            if($drawdown >= $maxDrawdownLimit){$killSwitch=true;if($pending!==null&&in_array($pending['action'],['OPEN_LONG','OPEN_PERP_LONG','OPEN_PERP_SHORT'],true))$pending=null;
                if($position!==null&&$pending===null)$pending=['action'=>($position['instrument_type']??'SPOT')==='PERP'?'CLOSE_PERP':'CLOSE_LONG','venue'=>$position['venue'],'symbol'=>$position['symbol'],
                    'quantity'=>$position['quantity'],'signal_index'=>$i,'eligible_epoch'=>$at+$latency];}
            $history[]=['as_of_utc'=>gmdate('Y-m-d H:i:s',$at),'snapshot'=>$snapshot];
        }
        $testStart=$events[$validationEnd]['as_of_utc'];
        $testEquity=array_values(array_filter($equity,static fn(array $point):bool=>$point['at']>=$testStart));
        $daily=[];foreach($testEquity as $point)$daily[substr($point['at'],0,10)]=$point;
        $metrics=PerformanceAnalytics::calculate($trades,array_values($daily));
        $metrics['block_bootstrap']=BlockBootstrap::analyze($trades,$initial,(int)($config['bootstrap_trials']??1000),
            (int)($config['bootstrap_block_size']??5),hash('sha256',json_encode($events,JSON_THROW_ON_ERROR)));
        return ['status'=>$trades?'COMPLETED':'INSUFFICIENT_EXECUTION_DATA','strategy'=>$strategyKey,'classification'=>'BACKTEST',
            'events'=>$n,'train_end_utc'=>$events[$trainEnd-1]['as_of_utc'],'validation_end_utc'=>$events[$validationEnd-1]['as_of_utc'],
            'test_start_utc'=>$testStart,'test_end_utc'=>$events[$n-1]['as_of_utc'],
            'decision_count'=>$decisions,'trade_count'=>count($trades),'trades'=>$trades,'equity'=>$testEquity,
            'open_position'=>$position,'unrealized_pnl'=>$position!==null?end($testEquity)['equity']-$initial-array_sum(array_column($trades,'net_pnl')):0,
            'metrics'=>$metrics,'max_drawdown_pct'=>$maxDrawdown,'kill_switch_triggered'=>$killSwitch,'liquidations'=>$liquidations,
            'rejections'=>array_count_values($rejections),'dataset_sha256'=>hash('sha256',json_encode($events,JSON_THROW_ON_ERROR)),
            'limitations'=>array_values(array_merge(['NO_MULTI_LEG_PERP_HEDGE','BOOK_DEPTH_ONLY'],
                empty($config['walk_forward_model_fitted'])?['NO_PARAMETER_FITTING']:[],
                empty($config['margin_profiles'])?['NO_FORCED_LIQUIDATION_WITHOUT_MARGIN_PROFILE']:[]))];
    }

    /** Fits on train, validates on a later partition, then predicts only during test replay. */
    public function runWalkForwardModel(string $strategyKey,array $events,callable $fit,callable $validate,callable $predict,array $config=[]): array
    {
        if(count($events)<30)throw new \InvalidArgumentException('BACKTEST_HISTORY_TOO_SHORT');
        usort($events,static fn(array $a,array $b):int=>strcmp((string)($a['as_of_utc']??''),(string)($b['as_of_utc']??'')));
        foreach($events as $event){$at=$this->utc($event['as_of_utc']??null);$available=$this->utc($event['available_at_utc']??null);
            if($at===null||$available===null||$available>$at)throw new \RuntimeException('LOOKAHEAD_OR_UNAVAILABLE_EVENT');}
        $n=count($events);$trainEnd=max(1,(int)floor($n*0.6));$validationEnd=max($trainEnd+1,(int)floor($n*0.8));
        if($validationEnd>=$n)throw new \InvalidArgumentException('INVALID_WALK_FORWARD_SPLIT');
        $training=array_slice($events,0,$trainEnd);$validation=array_slice($events,$trainEnd,$validationEnd-$trainEnd);
        $model=$fit($training,$events[$trainEnd-1]['as_of_utc']);if(!is_array($model))throw new \RuntimeException('MODEL_FIT_FAILED');
        $validationResult=$validate($model,$validation,$events[$trainEnd-1]['as_of_utc'],$events[$validationEnd-1]['as_of_utc']);
        if(!is_array($validationResult)||empty($validationResult['approved']))
            throw new \RuntimeException('MODEL_VALIDATION_FAILED');
        $signalGenerator=static function(array $snapshot,array $history,string $at)use($model,$validationResult,$predict):array{
            $signal=$predict($model,$validationResult,$snapshot,$history,$at);return is_array($signal)?$signal:['action'=>'NO_TRADE'];};
        $result=$this->run($strategyKey,$events,$signalGenerator,array_merge($config,['walk_forward_model_fitted'=>true]));
        $result['model_validation']=['status'=>'PASSED','training_observations'=>count($training),
            'validation_observations'=>count($validation),'validation_metrics'=>$validationResult,
            'training_end_utc'=>$events[$trainEnd-1]['as_of_utc'],'validation_end_utc'=>$events[$validationEnd-1]['as_of_utc'],
            'test_predictions_begin_utc'=>$events[$validationEnd]['as_of_utc'],
            'training_data_sha256'=>hash('sha256',json_encode($training,JSON_THROW_ON_ERROR)),
            'validation_data_sha256'=>hash('sha256',json_encode($validation,JSON_THROW_ON_ERROR)),
            'parameter_or_model_payload_persisted'=>false];
        $result['limitations']=array_values(array_diff($result['limitations'],['NO_PARAMETER_FITTING']));
        return $result;
    }

    public function persist(PDO $db,array $result,string $startedAtUtc): int
    {
        if(($result['classification']??null)!=='BACKTEST')throw new \InvalidArgumentException('INVALID_BACKTEST_RESULT');
        $ownsTransaction=!$db->inTransaction();if($ownsTransaction)$db->beginTransaction();
        try{
        $stmt=$db->prepare('INSERT INTO arbitrage_strategy_backtests
            (strategy_key,dataset_sha256,started_at_utc,ended_at_utc,train_end_utc,validation_end_utc,test_start_utc,status,metrics_json,limitations_json)
            VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$result['strategy'],$result['dataset_sha256'],$startedAtUtc,$result['test_end_utc'],$result['train_end_utc'],
            $result['validation_end_utc'],$result['test_start_utc'],$result['status'],json_encode(['metrics'=>$result['metrics'],
                'model_validation'=>$result['model_validation']??null],JSON_THROW_ON_ERROR),
            json_encode($result['limitations'],JSON_THROW_ON_ERROR)]);
        $backtestId=(int)$db->lastInsertId();$daily=[];
        foreach(($result['equity']??[]) as $point)if(isset($point['at'],$point['equity']))$daily[substr((string)$point['at'],0,10)]=(float)$point['equity'];
        ksort($daily,SORT_STRING);$previous=null;$insert=$db->prepare('INSERT INTO arbitrage_backtest_equity
            (backtest_id,strategy_key,event_date,equity,daily_return) VALUES (?,?,?,?,?)');
        foreach($daily as $date=>$value){$ret=$previous!==null&&$previous>0?$value/$previous-1:0.0;
            $insert->execute([$backtestId,$result['strategy'],$date,$value,$ret]);$previous=$value;}
        if($ownsTransaction)$db->commit();
        return $backtestId;
        }catch(\Throwable $e){if($ownsTransaction&&$db->inTransaction())$db->rollBack();throw $e;}
    }

    private function utc($value): ?int
    {
        if(!is_string($value)||$value==='')return null;$ts=strtotime($value.' UTC');return $ts===false?null:$ts;
    }
}
