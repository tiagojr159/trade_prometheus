<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Isolated-margin stress calculator. Venue margin rates and shocks must be supplied by the caller. */
final class MarginStressEngine
{
    /** Checks a marked isolated position against venue maintenance and liquidation rates. */
    public function evaluateAtMark(array $position,float $markPrice): array
    {
        $side=strtoupper((string)($position['side']??''));$entry=(float)($position['entry_price']??0);
        $quantity=(float)($position['quantity']??0);$collateral=(float)($position['collateral']??0);
        $maintenance=(float)($position['maintenance_margin_rate']??-1);$fee=(float)($position['liquidation_fee_rate']??-1);
        $venue=(string)($position['venue']??'');$symbol=(string)($position['symbol']??'');
        if(!in_array($side,['LONG','SHORT'],true)||$entry<=0||$markPrice<=0||$quantity<=0||$collateral<0
            ||$venue===''||$symbol===''||$maintenance<0||$fee<0||$maintenance+$fee>=1)
            throw new \InvalidArgumentException('INVALID_MARKED_MARGIN_POSITION');
        $pnl=($side==='LONG'?$markPrice-$entry:$entry-$markPrice)*$quantity;$notional=$markPrice*$quantity;
        $buffer=$collateral+$pnl-$notional*($maintenance+$fee);
        $shock=$side==='LONG'?max(0,($entry-$markPrice)/$entry):max(0,($markPrice-$entry)/$entry);
        return ['status'=>$buffer>0?'MARGIN_HEALTHY':'LIQUIDATION_RISK','approved'=>$buffer>0,
            'venue'=>$venue,'symbol'=>$symbol,'side'=>$side,'entry_price'=>$entry,'mark_price'=>$markPrice,
            'adverse_move_pct'=>$shock*100,'collateral'=>$collateral,'pnl_at_mark'=>$pnl,
            'maintenance_required'=>$notional*$maintenance,'liquidation_reserve'=>$notional*$fee,
            'margin_buffer'=>$buffer,'liquidation_price'=>$this->liquidationPrice($side,$entry,$quantity,$collateral,$maintenance,$fee)];
    }

    public function evaluate(array $positions,array $scenarios): array
    {
        if($positions===[]||$scenarios===[])throw new \InvalidArgumentException('MARGIN_POSITIONS_AND_SCENARIOS_REQUIRED');
        $results=[];$liquidationCount=0;
        foreach($positions as $index=>$position){
            $side=strtoupper((string)($position['side']??''));$entry=(float)($position['entry_price']??0);
            $quantity=(float)($position['quantity']??0);$collateral=(float)($position['collateral']??0);
            $maintenance=(float)($position['maintenance_margin_rate']??-1);$liquidationFee=(float)($position['liquidation_fee_rate']??-1);
            $venue=(string)($position['venue']??'');$symbol=(string)($position['symbol']??'');
            if(!in_array($side,['LONG','SHORT'],true)||$entry<=0||$quantity<=0||$collateral<0||$venue===''||$symbol===''
                ||$maintenance<0||$liquidationFee<0||$maintenance+$liquidationFee>=1)
                throw new \InvalidArgumentException('INVALID_MARGIN_POSITION');
            $liquidationPrice=$this->liquidationPrice($side,$entry,$quantity,$collateral,$maintenance,$liquidationFee);
            foreach($scenarios as $scenarioName=>$scenario){
                $shock=(float)($scenario[$venue]??-1);
                if(!is_finite($shock)||$shock<0||$shock>=1)throw new \InvalidArgumentException('INVALID_ADVERSE_SHOCK');
                $stressedPrice=$entry*($side==='LONG'?1-$shock:1+$shock);
                $pnl=($side==='LONG'?$stressedPrice-$entry:$entry-$stressedPrice)*$quantity;
                $stressedNotional=$stressedPrice*$quantity;$maintenanceRequired=$stressedNotional*$maintenance;
                $liquidationReserve=$stressedNotional*$liquidationFee;$equityAfterShock=$collateral+$pnl;
                $buffer=$equityAfterShock-$maintenanceRequired-$liquidationReserve;
                $liquidated=$buffer<=0;if($liquidated)$liquidationCount++;
                $results[]=['position_index'=>$index,'venue'=>$venue,'symbol'=>$symbol,'side'=>$side,
                    'scenario'=>(string)$scenarioName,'adverse_move_pct'=>$shock*100,'entry_price'=>$entry,
                    'stressed_price'=>$stressedPrice,'collateral'=>$collateral,'pnl_at_stress'=>$pnl,
                    'maintenance_required'=>$maintenanceRequired,'liquidation_reserve'=>$liquidationReserve,
                    'margin_buffer'=>$buffer,'liquidation_price'=>$liquidationPrice,
                    'liquidated_under_scenario'=>$liquidated];
            }
        }
        return ['status'=>$liquidationCount===0?'STRESS_PASSED':'LIQUIDATION_RISK',
            'approved'=>$liquidationCount===0,'position_count'=>count($positions),'scenario_count'=>count($scenarios),
            'liquidation_scenarios'=>$liquidationCount,'results'=>$results,
            'assumptions'=>['ISOLATED_MARGIN','NO_CROSS_COLLATERAL','VENUE_RATES_AND_SHOCKS_SUPPLIED_BY_CALLER']];
    }

