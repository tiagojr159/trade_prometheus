<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

final class RiskManagementEngine
{
    public function evaluate(array $opportunity,array $limits=[],array $portfolio=[]): array
    {
        $reasons=[];$marginStress=null;$crossMarginStress=null;$equity=(float)($portfolio['equity']??$limits['virtual_equity']??10000);
        $maxDrawdown=(float)($limits['max_portfolio_drawdown_pct']??10);
        $maxAllocation=(float)($limits['max_opportunity_allocation_pct']??5);
        $maxExchange=(float)($limits['max_exchange_exposure_pct']??25);
        $capital=(float)($opportunity['capital_required']??($equity*(float)($opportunity['capital_pct']??0)/100));
        if(!empty($opportunity['stale']))$reasons[]='STALE_MARKET_DATA';
        if($equity<=0)$reasons[]='INVALID_VIRTUAL_EQUITY';
        if(($portfolio['drawdown_pct']??0)>=$maxDrawdown)$reasons[]='KILL_SWITCH_DRAWDOWN';
        if(!empty($portfolio['kill_switch']))$reasons[]='KILL_SWITCH_ACTIVE';
        if(!empty($portfolio['duplicate_key']))$reasons[]='DUPLICATE_ORDER_BLOCKED';
        if((int)($portfolio['recent_failures']??0)>=(int)($limits['circuit_breaker_failures']??3))$reasons[]='CIRCUIT_BREAKER_OPEN';
        $rateSources=$opportunity['details']['rate_sources']??[];
        if(count(array_unique(array_filter($rateSources,static fn($v):bool=>is_string($v)&&$v!=='')))>1)$reasons[]='FUNDING_RATE_SOURCE_MISMATCH';
        $observationOnly=($opportunity['strategy']??'')==='MEV_OBSERVATION';
        if(!$observationOnly&&($capital<=0||$equity>0&&$capital/$equity*100>$maxAllocation))$reasons[]='ALLOCATION_LIMIT';
        $venueExposures=$opportunity['venue_exposures_pct']??[];$currentVenueExposures=$portfolio['venue_exposure_pct']??[];
        if($venueExposures){foreach($venueExposures as $venue=>$pct){$total=(float)$pct+(float)($currentVenueExposures[$venue]??0);
            if($total>$maxExchange)$reasons[]='EXCHANGE_EXPOSURE_LIMIT';}}
        elseif((float)($opportunity['exchange_exposure_pct']??0)+(float)($portfolio['exchange_exposure_pct']??0)>$maxExchange)
            $reasons[]='EXCHANGE_EXPOSURE_LIMIT';
        $gross=(float)($portfolio['gross_exposure']??0)+$capital;
        if($gross>$equity)$reasons[]='GROSS_EXPOSURE_EXCEEDS_EQUITY';
        if(($opportunity['net_profit']??0)<=0)$reasons[]='NON_POSITIVE_NET_EDGE';
        if(empty($opportunity['data_quality']))$reasons[]='INSUFFICIENT_DATA_QUALITY';
        if(array_key_exists('margin_positions',$opportunity)){
            try{$marginStress=(new MarginStressEngine())->evaluate($opportunity['margin_positions'],(array)($opportunity['margin_scenarios']??[]));
                if(!$marginStress['approved'])$reasons[]='LIQUIDATION_STRESS';
            }catch(\InvalidArgumentException $e){$reasons[]='INVALID_MARGIN_STRESS_INPUT';}
        }
        if(array_key_exists('cross_margin_positions',$opportunity)){
            try{$crossMarginStress=(new MarginStressEngine())->evaluateCrossMargin($opportunity['cross_margin_positions'],
                    (array)($opportunity['cross_margin_scenarios']??[]),(array)($opportunity['cross_margin_collateral_by_venue']??[]));
                if(!$crossMarginStress['approved'])$reasons[]='CROSS_MARGIN_LIQUIDATION_STRESS';
            }catch(\InvalidArgumentException $e){$reasons[]='INVALID_CROSS_MARGIN_STRESS_INPUT';}
        }
        return ['approved'=>$reasons===[],'reasons'=>array_values(array_unique($reasons)),
            'capital_required'=>$capital,'gross_exposure_after'=>$gross,'virtual_equity'=>$equity,
            'margin_stress'=>$marginStress,'cross_margin_stress'=>$crossMarginStress,'mode'=>'PAPER_ONLY','leverage_enabled'=>false,'live_trading_enabled'=>false];
    }
}
