<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

final class MevArbitrage extends AbstractStrategy
{
    public function key(): string { return 'MEV_OBSERVATION'; }
    public function generateSignals(array $s): array
    {
        $routes=$s['dex_routes']??[];$simulator=new DexRouteSimulator();
        foreach(($s['dex_route_configs']??[]) as $config){
            if(!is_array($config))continue;$network=(string)($config['network']??'');$buyAddress=strtolower((string)($config['buy_pool_address']??''));$sellAddress=strtolower((string)($config['sell_pool_address']??''));
            $buy=$sell=null;foreach(($s['dex_pools']??[]) as $pool){if(!is_array($pool)||strtolower((string)($pool['network']??''))!==strtolower($network))continue;
                $address=strtolower((string)($pool['pool_address']??''));if($address===$buyAddress)$buy=$pool;if($address===$sellAddress)$sell=$pool;}
            if(!$buy||!$sell)continue;$gas=null;foreach(($s['chain_gas']??[]) as $chain=>$observed)if(strtolower((string)($observed['chain']??$chain))===strtolower($network)){$gas=$observed;break;}
            $simulated=$simulator->simulate($config+['as_of_utc'=>$s['snapshot_at']??$s['as_of_utc']??'now UTC'],$buy,$sell,$gas);
            if(($simulated['status']??'')==='SIMULATED')$routes[]=$simulated;
        }
        $hybrid=new DexCexRouteSimulator();
        foreach(($s['dex_cex_route_configs']??[]) as $config){
            if(!is_array($config))continue;$network=strtolower((string)($config['network']??''));$address=strtolower((string)($config['pool_address']??''));
            $pool=null;foreach(($s['dex_pools']??[]) as $candidate)if(is_array($candidate)&&strtolower((string)($candidate['network']??''))===$network
                &&strtolower((string)($candidate['pool_address']??''))===$address){$pool=$candidate;break;}
            $symbol=(string)($config['symbol']??'');$venue=(string)($config['cex_venue']??'');$book=$s['books'][$symbol][$venue]??null;$gas=$s['chain_gas'][$network]??$s['chain_gas'][$config['network']??'']??null;
            if(!is_array($pool)||!is_array($book))continue;
            $simulation=$hybrid->simulate($config+['as_of_utc'=>$s['snapshot_at']??$s['as_of_utc']??'now UTC'],$pool,$book,is_array($gas)?$gas:null);
            if(($simulation['status']??'')==='SIMULATED')$routes[]=$simulation;
        }
        $out=[];foreach($routes as $r){$gross=$this->number($r['gross_edge']??null);$gas=$this->number($r['gas_cost']??null);$impact=$this->number($r['price_impact_cost']??null);$poolFee=$this->number($r['pool_fee_cost']??0);$slip=$this->number($r['slippage_cost']??null);$cexFee=$this->number($r['cex_fee_cost']??0);$adverse=$this->number($r['adverse_selection_cost']??0);if($gross===null||$gas===null||$impact===null||$poolFee===null||$slip===null||$cexFee===null||$adverse===null)continue;
            $revertCost=max(0,(float)($r['revert_cost']??0));$p=$this->number($r['revert_probability']??null);
            $calibrated=$p!==null&&$p>=0&&$p<=1&&!empty($r['revert_probability_calibrated']);
            if($calibrated){$effectiveGross=$gross*(1-$p);$expectedRevertCost=$revertCost*$p;}
            else{$effectiveGross=$gross;$expectedRevertCost=$revertCost;}
            $costs=$gas+$impact+$poolFee+$slip+$cexFee+$adverse+$expectedRevertCost;$net=$effectiveGross-$costs;
            $out[]=['strategy'=>$this->key(),'symbol'=>$r['route']??'DEX_ROUTE','action'=>'OBSERVE_AND_BACKTEST_ONLY','gross_profit'=>$effectiveGross,'costs'=>$costs,'net_profit'=>$net,
                'capital_pct'=>0,'exchange_exposure_pct'=>0,'data_quality'=>!empty($r['observed_at']),'classification'=>'OBSERVATION_ONLY',
                'details'=>['pool_reserves'=>$r['pool_reserves']??null,'quoted_gross_edge'=>$gross,'gas_cost'=>$gas,'price_impact'=>$impact,'pool_fee_cost'=>$poolFee,'cex_fee_cost'=>$cexFee,'adverse_selection_cost'=>$adverse,'slippage'=>$slip,
                    'liquidity_available_usd'=>$r['liquidity_available_usd']??null,
                    'revert_probability'=>$calibrated?$p:null,'revert_probability_status'=>$calibrated?'CALIBRATED':'UNVALIDATED',
                    'revert_cost_treatment'=>$calibrated?'PROBABILITY_WEIGHTED':'FULL_COST_RESERVED',
                    'simulation_model'=>$r['model']??null,'simulation_reconciliation'=>$r['cost_reconciliation']??null,
                    'live_execution'=>false,'predatory_strategies'=>false]];
        }return $out;
    }
}
