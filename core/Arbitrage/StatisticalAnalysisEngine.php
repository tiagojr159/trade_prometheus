<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Deterministic research statistics. No fitted success probability is emitted. */
final class StatisticalAnalysisEngine
{
    public function analyzePair(array $logA,array $logB,array $options=[]): array
    {
        $n=min(count($logA),count($logB));
        if($n<30)throw new \InvalidArgumentException('PAIR_HISTORY_TOO_SHORT');
        $a=array_slice(array_map('floatval',$logA),-$n);$b=array_slice(array_map('floatval',$logB),-$n);
        [$alpha,$beta]=$this->ols($b,$a);$spread=[];
        for($i=0;$i<$n;$i++)$spread[]=$a[$i]-($alpha+$beta*$b[$i]);
        $mean=array_sum($spread)/$n;$variance=0.0;
        foreach($spread as $v)$variance+=($v-$mean)**2;
        $sd=sqrt($variance/$n);$z=$sd>0?(end($spread)-$mean)/$sd:null;
        $adf=$this->augmentedAdf($spread,$options);$level=(int)($options['adf_significance_level_pct']??5);
        if(!in_array($level,[1,5,10],true))throw new \InvalidArgumentException('INVALID_ADF_SIGNIFICANCE_LEVEL');
        $critical=array_key_exists('adf_critical_value',$options)?(float)$options['adf_critical_value']
            :(($adf['observations']??0)>=10?$this->engleGrangerCritical($adf['observations'],$level):null);
        $kalman=$this->kalmanBeta($b,$a,(float)$beta,(float)($options['kalman_process_variance']??1e-6),(float)($options['kalman_observation_variance']??1e-3));
        $hl=$this->halfLife($spread);$break=$this->structuralBreakDetails($spread,(int)($options['break_min_segment']??20),
            (float)($options['break_threshold']??3.5));
        return ['observations'=>$n,'alpha'=>$alpha,'hedge_ratio_ols'=>$beta,'spread'=>$spread,'spread_mean'=>$mean,
            'spread_std'=>$sd,'zscore'=>$z,'adf_tau'=>$adf['tau'],'adf0_tau'=>$adf['tau'],'adf_lag'=>$adf['lag'],
            'adf_lag_criterion'=>$adf['criterion'],'adf_regression_observations'=>$adf['observations'],
            'adf_critical_value'=>$critical,'adf_significance_level_pct'=>$level,
            'cointegration_confirmed'=>$adf['tau']!==null&&$critical!==null&&$adf['tau']<$critical,
            'adf_method'=>'ENGLE_GRANGER_ADF_SELECTED_LAG_MACKINNON_APPROX',
            'adf_critical_method'=>array_key_exists('adf_critical_value',$options)?'USER_SUPPLIED_CRITICAL_VALUE'
                :'MACKINNON_2010_FINITE_SAMPLE_EG_NO_TREND_N2_RESPONSE_SURFACE',
            'adf_p_value'=>$adf['p_value'],'adf_p_value_method'=>'MACKINNON_1994_ASYMPTOTIC_RESPONSE_SURFACE_N2',
            'kalman_beta'=>$kalman?end($kalman):null,'kalman_beta_series'=>$kalman,
            'half_life_bars'=>$hl,'structural_break'=>$break['detected'],'structural_break_details'=>$break,
            'success_probability'=>null];
    }

    private function ols(array $x,array $y): array
    {
        $n=count($x);$mx=array_sum($x)/$n;$my=array_sum($y)/$n;$cov=0.0;$var=0.0;
        for($i=0;$i<$n;$i++){$cov+=($x[$i]-$mx)*($y[$i]-$my);$var+=($x[$i]-$mx)**2;}
        if($var<=1e-18)throw new \InvalidArgumentException('PAIR_VARIANCE_TOO_LOW');
        $beta=$cov/$var;return [$my-$beta*$mx,$beta];
    }

