<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

final class FundingRateArbitrage extends AbstractStrategy
{
    public function key(): string { return 'FUNDING_RATE'; }
    public function generateSignals(array $s): array
    {
        $out=[]; $rates=$s['funding'] ?? []; $books=$s['books'] ?? []; $fee=(float)($s['taker_fee_rate'] ?? 0.001);
        foreach ($rates as $symbol=>$venues) {
            foreach ($venues as $longVenue=>$long) foreach ($venues as $shortVenue=>$short) {
                if ($longVenue===$shortVenue || !isset($books[$symbol][$longVenue],$books[$symbol][$shortVenue])) continue;
                $lb=$books[$symbol][$longVenue]; $sb=$books[$symbol][$shortVenue];
                if (!$this->validBook($lb)||!$this->validBook($sb)) continue;
                $lr=$this->number($long['rate']??null); $sr=$this->number($short['rate']??null);
                $li=$this->number($long['interval_hours']??null); $si=$this->number($short['interval_hours']??null);
                if ($lr===null||$sr===null||$li===null||$si===null||$li<=0||$si<=0) continue;
                $diff=$sr*24/$si-$lr*24/$li; $markL=(float)($lb['mark']??(($lb['bid']+$lb['ask'])/2)); $markS=(float)($sb['mark']??(($sb['bid']+$sb['ask'])/2));
                $basisBps=abs($markL-$markS)/max($markL,$markS)*10000; $notional=(float)($s['notional']??1000);
                $income=$notional*$diff*(float)($s['funding_horizon_days']??1);
                $costs=$notional*(2*$fee*2 + $basisBps/10000 + (float)($s['slippage_rate']??0.0005))
                    +(float)($s['borrowing_cost']??0)+(float)($s['rebalancing_cost']??0)+(float)($s['other_operational_costs']??0);
                $net=$income-$costs;
                $liquidityUsd=min((float)($lb['ask_qty']??0)*(float)$lb['ask'],(float)($sb['bid_qty']??0)*(float)$sb['bid']);
                $equity=max(1e-12,(float)($s['virtual_equity']??10000));$perVenuePct=100*$notional/$equity;
                $out[]=['strategy'=>$this->key(),'symbol'=>$symbol,'action'=>'LONG_'.$longVenue.'_SHORT_'.$shortVenue,
                    'gross_profit'=>$income,'costs'=>$costs,'net_profit'=>$net,
                    'capital_required'=>2*$notional,'capital_pct'=>200*$notional/$equity,
                    'exchange_exposure_pct'=>$perVenuePct,'venue_exposures_pct'=>[$longVenue=>$perVenuePct,$shortVenue=>$perVenuePct],
                    'data_quality'=>!empty($long['observed_at'])&&!empty($short['observed_at'])&&!empty($lb['observed_at'])&&!empty($sb['observed_at']),
                    'details'=>['normalized_daily_spread'=>$diff,'basis_bps'=>$basisBps,'long_rate'=>$lr,'short_rate'=>$sr,'notional'=>$notional,
                        'liquidity_available_usd'=>$liquidityUsd,
                        'rate_sources'=>[$long['source']??null,$short['source']??null],
                        'funding_interval_hours'=>[$li,$si],'income_status'=>'ESTIMATED_NOT_SETTLED','directional_hedge'=>'SIZE_BY_DELTA_NOTIONAL']];
                $out[count($out)-1]['details']['history']=[
                    $longVenue=>$s['funding_history'][$symbol][$longVenue]??['7d'=>null,'30d'=>null,'90d'=>null],
                    $shortVenue=>$s['funding_history'][$symbol][$shortVenue]??['7d'=>null,'30d'=>null,'90d'=>null],
                ];
            }
        }
        return $out;
    }

    public function simulateExecution(array $opportunity,array $snapshot): array
    {
        $parts=explode('_',$opportunity['action']??'');if(count($parts)<4)return parent::simulateExecution($opportunity,$snapshot);
        $longVenue=$parts[1];$shortVenue=$parts[3];$symbol=$opportunity['symbol']??'';
        $long=$snapshot['books'][$symbol][$longVenue]??[];$short=$snapshot['books'][$symbol][$shortVenue]??[];
        $notional=(float)($opportunity['details']['notional']??0);$mark=(float)($long['mark']??(($long['bid']??0)+($long['ask']??0))/2);
        if($notional<=0||$mark<=0||empty($long['asks'])||empty($short['bids'])
            || !isset($snapshot['taker_fee_rate']))return ['mode'=>'SIMULATION_ONLY','status'=>'INSUFFICIENT_DEPTH_DATA','fillable'=>false];
        $result=(new ExecutionSimulator())->hedge($long['asks'],$short['bids'],$notional/$mark,
            (float)$snapshot['taker_fee_rate'],(float)($snapshot['slippage_bps']??0));
        $result['funding_income_realized']=0.0;$result['funding_income_estimated']=$opportunity['gross_profit'];
        $result['settlement_status']='NOT_SETTLED';return $result;
    }
}
