<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

final class TriangularArbitrage extends AbstractStrategy
{
    public function key(): string { return 'TRIANGULAR'; }

    public function generateSignals(array $s): array
    {
        $graph=[];
        foreach (($s['triangular_edges']??[]) as $e) {
            $rate=$this->number($e['executable_rate']??null);
            $fee=$this->number($e['fee_rate']??null);
            $venue=(string)($e['venue']??'');
            if (!$rate || $rate<=0 || $fee===null || $fee<0 || $fee>=1 || empty($e['from']) || empty($e['to'])||$venue==='') continue;
            $graph[$venue][$e['from']][]=['from'=>$e['from'],'to'=>$e['to'],'rate'=>$rate*(1-$fee),'raw_rate'=>$rate,
                'depth'=>(float)($e['depth_qty']??$e['depth']??0),'min_quantity'=>(float)($e['min_quantity']??0),
                'quantity_step'=>(float)($e['quantity_step']??0),'market'=>$e['market']??'',
                'observed_at'=>$e['observed_at']??null];
        }
        $out=[];$seenCycles=[];
        foreach ($graph as $venue=>$venueGraph) foreach ($venueGraph as $a=>$ab) {
            foreach ($ab as $edge1) {
                $b=$edge1['to'];
                foreach ($venueGraph[$b]??[] as $edge2) {
                    $c=$edge2['to'];
                    if ($c===$a || $c===$b) continue;
                    foreach ($venueGraph[$c]??[] as $edge3) {
                        if ($edge3['to']!==$a) continue;
                        $rotations=["$a>$b>$c","$b>$c>$a","$c>$a>$b"];$canonical=min($rotations);$cycleKey=$venue.'|'.$canonical;
                        if(isset($seenCycles[$cycleKey]))continue;
                        $capital=(float)($s['capital']??1000);if($capital<=0)continue;
                        $amount=$capital;$grossAmount=$capital;$valid=true;$edgeInputs=[];
                        foreach([$edge1,$edge2,$edge3] as $edge){
                            $quantity=$amount;$step=(float)$edge['quantity_step'];
                            if($step>0)$quantity=floor(($quantity+1e-12)/$step)*$step;
                            if($quantity<(float)$edge['min_quantity']||$quantity>(float)$edge['depth']||$quantity<=0){$valid=false;break;}
                            $edgeInputs[]=$quantity;$amount=$quantity*(float)$edge['rate'];$grossAmount*=(float)$edge['raw_rate'];
                        }
                        if(!$valid)continue;$seenCycles[$cycleKey]=true;
                        $product=$amount/$capital;$rawProduct=$grossAmount/$capital;$gross=$grossAmount-$capital;
                        $profit=$amount-$capital;$costs=$gross-$profit;
                        $equity=max(1e-12,(float)($s['virtual_equity']??10000));$exposurePct=100*$capital/$equity;
                        $allObserved=!empty($s['snapshot_at'])&&!empty($edge1['observed_at'])&&!empty($edge2['observed_at'])&&!empty($edge3['observed_at']);
                        $liquidityUsd=null;$prices=$s['asset_usd_prices']??[];$edgeLiquidity=[];
                        foreach([[$edge1,$edgeInputs[0]],[$edge2,$edgeInputs[1]],[$edge3,$edgeInputs[2]]] as [$edge,$edgeInput])
                            if(is_numeric($prices[$edge['from']]??null)&&(float)$prices[$edge['from']]>0)$edgeLiquidity[]=$edgeInput*(float)$prices[$edge['from']];
                        if(count($edgeLiquidity)===3)$liquidityUsd=min($edgeLiquidity);
                        $out[]=['strategy'=>$this->key(),'symbol'=>"$a->$b->$c->$a",'action'=>'SIMULATE_CYCLE',
                            'gross_profit'=>$gross,'costs'=>$costs,'net_profit'=>$profit,'capital_required'=>$capital,
                            'capital_pct'=>$exposurePct,'exchange_exposure_pct'=>$exposurePct,'venue_exposures_pct'=>[$venue=>$exposurePct],
                            'data_quality'=>$allObserved,
                            'details'=>['venue'=>$venue,'product'=>$product,'raw_product'=>$rawProduct,
                                'liquidity_available_usd'=>$liquidityUsd,
                                'input_quantity_by_leg'=>$edgeInputs,'minimum_input_depth_checked'=>true,'quantity_steps_applied'=>true,
                                'edge_markets'=>[$edge1['market'],$edge2['market'],$edge3['market']],'execution_validation'=>'SIZE_AND_DEPTH_CHECK_ONLY']];
                    }
                }
            }
        }
        return $out;
    }
}
