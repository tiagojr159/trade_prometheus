<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

final class StatisticalArbitrage extends AbstractStrategy
{
    public function key(): string { return 'STATISTICAL'; }
    public function generateSignals(array $s): array
    {
        $out=[]; $engine=new StatisticalAnalysisEngine(); foreach (($s['pairs']??[]) as $p) {
            $a=array_values(array_filter($p['log_a']??[],'is_numeric')); $b=array_values(array_filter($p['log_b']??[],'is_numeric'));
            try{$stats=$engine->analyzePair($a,$b,$p['parameters']??[]);}catch(\InvalidArgumentException $e){continue;}
            if(!$stats['cointegration_confirmed']||$stats['structural_break'])continue;
            $z=$stats['zscore'];if($z===null||abs($z)<2||abs($z)>3.5)continue;
            $dynamicBeta=$stats['kalman_beta']!==null&&is_finite((float)$stats['kalman_beta'])?(float)$stats['kalman_beta']:(float)$stats['hedge_ratio_ols'];
            $out[]=['strategy'=>$this->key(),'symbol'=>($p['symbol_a']??'').'/'.($p['symbol_b']??''),'action'=>$z>0?'SHORT_SPREAD':'LONG_SPREAD',
                'gross_profit'=>(float)($s['notional']??1000)*abs($z)*0.0005,'costs'=>(float)($p['estimated_costs']??0),
                'net_profit'=>(float)($s['notional']??1000)*abs($z)*0.0005-(float)($p['estimated_costs']??0),
                'capital_pct'=>(float)($s['capital_pct']??5),'exchange_exposure_pct'=>0,'data_quality'=>!empty($p['observed_at']),
                'details'=>['zscore'=>$z,'hedge_ratio'=>$dynamicBeta,'hedge_ratio_ols'=>$stats['hedge_ratio_ols'],'alpha'=>$stats['alpha'],
                    'liquidity_available_usd'=>is_numeric($p['liquidity_available_usd']??null)?(float)$p['liquidity_available_usd']:null,
                    'cointegration_confirmed'=>$stats['cointegration_confirmed'],'adf0_tau'=>$stats['adf0_tau'],
                    'adf_tau'=>$stats['adf_tau'],'adf_lag'=>$stats['adf_lag'],'adf_lag_criterion'=>$stats['adf_lag_criterion'],
                    'adf_critical_value'=>$stats['adf_critical_value'],'adf_p_value'=>$stats['adf_p_value'],
                    'adf_p_value_method'=>$stats['adf_p_value_method'],'adf_method'=>$stats['adf_method'],
                    'half_life_bars'=>$stats['half_life_bars'],
                    'kalman_beta'=>$stats['kalman_beta'],'structural_break'=>$stats['structural_break'],
                    'probability'=>null,'probability_status'=>'NOT_CALIBRATED']];
        } return $out;
    }
}