    /** Portfolio cross-margin stress: account collateral is shared once per venue across all its positions. */
    public function evaluateCrossMargin(array $positions,array $scenarios,array $accountCollateralByVenue): array
    {
        if($positions===[]||$scenarios===[]||$accountCollateralByVenue===[])
            throw new \InvalidArgumentException('CROSS_MARGIN_INPUTS_REQUIRED');
        $groups=[];foreach($positions as $index=>$position){$side=strtoupper((string)($position['side']??''));$entry=(float)($position['entry_price']??0);
            $qty=(float)($position['quantity']??0);$venue=(string)($position['venue']??'');$symbol=(string)($position['symbol']??'');
            $maintenance=(float)($position['maintenance_margin_rate']??-1);$fee=(float)($position['liquidation_fee_rate']??-1);
            if(!in_array($side,['LONG','SHORT'],true)||$entry<=0||$qty<=0||$venue===''||$symbol===''||$maintenance<0||$fee<0||$maintenance+$fee>=1)
                throw new \InvalidArgumentException('INVALID_CROSS_MARGIN_POSITION');
            $groups[$venue][]=[$index,$position,$side,$entry,$qty,$maintenance,$fee];}
        foreach($groups as $venue=>$items){$collateral=$accountCollateralByVenue[$venue]??null;
            if(!is_numeric($collateral)||!is_finite((float)$collateral)||(float)$collateral<0)
                throw new \InvalidArgumentException('CROSS_MARGIN_COLLATERAL_MISSING');}
        $results=[];$breaches=0;
        foreach($scenarios as $name=>$shocks){if(!is_array($shocks))throw new \InvalidArgumentException('INVALID_CROSS_MARGIN_SCENARIO');
            foreach($groups as $venue=>$items){$shock=(float)($shocks[$venue]??NAN);
                if(!is_finite($shock)||$shock<=-0.99||$shock>5)throw new \InvalidArgumentException('INVALID_CROSS_MARGIN_SHOCK');
                $pnl=0.0;$maintenanceRequired=0.0;$liquidationReserve=0.0;$details=[];
                foreach($items as [$index,$position,$side,$entry,$qty,$maintenance,$fee]){$price=$entry*(1+$shock);
                    $legPnl=($side==='LONG'?$price-$entry:$entry-$price)*$qty;$notional=$price*$qty;
                    $legMaintenance=$notional*$maintenance;$legFee=$notional*$fee;$pnl+=$legPnl;$maintenanceRequired+=$legMaintenance;$liquidationReserve+=$legFee;
                    $details[]=['position_index'=>$index,'symbol'=>$position['symbol'],'side'=>$side,'stressed_price'=>$price,
                        'pnl'=>$legPnl,'maintenance_required'=>$legMaintenance,'liquidation_reserve'=>$legFee];}
                $collateral=(float)$accountCollateralByVenue[$venue];$buffer=$collateral+$pnl-$maintenanceRequired-$liquidationReserve;
                if($buffer<=0)$breaches++;$results[]=['scenario'=>(string)$name,'venue'=>$venue,'account_collateral'=>$collateral,
                    'market_return_pct'=>$shock*100,'portfolio_pnl'=>$pnl,'maintenance_required'=>$maintenanceRequired,'liquidation_reserve'=>$liquidationReserve,
                    'margin_buffer'=>$buffer,'liquidated_under_scenario'=>$buffer<=0,'positions'=>$details];}}
        return ['status'=>$breaches===0?'STRESS_PASSED':'LIQUIDATION_RISK','approved'=>$breaches===0,'margin_mode'=>'CROSS',
            'venue_accounts'=>count($groups),'scenario_count'=>count($scenarios),'liquidation_scenarios'=>$breaches,'results'=>$results,
            'assumptions'=>['ACCOUNT_COLLATERAL_SHARED_PER_VENUE','VENUE_RATES_AND_SHOCKS_SUPPLIED_BY_CALLER','NO_AUTOMATIC_LIQUIDATION_CASCADE']];
    }

    private function liquidationPrice(string $side,float $entry,float $quantity,float $collateral,float $maintenance,float $fee): ?float
    {
        $rate=$maintenance+$fee;
        if($side==='LONG'){$price=($entry*$quantity-$collateral)/($quantity*(1-$rate));return $price>0?$price:null;}
        return ($entry*$quantity+$collateral)/($quantity*(1+$rate));
    }
}
