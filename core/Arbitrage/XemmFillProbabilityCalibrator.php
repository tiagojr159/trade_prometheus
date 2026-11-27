<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Empirical XEMM fill-rate estimate with chronological holdout validation and no future-label access. */
final class XemmFillProbabilityCalibrator
{
    public function estimate(array $training,array $validation,array $quote,string $asOfUtc,array $config=[]): array
    {
        $asOf=self::timestamp($asOfUtc);$side=strtoupper((string)($quote['maker_side']??''));
        $spread=self::number($quote['spread_bps']??null);$latency=self::number($quote['latency_ms']??null);$depth=self::number($quote['depth_ratio']??null);
        if($asOf===null||!in_array($side,['BUY','SELL'],true)||$spread===null||$latency===null||$depth===null||$spread<0||$latency<0||$depth<0)
            return ['status'=>'INVALID_QUOTE_FEATURES','probability'=>null,'calibrated'=>false];
        $spreadTol=max(0.0,(float)($config['spread_tolerance_bps']??2.0));
        $latencyTol=max(0.0,(float)($config['latency_tolerance_ms']??100.0));
        $depthTol=max(0.0,(float)($config['depth_ratio_tolerance']??0.5));
        $train=$this->matches($training,$side,$spread,$latency,$depth,$spreadTol,$latencyTol,$depthTol,$asOf);
        $valid=$this->matches($validation,$side,$spread,$latency,$depth,$spreadTol,$latencyTol,$depthTol,$asOf);
        if($train['future']||$valid['future'])return ['status'=>'FUTURE_LABELS_REJECTED','probability'=>null,'calibrated'=>false];
        if(!$train['rows']||!$valid['rows']||$train['max_at']===null||$valid['min_at']===null||$train['max_at']>=$valid['min_at'])
            return ['status'=>'INVALID_CHRONOLOGICAL_SPLIT','probability'=>null,'calibrated'=>false,
                'training_observations'=>count($train['rows']),'validation_observations'=>count($valid['rows'])];
        $minTrain=max(1,(int)($config['minimum_training_observations']??50));
        $minValidation=max(1,(int)($config['minimum_validation_observations']??30));
        if(count($train['rows'])<$minTrain||count($valid['rows'])<$minValidation)
            return ['status'=>'INSUFFICIENT_HISTORY','probability'=>null,'calibrated'=>false,
                'training_observations'=>count($train['rows']),'validation_observations'=>count($valid['rows']),
                'minimum_training_observations'=>$minTrain,'minimum_validation_observations'=>$minValidation];
        $p=$train['fills']/count($train['rows']);$validationRate=$valid['fills']/count($valid['rows']);
        $error=abs($validationRate-$p);$maxError=(float)($config['maximum_calibration_error']??0.10);
        if(!is_finite($maxError)||$maxError<0||$maxError>1)throw new \InvalidArgumentException('INVALID_CALIBRATION_ERROR_TOLERANCE');
        $z=1.959963984540054;$n=count($train['rows']);$den=1+$z*$z/$n;$center=($p+$z*$z/(2*$n))/$den;
        $half=$z*sqrt(($p*(1-$p)+$z*$z/(4*$n))/$n)/$den;
        $brier=0.0;foreach($valid['rows'] as $row)$brier+=((int)$row['filled']-$p)**2;$brier/=count($valid['rows']);
        $calibrated=$error<=$maxError;
        return ['status'=>$calibrated?'CALIBRATED':'VALIDATION_ERROR_TOO_HIGH','calibrated'=>$calibrated,
            'probability'=>$p,'training_fill_rate'=>$p,'validation_fill_rate'=>$validationRate,
            'validation_absolute_error'=>$error,'maximum_calibration_error'=>$maxError,'validation_brier_score'=>$brier,
            'confidence_95_pct'=>['lower'=>max(0,$center-$half),'upper'=>min(1,$center+$half)],
            'training_observations'=>$n,'training_fills'=>$train['fills'],'validation_observations'=>count($valid['rows']),
            'validation_fills'=>$valid['fills'],'as_of_utc'=>$asOfUtc,'method'=>'EMPIRICAL_FEATURE_MATCHED_RATE_CHRONOLOGICAL_HOLDOUT',
            'features'=>['maker_side'=>$side,'spread_bps'=>$spread,'latency_ms'=>$latency,'depth_ratio'=>$depth],
            'tolerances'=>['spread_bps'=>$spreadTol,'latency_ms'=>$latencyTol,'depth_ratio'=>$depthTol]];
    }

    private function matches(array $rows,string $side,float $spread,float $latency,float $depth,float $spreadTol,float $latencyTol,float $depthTol,int $asOf): array
    {
        $matched=[];$future=false;$times=[];$fills=0;
        foreach($rows as $row){if(!is_array($row))continue;$at=self::timestamp((string)($row['available_at_utc']??''));
            if($at===null)continue;if($at>=$asOf){$future=true;continue;}
            $rowSide=strtoupper((string)($row['maker_side']??''));$rowSpread=self::number($row['spread_bps']??null);
            $rowLatency=self::number($row['latency_ms']??null);$rowDepth=self::number($row['depth_ratio']??null);
            if($rowSide!==$side||$rowSpread===null||$rowLatency===null||$rowDepth===null
                ||abs($rowSpread-$spread)>$spreadTol||abs($rowLatency-$latency)>$latencyTol||abs($rowDepth-$depth)>$depthTol)continue;
            if(!array_key_exists('filled',$row)||!in_array($row['filled'],[0,1,false,true,'0','1'],true))continue;
            $filled=(int)$row['filled'];$matched[]=['filled'=>$filled];$fills+=$filled;$times[]=$at;
        }
        sort($times,SORT_NUMERIC);return ['rows'=>$matched,'fills'=>$fills,'future'=>$future,'min_at'=>$times?$times[0]:null,'max_at'=>$times?$times[count($times)-1]:null];
    }

    private static function number($value): ?float
    {return is_numeric($value)&&is_finite((float)$value)?(float)$value:null;}

    private static function timestamp(string $value): ?int
    {if(trim($value)==='')return null;try{return (new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->getTimestamp();}catch(\Throwable $e){return null;}}
}
