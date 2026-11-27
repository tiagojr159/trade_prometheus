<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Long-only equal-risk-contribution weights from synchronized out-of-sample returns. */
final class StrategyAllocationEngine
{
    public function recommend(array $dailyReturns,array $config=[]): array
    {
        if(($config['classification']??null)!=='BACKTEST'||($config['partition']??null)!=='TEST')
            return ['status'=>'REJECTED_NON_OOS_INPUT','weights'=>[],'classification'=>'VIRTUAL_RECOMMENDATION'];
        $series=[];$dates=null;
        foreach($dailyReturns as $key=>$rows){
            if(!isset(StrategyEngine::registry()[$key])||!is_array($rows))continue;
            foreach($rows as $date=>$value)if(!is_string($date)||!is_numeric($value)||!is_finite((float)$value))unset($rows[$date]);
            if(count($rows)<2)continue;$series[$key]=$rows;$dates=$dates===null?array_keys($rows):array_values(array_intersect($dates,array_keys($rows)));
        }
        $dates=array_values(array_unique($dates??[]));sort($dates,SORT_STRING);$min=(int)($config['minimum_observations']??20);
        if(count($series)<2||count($dates)<$min)return ['status'=>'INSUFFICIENT_SYNCHRONIZED_OOS_HISTORY','weights'=>[],
            'strategies'=>count($series),'observations'=>count($dates),'minimum_observations'=>$min,'classification'=>'VIRTUAL_RECOMMENDATION'];
        $windowKeys=$config['evaluation_window_keys']??[];$distinctWindows=[];
        foreach(array_keys($series) as $key){if(!isset($windowKeys[$key])||!is_string($windowKeys[$key])||$windowKeys[$key]==='')
                return ['status'=>'MISSING_EVALUATION_WINDOW_METADATA','weights'=>[],'classification'=>'VIRTUAL_RECOMMENDATION'];
            $distinctWindows[$windowKeys[$key]]=true;}
        if(count($distinctWindows)!==1)return ['status'=>'UNSYNCHRONIZED_EVALUATION_WINDOWS','weights'=>[],
            'classification'=>'VIRTUAL_RECOMMENDATION'];
        $keys=array_keys($series);$n=count($keys);$means=[];
        foreach($keys as $key){$x=[];foreach($dates as $date)$x[]=(float)$series[$key][$date];$means[$key]=array_sum($x)/count($x);$series[$key]=$x;}
        $cov=[];for($i=0;$i<$n;$i++)for($j=0;$j<$n;$j++){
            $sum=0.0;for($t=0;$t<count($dates);$t++)$sum+=($series[$keys[$i]][$t]-$means[$keys[$i]])*($series[$keys[$j]][$t]-$means[$keys[$j]]);
            $cov[$i][$j]=$sum/(count($dates)-1);
        }
        foreach($keys as $i=>$key)if($cov[$i][$i]<=1e-14)return ['status'=>'ZERO_VARIANCE_STRATEGY','weights'=>[],
            'strategy'=>$key,'observations'=>count($dates),'classification'=>'VIRTUAL_RECOMMENDATION'];
        $cap=max(1/$n,min(1.0,(float)($config['max_weight_pct']??25)/100));$w=array_fill(0,$n,1/$n);$converged=false;
        for($iteration=0;$iteration<1000;$iteration++){
            $marginal=[];$variance=0.0;
            for($i=0;$i<$n;$i++){$marginal[$i]=0.0;for($j=0;$j<$n;$j++)$marginal[$i]+=$cov[$i][$j]*$w[$j];$variance+=$w[$i]*$marginal[$i];}
            if($variance<=1e-14)break;$target=$variance/$n;$next=[];
            for($i=0;$i<$n;$i++){$rc=$w[$i]*$marginal[$i];if($rc<=1e-14)return ['status'=>'NO_POSITIVE_RISK_PARITY_SOLUTION','weights'=>[],
                    'observations'=>count($dates),'classification'=>'VIRTUAL_RECOMMENDATION'];$next[$i]=$w[$i]*sqrt($target/$rc);}
            $next=self::capAndNormalize($next,$cap);$change=0.0;foreach($w as $i=>$old)$change=max($change,abs($next[$i]-$old));$w=$next;
            if($change<1e-9){$converged=true;break;}
        }
        $marginal=[];$variance=0.0;for($i=0;$i<$n;$i++){$marginal[$i]=0.0;for($j=0;$j<$n;$j++)$marginal[$i]+=$cov[$i][$j]*$w[$j];$variance+=$w[$i]*$marginal[$i];}
        $weights=[];$riskContributions=[];foreach($keys as $i=>$key){$weights[$key]=round($w[$i]*100,6);
            $riskContributions[$key]=$variance>0?round($w[$i]*$marginal[$i]/$variance*100,6):null;}
        return ['status'=>$converged?'RECOMMENDATION_READY':'ITERATION_LIMIT','weights_pct'=>$weights,
            'risk_contribution_pct'=>$riskContributions,'observations'=>count($dates),'window_start'=>$dates[0],
            'window_end'=>end($dates),'max_weight_pct'=>$cap*100,'iterations'=>$iteration+1,
            'source'=>'SYNCHRONIZED_OUT_OF_SAMPLE_TEST_RETURNS','classification'=>'VIRTUAL_RECOMMENDATION',
            'not_a_live_allocation'=>true];
    }

    private static function capAndNormalize(array $weights,float $cap): array
    {
        $n=count($weights);$sum=array_sum($weights);if($sum<=0)return array_fill(0,$n,1/$n);
        foreach($weights as &$weight)$weight/=$sum;unset($weight);
        for($round=0;$round<$n+2;$round++){
            $excess=0.0;$free=[];foreach($weights as $i=>$weight){if($weight>$cap){$excess+=$weight-$cap;$weights[$i]=$cap;}else $free[]=$i;}
            if($excess<=1e-12||!$free)break;$freeSum=0.0;foreach($free as $i)$freeSum+=$weights[$i];
            foreach($free as $i)$weights[$i]+=$freeSum>0?$excess*$weights[$i]/$freeSum:$excess/count($free);
        }
        $sum=array_sum($weights);if($sum>0)foreach($weights as &$weight)$weight/=$sum;unset($weight);return $weights;
    }
}
