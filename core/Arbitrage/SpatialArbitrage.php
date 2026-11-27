<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

final class SpatialArbitrage extends AbstractStrategy
{
    public function key(): string { return 'SPATIAL'; }
    public function generateSignals(array $s): array
    {
        $out=[]; foreach (($s['books']??[]) as $symbol=>$venues) foreach($venues as $buyVenue=>$buy) foreach($venues as $sellVenue=>$sell) {
            if($buyVenue===$sellVenue||!$this->validBook($buy)||!$this->validBook($sell))continue;
            $target=(float)($s['quantity']??((float)($s['notional']??0)/max((float)$buy['ask'],1e-12)));
            $qty=min((float)$buy['ask_qty'],(float)$sell['bid_qty'],$target); if($qty<=0)continue;
            $gross=((float)$sell['bid']-(float)$buy['ask'])*$qty;$fees=$qty*((float)$buy['ask']+(float)$sell['bid'])*(float)($s['taker_fee_rate']??0.001);
            $costs=$fees+(float)($s['transfer_cost']??0)+(float)($s['rebalancing_cost']??0);
            $equity=max(1e-12,(float)($s['virtual_equity']??10000));$buyExposure=100*$qty*(float)$buy['ask']/$equity;
            $sellExposure=100*$qty*(float)$sell['bid']/$equity;
            $out[]=['strategy'=>$this->key(),'symbol'=>$symbol,'action'=>'BUY_'.$buyVenue.'_SELL_'.$sellVenue,'gross_profit'=>$gross,
                'costs'=>$costs,'net_profit'=>$gross-$costs,'capital_required'=>$qty*((float)$buy['ask']+(float)$sell['bid']),
                'capital_pct'=>100*$qty*((float)$buy['ask']+(float)$sell['bid'])/$equity,'exchange_exposure_pct'=>max($buyExposure,$sellExposure),
                'venue_exposures_pct'=>[$buyVenue=>$buyExposure,$sellVenue=>$sellExposure],
                'data_quality'=>!empty($buy['observed_at'])&&!empty($sell['observed_at']),'details'=>['quantity'=>$qty,
                    'liquidity_available_usd'=>min((float)$buy['ask_qty']*(float)$buy['ask'],(float)$sell['bid_qty']*(float)$sell['bid']),
                    'inventory_mode'=>'PREPOSITIONED','bid'=>$sell['bid'],'ask'=>$buy['ask']]];
        } return $out;
    }
}
