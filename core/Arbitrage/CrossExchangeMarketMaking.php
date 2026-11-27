<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

final class CrossExchangeMarketMaking extends AbstractStrategy
{
    public function key(): string { return 'CROSS_EXCHANGE_MM'; }
    public function generateSignals(array $s): array
    {
        $out=[];$calibrator=new XemmFillProbabilityCalibrator(); foreach (($s['xemm']??[]) as $r) {
            $maker=$r['maker_book']??[]; $hedge=$r['hedge_book']??[]; if (!$this->validBook($maker)||!$this->validBook($hedge)) continue;
            $side=strtoupper((string)($r['maker_side']??''));
            if (!in_array($side,['BUY','SELL'],true)) continue;
            $makerPrice=$side==='BUY'?(float)$maker['bid']:(float)$maker['ask'];
            $hedgePrice=$side==='BUY'?(float)$hedge['bid']:(float)$hedge['ask'];
            $makerDepth=(float)($side==='BUY'?$maker['bid_qty']:$maker['ask_qty']);
            $hedgeDepth=(float)($side==='BUY'?$hedge['bid_qty']:$hedge['ask_qty']);
            $qty=min((float)($r['quantity']??0),$makerDepth,$hedgeDepth);
            if ($qty<=0) continue;
            $calibration=null;$fillCalibrated=!empty($r['fill_probability_calibrated']);$fillProbability=$this->number($r['fill_probability']??null);
            if(isset($s['xemm_fill_history']['training'],$s['xemm_fill_history']['validation'])){
                $mid=((float)$maker['bid']+(float)$maker['ask'])/2;$quoteSpread=$this->number($r['spread_bps']??null);
                if($quoteSpread===null&&$mid>0)$quoteSpread=abs($makerPrice-$mid)/$mid*10000;
                $calibration=$calibrator->estimate($s['xemm_fill_history']['training'],$s['xemm_fill_history']['validation'],[
                    'maker_side'=>$side,'spread_bps'=>$quoteSpread,'latency_ms'=>$r['latency_ms']??null,
                    'depth_ratio'=>$qty/max($makerDepth,1e-12)],(string)($r['available_at_utc']??$r['observed_at']??$s['snapshot_at']??''),
                    $s['xemm_fill_calibration_config']??[]);
                if(($calibration['status']??'')==='CALIBRATED'){$fillProbability=(float)$calibration['probability'];$fillCalibrated=true;}
                else $fillCalibrated=false;
            }
            if($fillProbability===null||$fillProbability<0||$fillProbability>1)continue;
            $gross=($side==='BUY'?$hedgePrice-$makerPrice:$makerPrice-$hedgePrice)*$qty;
            $fee=(float)($r['maker_fee_rate']??0)+(float)($r['taker_fee_rate']??0);
            $costs=($makerPrice+$hedgePrice)*$qty*$fee+(float)($r['slippage_cost']??0)+(float)($r['adverse_selection_cost']??0);
            $net=($gross-$costs)*$fillProbability;
            $equity=max(1e-12,(float)($s['virtual_equity']??10000));$makerExposure=100*$qty*$makerPrice/$equity;$hedgeExposure=100*$qty*$hedgePrice/$equity;
            $venueExposure=[];$makerVenue=(string)($r['maker_venue']??'UNKNOWN');$hedgeVenue=(string)($r['hedge_venue']??'UNKNOWN');
            $venueExposure[$makerVenue]=($venueExposure[$makerVenue]??0)+$makerExposure;
            $venueExposure[$hedgeVenue]=($venueExposure[$hedgeVenue]??0)+$hedgeExposure;
            $out[]=['strategy'=>$this->key(),'symbol'=>$r['symbol']??'','action'=>'MAKER_'.$r['maker_side'].'_HEDGE_OTHER_VENUE',
                'gross_profit'=>$gross,'costs'=>$costs,'net_profit'=>$net,'capital_required'=>$qty*($makerPrice+$hedgePrice),
                'capital_pct'=>100*$qty*($makerPrice+$hedgePrice)/$equity,'exchange_exposure_pct'=>max($makerExposure,$hedgeExposure),
                'venue_exposures_pct'=>$venueExposure,'data_quality'=>!empty($r['observed_at'])&&isset($r['latency_ms'])
                    &&!empty($maker['observed_at'])&&!empty($hedge['observed_at'])&&$fillCalibrated,
                'details'=>['executable_quantity'=>$qty,'maker_price'=>$makerPrice,'hedge_price'=>$hedgePrice,
                    'liquidity_available_usd'=>min($makerDepth*$makerPrice,$hedgeDepth*$hedgePrice),
                    'maker_venue'=>$r['maker_venue']??null,'hedge_venue'=>$r['hedge_venue']??null,'maker_side'=>$side,
                    'fill_probability'=>$fillProbability,'fill_probability_status'=>$fillCalibrated?'CALIBRATED':'UNVALIDATED',
                    'fill_probability_calibration'=>$calibration,
                    'latency_ms'=>$r['latency_ms']??null,
                    'partial_fill_risk'=>true,'stale_order_policy'=>'CANCEL_REQUIRED']];
        } return $out;
    }

    public function simulateExecution(array $opportunity,array $snapshot): array
    {
        $source=null;foreach(($snapshot['xemm']??[]) as $row)if(($row['symbol']??null)===($opportunity['symbol']??null)
            &&strtoupper((string)($row['maker_side']??''))===($opportunity['details']['maker_side']??'')){$source=$row;break;}
        if(!$source||empty($source['maker_fill_confirmed']))return ['mode'=>'SIMULATION_ONLY','status'=>'WAITING_FOR_CONFIRMED_MAKER_FILL',
            'fillable'=>false,'live_trading_enabled'=>false];
        $levels=($opportunity['details']['maker_side']??'')==='BUY'?($source['hedge_book']['bids']??[]):($source['hedge_book']['asks']??[]);
        if(!$levels)return ['mode'=>'SIMULATION_ONLY','status'=>'HEDGE_DEPTH_UNAVAILABLE','fillable'=>false];
        $side=($opportunity['details']['maker_side']??'')==='BUY'?'SELL':'BUY';
        $hedge=(new ExecutionSimulator())->marketOrder($levels,$side,(float)$opportunity['details']['executable_quantity'],
            (float)($source['taker_fee_rate']??$snapshot['taker_fee_rate']??0.001),(float)($source['slippage_bps']??$snapshot['slippage_bps']??0));
        $makerQty=(float)$opportunity['details']['executable_quantity'];$hedged=(float)$hedge['quantity_filled'];
        $makerFee=$makerQty*(float)$opportunity['details']['maker_price']*(float)($source['maker_fee_rate']??$snapshot['maker_fee_rate']??0);
        $makerCash=(float)$opportunity['details']['maker_price']*$makerQty*(($opportunity['details']['maker_side']??'')==='SELL'?1:-1);
        $hedgeCash=(float)($hedge['net_cash_flow']??0);$residual=$makerQty-$hedged;
        return ['mode'=>'SIMULATION_ONLY','maker_fill_confirmed'=>true,'maker_fee'=>$makerFee,'hedge'=>$hedge,
            'simulated_pair_pnl'=>$residual<=1e-12?$makerCash+$hedgeCash-$makerFee:null,'residual_unhedged_quantity'=>$residual,
            'status'=>$hedge['status']==='FILLED'?'HEDGED':'PARTIAL_HEDGE','live_trading_enabled'=>false];
    }
}
