<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

final class CashAndCarry extends AbstractStrategy
{
    public function key(): string { return 'CASH_AND_CARRY'; }
    public function generateSignals(array $s): array
    {
        $out=[]; foreach (($s['basis']??[]) as $row) {
            $spot=$this->number($row['spot_ask']??null); $future=$this->number($row['future_bid']??null); $days=$this->number($row['days_to_expiration']??null);
            if (!$spot||!$future||$spot<=0||$days===null||$days<=0) continue;
            $notional=(float)($s['notional']??1000);$basis=($future-$spot)/$spot; $gross=$notional*$basis;
            $annual=$basis*365/$days; $fees=$notional*4*(float)($s['taker_fee_rate']??0.001);
            $costs=$fees+(float)($row['borrow_cost']??0)+(float)($row['slippage_cost']??0);
            $liquidityUsd=min((float)($row['spot_book']['ask_qty']??0)*$spot,(float)($row['future_book']['bid_qty']??0)*$future);
            $equity=max(1e-12,(float)($s['virtual_equity']??10000));$perLegPct=100*$notional/$equity;
            $venueExposure=[];foreach([$row['spot_venue']??'UNKNOWN',$row['future_venue']??'UNKNOWN'] as $venue)
                $venueExposure[$venue]=($venueExposure[$venue]??0)+$perLegPct;
            $out[]=['strategy'=>$this->key(),'symbol'=>$row['symbol']??'','action'=>'BUY_SPOT_SELL_DATED_FUTURE',
                'gross_profit'=>$gross,'costs'=>$costs,'net_profit'=>$gross-$costs,'capital_required'=>2*$notional,
                'capital_pct'=>200*$notional/$equity,'exchange_exposure_pct'=>$perLegPct,'venue_exposures_pct'=>$venueExposure,
                'data_quality'=>!empty($row['observed_at'])&&!empty($row['spot_book']['observed_at'])&&!empty($row['future_book']['observed_at'])
                    &&!empty($row['cost_model_complete']),
                'details'=>['basis'=>$basis,'annualized_basis'=> $annual,'days_to_expiration'=>$days,'notional'=>$notional,
                    'liquidity_available_usd'=>$liquidityUsd,
                    'annualization_scope'=>'DATED_CONTRACT_ONLY','future_symbol'=>$row['future_symbol']??null,
                    'cost_model_status'=>$row['cost_model_status']??'UNVERIFIED']];
        } return $out;
    }

    public function simulateExecution(array $opportunity,array $snapshot): array
    {
        $row=null;foreach(($snapshot['basis']??[]) as $candidate)if(($candidate['symbol']??null)===$opportunity['symbol']
            &&(($opportunity['details']['future_symbol']??null)===null||($candidate['future_symbol']??null)===$opportunity['details']['future_symbol'])){$row=$candidate;break;}
        if(!$row||empty($row['spot_book']['asks'])||empty($row['future_book']['bids'])||!isset($snapshot['taker_fee_rate']))
            return ['mode'=>'SIMULATION_ONLY','status'=>'INSUFFICIENT_DEPTH_DATA','fillable'=>false];
        $spot=(float)($row['spot_ask']??0);if($spot<=0)return ['mode'=>'SIMULATION_ONLY','status'=>'INVALID_SPOT_PRICE','fillable'=>false];
        $qty=(float)$opportunity['details']['notional']/$spot;
        $result=(new ExecutionSimulator())->hedge($row['spot_book']['asks'],$row['future_book']['bids'],$qty,
            (float)$snapshot['taker_fee_rate'],(float)($snapshot['slippage_bps']??0));
        $result['status']=$result['status']==='HEDGED'?'OPENED_SIMULATED_CASH_AND_CARRY':$result['status'];
        $result['convergence_pnl_realized']=0.0;$result['basis_return_estimated']=$opportunity['details']['basis'];
        return $result;
    }
}
