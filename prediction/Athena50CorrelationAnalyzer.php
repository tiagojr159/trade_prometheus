<?php
declare(strict_types=1);

namespace Prometheus\prediction;

/** Descriptive correlation diagnostics; reports redundancy but never drops a feature. */
final class Athena50CorrelationAnalyzer
{
    public static function analyzeSnapshots(array $snapshots, float $threshold = 0.95, int $minimumPairN = 30): array
    {
        $series=[];$constant=[];$featureN=[];$names=[];
        foreach($snapshots as $snapshot)foreach(array_keys($snapshot) as $name)$names[$name]=true;
        $names=array_keys($names);
        foreach($snapshots as $rowIndex=>$snapshot){
            foreach($names as $name){
                $feature=$snapshot[$name]??null;
                if(is_array($feature)&&($feature['status']??'')==='AVAILABLE'&&is_numeric($feature['normalized_value']??null))$series[$name][$rowIndex]=(float)$feature['normalized_value'];
            }
        }
        foreach($series as $name=>$values){
            $featureN[$name]=count($values);
            if(count($values)>=2&&max($values)-min($values)<=1e-12)$constant[]=$name;
        }
        $names=array_keys($series);$correlated=[];
        for($i=0;$i<count($names);$i++)for($j=$i+1;$j<count($names);$j++){
            $a=$series[$names[$i]]??[];$b=$series[$names[$j]]??[];$indexes=array_intersect_key($a,$b);$n=count($indexes);
            if($n<$minimumPairN)continue;
            $aValues=[];$bValues=[];foreach($indexes as $rowIndex=>$value){$aValues[]=$value;$bValues[]=$b[$rowIndex];}$corr=self::pearson($aValues,$bValues);
            if($corr!==null&&abs($corr)>=$threshold)$correlated[]=['feature_a'=>$names[$i],'feature_b'=>$names[$j],'correlation'=>$corr,'n'=>$n];
        }
        return ['snapshot_n'=>count($snapshots),'feature_n'=>$featureN,'constant_features'=>$constant,'high_correlation_pairs'=>$correlated,'threshold'=>$threshold,'minimum_pair_n'=>$minimumPairN];
    }

    private static function pearson(array $a,array $b): ?float
    {
        $n=count($a);if($n<2||$n!==count($b))return null;
        $ma=array_sum($a)/$n;$mb=array_sum($b)/$n;$cov=$va=$vb=0.0;
        foreach($a as $i=>$x){$dx=$x-$ma;$dy=$b[$i]-$mb;$cov+=$dx*$dy;$va+=$dx*$dx;$vb+=$dy*$dy;}
        if($va<=1e-18||$vb<=1e-18)return null;
        return max(-1.0,min(1.0,$cov/sqrt($va*$vb)));
    }
}