    private function augmentedAdf(array $values,array $options): array
    {
        $n=count($values);$differences=[];for($i=1;$i<$n;$i++)$differences[]=$values[$i]-$values[$i-1];
        $default=(int)floor(12*($n/100)**0.25);$maxAllowed=max(0,min((int)floor(($n-4)/2),$n-19));
        $maxLag=(int)($options['adf_max_lag']??min($default,$maxAllowed));
        if($maxLag<0||$maxLag>$maxAllowed)throw new \InvalidArgumentException('INVALID_ADF_MAX_LAG');
        $criterion=strtolower((string)($options['adf_lag_criterion']??'bic'));
        if(!in_array($criterion,['aic','bic'],true))throw new \InvalidArgumentException('INVALID_ADF_LAG_CRITERION');
        $best=null;
        for($lag=0;$lag<=$maxLag;$lag++){
            $fit=$this->adfRegression($values,$differences,$lag,$maxLag);
            if($fit===null)continue;$k=$lag+1;$penalty=$criterion==='bic'?log($fit['observations']):2.0;
            $ic=$fit['observations']*log(max($fit['sse']/$fit['observations'],1e-300))+$k*$penalty;
            if($best===null||$ic<$best['ic'])$best=['lag'=>$lag,'ic'=>$ic];
        }
        if($best===null)return ['tau'=>null,'lag'=>0,'criterion'=>strtoupper($criterion),'observations'=>0,'p_value'=>null];
        $fit=$this->adfRegression($values,$differences,$best['lag'],$best['lag']);
        if($fit===null||$fit['se']<=0)return ['tau'=>null,'lag'=>$best['lag'],'criterion'=>strtoupper($criterion),
            'observations'=>$fit['observations']??0,'p_value'=>null];
        return ['tau'=>$fit['beta'][0]/$fit['se'],'lag'=>$best['lag'],'criterion'=>strtoupper($criterion),
            'observations'=>$fit['observations'],'p_value'=>$this->mackinnonEgPValue($fit['beta'][0]/$fit['se'])];
    }

    /** Fits Δe[t] on e[t-1] and lagged Δe terms without a second-stage intercept. */
    private function adfRegression(array $values,array $differences,int $lag,int $holdback): ?array
    {
        $rows=[];$dependent=[];$m=count($differences);
        for($i=$holdback;$i<$m;$i++){$row=[$values[$i]];for($j=1;$j<=$lag;$j++)$row[]=$differences[$i-$j];
            $rows[]=$row;$dependent[]=$differences[$i];}
        $n=count($rows);$k=$lag+1;if($n<=$k+1)return null;$xtx=array_fill(0,$k,array_fill(0,$k,0.0));$xty=array_fill(0,$k,0.0);
        foreach($rows as $i=>$row)for($a=0;$a<$k;$a++){$xty[$a]+=$row[$a]*$dependent[$i];
            for($b=0;$b<$k;$b++)$xtx[$a][$b]+=$row[$a]*$row[$b];}
        $beta=$this->solve($xtx,$xty);$inverseColumn=$this->solve($xtx,array_merge([1.0],array_fill(0,$k-1,0.0)));
        if($beta===null||$inverseColumn===null)return null;$sse=0.0;
        foreach($rows as $i=>$row){$pred=0.0;foreach($row as $j=>$value)$pred+=$value*$beta[$j];$sse+=($dependent[$i]-$pred)**2;}
        $se=sqrt(max(0,$sse/max(1,$n-$k))*max(0,$inverseColumn[0]));
        return ['beta'=>$beta,'sse'=>$sse,'se'=>$se,'observations'=>$n];
    }

    private function solve(array $matrix,array $vector): ?array
    {
        $n=count($vector);for($col=0;$col<$n;$col++){$pivot=$col;
            for($row=$col+1;$row<$n;$row++)if(abs($matrix[$row][$col])>abs($matrix[$pivot][$col]))$pivot=$row;
            if(abs($matrix[$pivot][$col])<1e-18)return null;
            if($pivot!==$col){[$matrix[$pivot],$matrix[$col]]=[$matrix[$col],$matrix[$pivot]];[$vector[$pivot],$vector[$col]]=[$vector[$col],$vector[$pivot]];}
            $scale=$matrix[$col][$col];for($j=$col;$j<$n;$j++)$matrix[$col][$j]/=$scale;$vector[$col]/=$scale;
            for($row=0;$row<$n;$row++)if($row!==$col){$factor=$matrix[$row][$col];if($factor==0.0)continue;
                for($j=$col;$j<$n;$j++)$matrix[$row][$j]-=$factor*$matrix[$col][$j];$vector[$row]-=$factor*$vector[$col];}}
        return $vector;
    }

