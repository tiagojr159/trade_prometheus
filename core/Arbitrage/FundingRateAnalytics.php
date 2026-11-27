<?php
declare(strict_types=1);

namespace Prometheus\core\Arbitrage;

use PDO;

final class FundingRateAnalytics
{
    public static function summarize(PDO $db, string $symbol, string $exchange, string $asOfUtc): array
    {
        $stmt=$db->prepare('SELECT funding_rate,interval_hours,observed_at_utc FROM arbitrage_funding_rate_history
            WHERE symbol=? AND exchange_name=? AND observed_at_utc<=? AND observed_at_utc>=DATE_SUB(?,INTERVAL 90 DAY)
            ORDER BY observed_at_utc ASC');
        $stmt->execute([$symbol,$exchange,$asOfUtc,$asOfUtc]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $result=['7d'=>self::window($rows,7,$asOfUtc),'30d'=>self::window($rows,30,$asOfUtc),
            '90d'=>self::window($rows,90,$asOfUtc)]+self::reversalEstimate($rows);
        return $result;
    }

    /** Empirical sign-reversal frequency on non-overlapping 24-hour anchors; not an OOS-calibrated forecast. */
    public static function reversalEstimate(array $rows,int $horizonObservations=24,int $minimumTrainingSamples=30,float $maximumCalibrationError=0.20): array
    {
        if($horizonObservations<1||$minimumTrainingSamples<1||!is_finite($maximumCalibrationError)||$maximumCalibrationError<0||$maximumCalibrationError>1)
            throw new \InvalidArgumentException('INVALID_REVERSAL_ESTIMATE_CONFIG');
        $byHour=[];foreach($rows as $row){$ts=strtotime((string)($row['observed_at_utc']??'').' UTC');$hours=(float)($row['interval_hours']??0);
            $rate=(float)($row['funding_rate']??NAN);if($ts===false||$hours<=0||!is_finite($rate))continue;
            $bucket=(int)floor($ts/3600);$byHour[$bucket]=$rate*24/$hours;}
        if(!$byHour)return ['reversal_probability'=>null,'reversal_probability_status'=>'INSUFFICIENT_HISTORY',
            'reversal_observations'=>0,'reversal_events'=>null,'reversal_horizon_hours'=>$horizonObservations];
        ksort($byHour);$buckets=array_keys($byHour);$rates=array_values($byHour);$outcomes=[];$step=$horizonObservations;
        for($i=0;$i+$horizonObservations<count($rates);$i+=$step){$continuous=true;
            for($j=1;$j<=$horizonObservations;$j++)if($buckets[$i+$j]!==$buckets[$i]+$j){$continuous=false;break;}
            if(!$continuous)continue;$initial=$rates[$i];if(abs($initial)<1e-15)continue;$reversed=false;
            $sign=$initial>0?1:-1;for($j=1;$j<=$horizonObservations;$j++)if($rates[$i+$j]*$sign<0){$reversed=true;break;}$outcomes[]=$reversed?1:0;}
        $total=count($outcomes);$trainN=(int)floor($total*0.60);$validationN=(int)floor($total*0.20);$testN=$total-$trainN-$validationN;
        if($trainN<$minimumTrainingSamples||$validationN<10||$testN<10)return ['reversal_probability'=>null,
            'reversal_probability_status'=>'INSUFFICIENT_HISTORY','reversal_observations'=>$total,'reversal_events'=>array_sum($outcomes),
            'reversal_horizon_hours'=>$horizonObservations,'train_observations'=>$trainN,'validation_observations'=>$validationN,
            'test_observations'=>$testN];
        $training=array_slice($outcomes,0,$trainN);$validation=array_slice($outcomes,$trainN,$validationN);$test=array_slice($outcomes,$trainN+$validationN);
        $trainingRate=(array_sum($training)+1)/($trainN+2);$validationRate=array_sum($validation)/$validationN;
        $testRate=array_sum($test)/$testN;$validationError=abs($validationRate-$trainingRate);$testError=abs($testRate-$trainingRate);
        $fitN=$trainN+$validationN;$fitEvents=array_sum($training)+array_sum($validation);$p=($fitEvents+1)/($fitN+2);
        $validated=$validationError<=$maximumCalibrationError&&$testError<=$maximumCalibrationError;
        $z=1.959963984540054;$den=1+$z*$z/($fitN+2);$center=($p+$z*$z/(2*($fitN+2)))/$den;
        $half=$z*sqrt(($p*(1-$p)+$z*$z/(4*($fitN+2)))/($fitN+2))/$den;
        $testBrier=0.0;foreach($test as $outcome)$testBrier+=($outcome-$trainingRate)**2;$testBrier/=$testN;
        return ['reversal_probability'=>$validated?$p:null,'historical_reversal_frequency'=>$total?array_sum($outcomes)/$total:null,
            'reversal_probability_status'=>$validated?'OOS_VALIDATED_EMPIRICAL':'EMPIRICAL_VALIDATION_FAILED',
            'reversal_confidence_95_pct'=>$validated?['lower'=>max(0,$center-$half),'upper'=>min(1,$center+$half)]:null,
            'reversal_observations'=>$total,'reversal_events'=>array_sum($outcomes),'train_observations'=>$trainN,
            'validation_observations'=>$validationN,'test_observations'=>$testN,'validation_reversal_rate'=>$validationRate,
            'test_reversal_rate'=>$testRate,'validation_absolute_error'=>$validationError,'test_absolute_error'=>$testError,
            'test_brier_score'=>$testBrier,'maximum_calibration_error'=>$maximumCalibrationError,'reversal_horizon_hours'=>$horizonObservations,
            'reversal_method'=>'NON_OVERLAPPING_24H_RATE_FIT_WITH_CHRONOLOGICAL_VALIDATION_AND_TEST'];
    }

    private static function window(array $rows, int $days, string $asOfUtc): array
    {
        $cutoff=strtotime($asOfUtc.' UTC')-$days*86400;$rates=[];
        foreach($rows as $row){$ts=strtotime($row['observed_at_utc'].' UTC');$hours=(float)$row['interval_hours'];
            if($ts===false||$ts<$cutoff||$hours<=0)continue;
            $rates[]=(float)$row['funding_rate']*24/$hours;
        }
        if(!$rates)return ['observations'=>0,'mean_daily_rate'=>null,'persistence_fraction'=>null,'classification'=>'INSUFFICIENT_HISTORY'];
        $nonNegative=count(array_filter($rates,static fn(float $v):bool=>$v>=0));
        return ['observations'=>count($rates),'mean_daily_rate'=>array_sum($rates)/count($rates),
            'persistence_fraction'=>max($nonNegative,count($rates)-$nonNegative)/count($rates),
            'classification'=>count($rates)>=10?'OBSERVED':'LIMITED_SAMPLE'];
    }
}
