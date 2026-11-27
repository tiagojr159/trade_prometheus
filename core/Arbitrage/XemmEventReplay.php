<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Historical maker-fill and delayed hedge replay. It never posts exchange orders. */
final class XemmEventReplay
{
    private ExecutionSimulator $execution;
    public function __construct(?ExecutionSimulator $execution=null){$this->execution=$execution??new ExecutionSimulator();}

    public function run(array $events,array $config=[]): array
    {
        if(count($events)<30)throw new \InvalidArgumentException('BACKTEST_HISTORY_TOO_SHORT');
        usort($events,static fn(array $a,array $b):int=>strcmp((string)($a['as_of_utc']??''),(string)($b['as_of_utc']??'')));
        $n=count($events);$trainEnd=max(1,(int)floor($n*0.6));$validationEnd=max($trainEnd+1,(int)floor($n*0.8));
        if($validationEnd >= $n)throw new \InvalidArgumentException('INVALID_WALK_FORWARD_SPLIT');
        $fee=(float)($config['hedge_fee_rate']??0.001);$slippage=(float)($config['slippage_bps']??2);
        $initial=(float)($config['initial_capital']??10000);$allocation=min(5,max(0,(float)($config['allocation_pct']??5)))/100;
        $latency=max(0,(int)($config['latency_seconds']??1));$cancelLatency=max(0,(int)($config['cancel_latency_seconds']??1));
        $maxAge=max(1,(int)($config['max_quote_age_seconds']??5));$circuit=max(1,(int)($config['circuit_breaker_failures']??3));
        if($initial<=0||$fee<0||$slippage<0)throw new \InvalidArgumentException('INVALID_XEMM_REPLAY_CONFIG');
        $orders=[];$hedges=[];$trades=[];$equity=[];$rejections=[];$history=[];$cancelled=0;$makerFillCount=0;
        $decisions=0;$cash=$initial;$failures=0;$killSwitch=false;$peak=$initial;$maxDrawdown=0.0;
        for($i=0;$i<$n;$i++){
            $event=$events[$i];$at=$this->utc($event['as_of_utc']??null);$available=$this->utc($event['available_at_utc']??null);
            if($at===null||$available===null||$available>$at)throw new \RuntimeException('LOOKAHEAD_OR_UNAVAILABLE_EVENT');
            $snapshot=$event['snapshot']??[];
            foreach(($snapshot['maker_fills']??[]) as $fill){
                $quoteId=(string)($fill['quote_id']??'');$fillAt=$this->utc($fill['filled_at_utc']??null);
                $fillAvailable=isset($fill['available_at_utc'])?$this->utc($fill['available_at_utc']):$available;
                if($quoteId===''||$fillAt===null||$fillAvailable===null||$fillAt>$at||$fillAvailable>$at||!isset($orders[$quoteId])
                    ||$orders[$quoteId]['status']!=='OPEN'||$fillAt<$orders[$quoteId]['eligible_epoch']
                    ||($orders[$quoteId]['cancel_effective_epoch']!==null&&$fillAt>=$orders[$quoteId]['cancel_effective_epoch']))continue;
                $qty=min((float)($fill['quantity']??0),$orders[$quoteId]['quantity_remaining']);if($qty<=0)continue;
                $makerPrice=is_numeric($fill['price']??null)?(float)$fill['price']:$orders[$quoteId]['price'];if($makerPrice<=0)continue;
                $orders[$quoteId]['quantity_remaining']-=$qty;$makerFillCount++;
                if($orders[$quoteId]['quantity_remaining']<=1e-12){$orders[$quoteId]['quantity_remaining']=0.0;
                    $orders[$quoteId]['status']='FILLED';$orders[$quoteId]['filled_at_utc']=gmdate('Y-m-d H:i:s',$fillAt);}
                $hedges[]=['quote_id'=>$quoteId,'symbol'=>$orders[$quoteId]['symbol'],'maker_venue'=>$orders[$quoteId]['venue'],
                    'hedge_venue'=>$orders[$quoteId]['hedge_venue'],'maker_side'=>$orders[$quoteId]['side'],'maker_price'=>$makerPrice,
                    'maker_fee_rate'=>$orders[$quoteId]['maker_fee_rate'],'quantity_remaining'=>$qty,'signal_index'=>$i,
                    'eligible_epoch'=>$fillAt+$latency,'created_at'=>$fillAt];
            }
            foreach($hedges as $hIndex=>&$hedge){
                if($at<$hedge['eligible_epoch'])continue;
                $venueState=strtoupper((string)($snapshot['exchange_status'][$hedge['hedge_venue']]??'UP'));
                $book=$snapshot['book_depth'][$hedge['hedge_venue']][$hedge['symbol']]??[];
                $hedgeSide=$hedge['maker_side']==='BUY'?'SELL':'BUY';$levels=$hedgeSide==='SELL'?($book['bids']??[]):($book['asks']??[]);
                if($venueState==='DOWN')$result=['quantity_filled'=>0.0];
                else $result=$this->execution->marketOrder($levels,$hedgeSide,(float)$hedge['quantity_remaining'],$fee,$slippage);
                $filled=(float)($result['quantity_filled']??0);
                if($filled>0){$makerFee=$hedge['maker_price']*$filled*$hedge['maker_fee_rate'];
                    $makerCash=$hedge['maker_price']*$filled*($hedge['maker_side']==='BUY'?-1:1)-$makerFee;
                    $tradeNet=$makerCash+(float)$result['net_cash_flow'];$gross=($hedgeSide==='SELL'
                        ?((float)$result['vwap']-$hedge['maker_price']):($hedge['maker_price']-(float)$result['vwap']))*$filled;
                    $trades[]=['symbol'=>$hedge['symbol'],'net_pnl'=>$tradeNet,'gross_pnl'=>$gross,
                        'fees'=>$makerFee+(float)$result['fee'],'slippage'=>(float)$result['slippage_cost'],
                        'capital_used'=>$hedge['maker_price']*$filled,'opened_at_utc'=>gmdate('Y-m-d H:i:s',$hedge['created_at']),
                        'closed_at_utc'=>gmdate('Y-m-d H:i:s',$at),'maker_venue'=>$hedge['maker_venue'],'hedge_venue'=>$hedge['hedge_venue'],
                        'quote_id'=>$hedge['quote_id']];$cash+=$tradeNet;$hedge['quantity_remaining']-=$filled;$failures=0;
                    if($hedge['quantity_remaining']<=1e-12)$hedge['quantity_remaining']=0.0;
                    else{$hedge['eligible_epoch']=$at+$latency;$rejections[]='PARTIAL_HEDGE_RETRY';}
                }else{$failures++;$rejections[]=$venueState==='DOWN'?'EXCHANGE_UNAVAILABLE':'NO_HEDGE_DEPTH';
                    $hedge['eligible_epoch']=$at+$latency;if($failures>=$circuit)$killSwitch=true;}
            }
            unset($hedge);$hedges=array_values(array_filter($hedges,static fn(array $h):bool=>$h['quantity_remaining']>1e-12));
            foreach($orders as $quoteId=>&$order){
                if($order['status']!=='OPEN')continue;
                if($order['cancel_effective_epoch']!==null&&$at>=$order['cancel_effective_epoch']){
                    $order['status']='CANCELLED';$order['cancelled_at_utc']=gmdate('Y-m-d H:i:s',$at);$cancelled++;continue;}
                if($at-$order['created_epoch']>=$maxAge&&$order['cancel_effective_epoch']===null){
                    $order['cancel_reason']='STALE';$order['cancel_effective_epoch']=$at+$cancelLatency;}
            }
            unset($order);
            if(!$killSwitch&&$i>=$validationEnd&&$i<$n-1){
                $signal=$snapshot['xemm_signal']??null;
                if($signal===null&&is_array($snapshot['xemm_quote_config']??null)){
                    $plan=(new XemmQuotePlanner())->plan($snapshot,$snapshot['xemm_quote_config']);
                    if(($plan['status']??'')==='POST_CANDIDATE')$signal=$plan['signal'];else $rejections[]='QUOTE_PLAN_'.($plan['reason']??'REJECTED');
                }
                $signal=is_array($signal)?$signal:['action'=>'NOOP'];$decisions++;$action=strtoupper((string)($signal['action']??'NOOP'));
                if($action==='CANCEL'){$quoteId=(string)($signal['quote_id']??'');if(isset($orders[$quoteId])&&$orders[$quoteId]['status']==='OPEN'){
                    $orders[$quoteId]['cancel_reason']='STRATEGY';$orders[$quoteId]['cancel_effective_epoch']=$at+$cancelLatency;}}
                elseif($action==='POST'){
                    $quoteId=(string)($signal['quote_id']??'');$side=strtoupper((string)($signal['maker_side']??''));
                    $venue=(string)($signal['maker_venue']??'');$hedgeVenue=(string)($signal['hedge_venue']??'');
                    $price=(float)($signal['price']??0);$qty=(float)($signal['quantity']??0);$makerFee=(float)($signal['maker_fee_rate']??0);
                    $signalObserved=$this->utc($signal['observed_at_utc']??null);$signalAvailable=$this->utc($signal['available_at_utc']??null);
                    $quoteExposure=0.0;$venueExposure=[];
                    foreach($orders as $existingOrder)if($existingOrder['status']==='OPEN'){$openNotional=$existingOrder['price']*$existingOrder['quantity_remaining'];
                        $quoteExposure+=$openNotional;foreach([$existingOrder['venue'],$existingOrder['hedge_venue']] as $openVenue)
                            $venueExposure[$openVenue]=($venueExposure[$openVenue]??0)+$openNotional;}
                    foreach($hedges as $openHedge){$openNotional=$openHedge['maker_price']*$openHedge['quantity_remaining'];$quoteExposure+=$openNotional;
                        $venueExposure[$openHedge['maker_venue']]=($venueExposure[$openHedge['maker_venue']]??0)+$openNotional;}
                    $availableEquity=$cash;if($quoteId===''||isset($orders[$quoteId])||!in_array($side,['BUY','SELL'],true)||$venue===''||$hedgeVenue===$venue
                        ||$price<=0||$qty<=0||$makerFee<0||$signalObserved===null||$signalAvailable===null||$signalObserved>$at||$signalAvailable>$at
                        ||$at-$signalObserved>$maxAge||$price*$qty>$availableEquity*$allocation||$quoteExposure+$price*$qty>$availableEquity
                        ||(($venueExposure[$venue]??0)+$price*$qty)/max(1e-12,$availableEquity)*100>(float)($config['max_exchange_exposure_pct']??25)
                        ||(($venueExposure[$hedgeVenue]??0)+$price*$qty)/max(1e-12,$availableEquity)*100>(float)($config['max_exchange_exposure_pct']??25))
                        {$rejections[]='INVALID_STALE_OR_OVERALLOCATED_QUOTE';}
                    else $orders[$quoteId]=['quote_id'=>$quoteId,'symbol'=>(string)($signal['symbol']??''),'venue'=>$venue,
                        'hedge_venue'=>$hedgeVenue,'side'=>$side,'price'=>$price,'quantity_initial'=>$qty,'quantity_remaining'=>$qty,
                        'maker_fee_rate'=>$makerFee,'created_epoch'=>$at,'eligible_epoch'=>$at+$latency,'status'=>'OPEN',
                        'cancel_effective_epoch'=>null,'cancel_reason'=>null];
                }
            }
            $unrealized=0.0;foreach($hedges as $h){$markBook=$snapshot['books'][$h['hedge_venue']][$h['symbol']]??[];
                $mark=$h['maker_side']==='BUY'?($markBook['bid']??null):($markBook['ask']??null);
                if(is_numeric($mark))$unrealized+=(($h['maker_side']==='BUY'?(float)$mark-$h['maker_price']:$h['maker_price']-(float)$mark)*$h['quantity_remaining']);}
            $current=$cash+$unrealized;$peak=max($peak,$current);$dd=$peak>0?($peak-$current)/$peak*100:0;$maxDrawdown=max($maxDrawdown,$dd);
            $equity[]=['at'=>gmdate('Y-m-d H:i:s',$at),'equity'=>$current];
            if($dd>=(float)($config['max_portfolio_drawdown_pct']??10))$killSwitch=true;
            $history[]=['as_of_utc'=>gmdate('Y-m-d H:i:s',$at),'snapshot'=>$snapshot];
        }
        $testStart=$events[$validationEnd]['as_of_utc'];$testEquity=array_values(array_filter($equity,static fn(array $p):bool=>$p['at']>=$testStart));
        $daily=[];foreach($testEquity as $point)$daily[substr($point['at'],0,10)]=$point;
        $metrics=PerformanceAnalytics::calculate($trades,array_values($daily));$metrics['block_bootstrap']=BlockBootstrap::analyze($trades,$initial,
            (int)($config['bootstrap_trials']??1000),(int)($config['bootstrap_block_size']??5),hash('sha256',json_encode($events,JSON_THROW_ON_ERROR)));
        $openOrders=array_values(array_filter($orders,static fn(array $o):bool=>$o['status']==='OPEN'));
        return ['status'=>$trades?'COMPLETED':'INSUFFICIENT_EXECUTION_DATA','strategy'=>'CROSS_EXCHANGE_MM','classification'=>'BACKTEST',
            'events'=>$n,'train_end_utc'=>$events[$trainEnd-1]['as_of_utc'],'validation_end_utc'=>$events[$validationEnd-1]['as_of_utc'],
            'test_start_utc'=>$testStart,'test_end_utc'=>$events[$n-1]['as_of_utc'],'decision_count'=>$decisions,
            'maker_fill_count'=>$makerFillCount,'cancelled_order_count'=>$cancelled,'quotes'=>$orders,'open_quotes'=>$openOrders,
            'open_hedges'=>$hedges,'trade_count'=>count($trades),'trades'=>$trades,'equity'=>$testEquity,'metrics'=>$metrics,
            'max_drawdown_pct'=>$maxDrawdown,'kill_switch_triggered'=>$killSwitch,'rejections'=>array_count_values($rejections),
            'dataset_sha256'=>hash('sha256',json_encode($events,JSON_THROW_ON_ERROR)),
            'limitations'=>['HISTORICAL_MAKER_FILL_EVENTS_REQUIRED','NO_QUEUE_POSITION_MODEL','NO_FUNDING_SETTLEMENTS','NO_LIVE_ORDER_TRANSMISSION']];
    }

    private function utc($value): ?int
    {if(!is_string($value)||$value==='')return null;$ts=strtotime($value.' UTC');return $ts===false?null:$ts;}
}