    /** MacKinnon residual EG distribution: N=2, constant in first-stage regression. */
    private function mackinnonEgPValue(float $tau): float
    {
        if($tau<=-18.86)return 0.0;if($tau>=0.92)return 1.0;
        if($tau<=-2.62)$z=2.92+1.5012*$tau+0.039796*$tau*$tau;
        else $z=2.1945+0.64695*$tau-0.29198*$tau*$tau-0.042377*$tau*$tau*$tau;
        return max(0.0,min(1.0,$this->normalCdf($z)));
    }

    private function normalCdf(float $x): float
    {
        $a=abs($x);$t=1/(1+0.2316419*$a);$density=0.3989422804014327*exp(-0.5*$a*$a);
        $tail=$density*$t*(0.319381530+$t*(-0.356563782+$t*(1.781477937+$t*(-1.821255978+$t*1.330274429))));
        return $x>=0?1-$tail:$tail;
    }

    /** MacKinnon 2010 finite-sample response surfaces: EG no-trend case, N=2. */
    private function engleGrangerCritical(int $observations,int $level): float
    {
        $coefficients=[1=>[-3.89644,-10.9519,-33.527],5=>[-3.33613,-6.1101,-6.823],10=>[-3.04445,-4.2412,-2.720]];
        if($observations<18)throw new \InvalidArgumentException('ADF_REGRESSION_TOO_SHORT');
        [$b0,$b1,$b2]=$coefficients[$level];$t=(float)$observations;return $b0+$b1/$t+$b2/($t*$t);
    }

    private function kalmanBeta(array $x,array $y,float $beta,float $q,float $r): array
    {
        $variance=1.0;$out=[];
        foreach($x as $i=>$xi){$variance+=$q;$den=$xi*$xi*$variance+$r;
            if($den>1e-18){$gain=$variance*$xi/$den;$beta+=$gain*($y[$i]-$beta*$xi);$variance=(1-$gain*$xi)*$variance;}
            $out[]=$beta;
        }
        return $out;
    }

    private function halfLife(array $spread): ?float
    {
        $x=[];$y=[];for($i=1;$i<count($spread);$i++){$x[]=$spread[$i-1];$y[]=$spread[$i]-$spread[$i-1];}
        if(count($x)<10)return null;[$intercept,$lambda]=$this->ols($x,$y);
        if($lambda>=0||$lambda<=-1)return null;return -log(2)/log(1+$lambda);
    }

    /** Exploratory maximum standardized mean-shift scan; threshold does not correct for multiple testing. */
    public function structuralBreakDetails(array $values,int $minSegment=20,float $threshold=3.5): array
    {
        $values=array_map('floatval',$values);$n=count($values);$minSegment=max(10,$minSegment);
        if(!is_finite($threshold)||$threshold<=0)throw new \InvalidArgumentException('INVALID_BREAK_THRESHOLD');
        if($n<2*$minSegment)return ['detected'=>false,'status'=>'INSUFFICIENT_HISTORY','score'=>null,'split_index'=>null,
            'method'=>'MAX_STANDARDIZED_MEAN_SHIFT','threshold'=>$threshold,'multiple_testing_corrected'=>false];
        $best=-INF;$bestSplit=null;
        for($split=$minSegment;$split<=$n-$minSegment;$split++){$left=array_slice($values,0,$split);$right=array_slice($values,$split);
            $meanL=array_sum($left)/count($left);$meanR=array_sum($right)/count($right);$ss=0.0;
            foreach($left as $v)$ss+=($v-$meanL)**2;foreach($right as $v)$ss+=($v-$meanR)**2;
            $pooled=$ss/max(1,$n-2);$se=sqrt(max(0,$pooled)*(1/count($left)+1/count($right)));
            $score=$se>1e-15?abs($meanL-$meanR)/$se:(abs($meanL-$meanR)>1e-15?INF:0.0);
            if($score>$best){$best=$score;$bestSplit=$split;}}
        return ['detected'=>$best>=$threshold,'status'=>'EXPLORATORY_UNADJUSTED_SCAN','score'=>is_finite($best)?$best:null,
            'split_index'=>$bestSplit,'method'=>'MAX_STANDARDIZED_MEAN_SHIFT','threshold'=>$threshold,'multiple_testing_corrected'=>false];
    }
}
