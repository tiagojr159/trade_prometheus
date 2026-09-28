<?php
declare(strict_types=1);

namespace Prometheus\prediction;

use Prometheus\core\Database;
use Prometheus\core\DirectionPolicy;
use Prometheus\modules\AthenaTechnical;

/** Experimental 50-feature shadow. It never contributes to the production ensemble. */
final class Athena50Shadow
{
    public function record(int $predictionId,string $symbol,string $horizon,string $regime,string $predictionCreatedAt,array $classicSignals): array
    {
        $map=AthenaTechnical::HORIZON_INTERVALS[$horizon]??['interval'=>'1m','limit'=>120];
        $asOfUnix=strtotime($predictionCreatedAt);$asOfUtc=gmdate('Y-m-d H:i:s',$asOfUnix===false?0:$asOfUnix);
        $rows=Database::fetchAll('SELECT id,open_time,close_time,open_price,high_price,low_price,close_price,volume,available_at,ingested_at FROM market_data WHERE symbol=? AND interval_name=? AND close_time<=? AND ingested_at IS NOT NULL AND ingested_at<=? AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND (available_at IS NULL OR (available_at<=? AND ingested_at>=available_at)) ORDER BY open_time DESC LIMIT '.(int)$map['limit'],[$symbol,$map['interval'],$predictionCreatedAt,$asOfUtc,$asOfUtc]);
        $rows=self::filterPointInTimeRows($rows,$predictionCreatedAt,$asOfUtc);
        $rows=array_reverse($rows);$classic=null;
        foreach($classicSignals as $signal)if(($signal['module']??'')==='ATHENA'){$classic=$signal;break;}
        $ablationPredictions=[];
        if(count($rows)<60){$features=$this->emptyFeatures('Menos de 60 candles fechados disponíveis no instante da previsão.');$groups=[];$signal=null;$confidence=null;$pUp=null;$status='INSUFFICIENT_DATA';}
        else{
            $features=$this->technicalFeatures($rows);
            $this->derivativeFeatures($features,$symbol,$predictionCreatedAt,$asOfUtc,$rows);
            [$signal,$confidence,$groups,$count]=$this->aggregate($features);
            $status=$count>=30&&count(array_filter($groups,static fn($g)=>$g['available']>0))>=3?($count===50?'READY':'READY_PARTIAL'):'INSUFFICIENT_DATA';
            $pUp=(new ProbabilityCalculator())->calculate((float)$signal,(float)$confidence)['up'];
            if($status==='INSUFFICIENT_DATA'){$signal=null;$confidence=null;$pUp=null;}else{$ablationPredictions=$this->groupAblations($features);}
        }
        $last=$rows?end($rows):null;$count=count(array_filter($features,static fn($f)=>$f['status']==='AVAILABLE'));
        $baseline=Database::fetch('SELECT probability_up,ensemble_signal,confidence,weights_json FROM predictions WHERE id=?',[$predictionId])?:[];
        $candidatePredictions=self::candidateProbabilities($classicSignals,(array)(json_decode((string)($baseline['weights_json']??''),true)?:[]),$baseline,$signal,$confidence,$pUp,$ablationPredictions);
        Database::execute('INSERT INTO athena50_shadow_predictions (prediction_id,symbol,horizon,regime,prediction_created_at,model_status,classic_signal,classic_confidence,shadow_signal,shadow_confidence,shadow_probability_up,feature_count,feature_total,feature_vector,group_summary,group_ablation_predictions,candidate_predictions,market_data_id,candle_timestamp) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE regime=VALUES(regime),model_status=VALUES(model_status),classic_signal=VALUES(classic_signal),classic_confidence=VALUES(classic_confidence),shadow_signal=VALUES(shadow_signal),shadow_confidence=VALUES(shadow_confidence),shadow_probability_up=VALUES(shadow_probability_up),feature_count=VALUES(feature_count),feature_vector=VALUES(feature_vector),group_summary=VALUES(group_summary),group_ablation_predictions=VALUES(group_ablation_predictions),candidate_predictions=VALUES(candidate_predictions),market_data_id=VALUES(market_data_id),candle_timestamp=VALUES(candle_timestamp)',[
            $predictionId,$symbol,$horizon,$regime,$predictionCreatedAt,$status,$classic['value']??null,$classic['confidence']??null,$signal,$confidence,$pUp,$count,50,json_encode($features,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),json_encode($groups,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),json_encode($ablationPredictions,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),json_encode($candidatePredictions,JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR),$last['id']??null,$last['close_time']??null
        ]);
        return ['status'=>$status,'feature_count'=>$count,'signal'=>$signal,'confidence'=>$confidence];
    }

    /** Defensive second temporal gate; timestamps are compared in their stored DATETIME representations. */
    public static function filterPointInTimeRows(array $rows,string $cutoffLocal,string $cutoffUtc): array
    {
        return array_values(array_filter($rows,static function(array $row)use($cutoffLocal,$cutoffUtc):bool{
            if(empty($row['close_time'])||$row['close_time']>$cutoffLocal||empty($row['ingested_at'])||$row['ingested_at']>$cutoffUtc)return false;
            if(!empty($row['available_at'])&&($row['available_at']>$cutoffUtc||$row['ingested_at']<$row['available_at']))return false;
            return true;
        }));
    }

    public static function candidateProbabilities(array $signals,array $weights,array $baseline,?float $shadowSignal,?float $shadowConfidence,?float $shadowP,array $groupAblations): array
    {
        $out=[];
        if(isset($baseline['probability_up']))$out['PROMETHEUS_CURRENT']=['probability_up'=>(float)$baseline['probability_up']];
        $classic=null;foreach($signals as $signal)if(($signal['module']??'')==='ATHENA'){$classic=$signal;break;}
        if($classic){$out['ATHENA_CLASSIC_ONLY']=['probability_up'=>(new ProbabilityCalculator())->calculate((float)$classic['value'],(float)$classic['confidence'])['up']];}
        if($shadowP!==null&&$shadowSignal!==null&&$shadowConfidence!==null){
            $out['ATHENA_50_ONLY']=['probability_up'=>$shadowP];
            $out['PROMETHEUS_ATHENA50_REPLACE']=['probability_up'=>self::ensembleProbability($signals,$weights,[],['value'=>$shadowSignal,'confidence'=>$shadowConfidence])];
            foreach(['ATHENA','HERMES','POSEIDON','HEPHAESTUS','CRONOS','MARKET_RELATIONS'] as $module){
                $out['PROMETHEUS_MINUS_'.$module]=['probability_up'=>self::ensembleProbability($signals,$weights,[$module],null)];
            }
            foreach($groupAblations as $group=>$metrics){$out['ATHENA50_MINUS_'.$group]=['probability_up'=>(float)$metrics['probability_up']];}
        }
        return array_filter($out,static fn($v)=>isset($v['probability_up'])&&is_finite((float)$v['probability_up']));
    }

    private static function ensembleProbability(array $signals,array $weights,array $removed,?array $replacement): float
    {
        $weighted=0.0;$weightSum=0.0;$confidenceSum=0.0;$present=0;
        foreach($signals as $signal){
            $module=(string)($signal['module']??'');$status=(string)($signal['status']??($signal['metadata']['status']??'AVAILABLE'));
            if(in_array($module,$removed,true)||!in_array($status,['AVAILABLE','STALE'],true))continue;
            if($module==='ATHENA'&&$replacement!==null){$value=(float)$replacement['value'];$confidence=(float)$replacement['confidence'];}
            else{$value=(float)($signal['value']??0);$confidence=(float)($signal['confidence']??0);}
            $effective=(float)($weights[$module]??1.0)*max(.05,$confidence);$weighted+=$value*$effective;$weightSum+=$effective;$confidenceSum+=$confidence;$present++;
        }
        if($weightSum<=0)return .5;
        $signal=max(-1.0,min(1.0,$weighted/$weightSum));$coverage=$present/6;
        $confidence=max(.05,min(1.0,($confidenceSum/$present)*(.5+.5*$coverage)));
        return (new ProbabilityCalculator())->calculate($signal,$confidence)['up'];
    }

    public static function evaluateGroupAblations(array $predictions,string $actual): array
    {
        $results=[];
        foreach($predictions as $group=>$metrics){
            if(!isset($metrics['probability_up']))continue;
            $p=max(.005,min(.995,(float)$metrics['probability_up']));
            $direction=$p>.5?'UP':($p<.5?'DOWN':'INDETERMINATE');
            $results[$group]=['hit'=>DirectionPolicy::hit($direction,$actual),'brier'=>DirectionPolicy::brier($p,$actual),
                'logloss'=>$actual==='UP'?-log($p):($actual==='DOWN'?-log(1-$p):null)];
        }
        return $results;
    }

    private function technicalFeatures(array $rows): array
    {
        $c=array_map(static fn($r)=>(float)$r['close_price'],$rows);$o=array_map(static fn($r)=>(float)$r['open_price'],$rows);$h=array_map(static fn($r)=>(float)$r['high_price'],$rows);$l=array_map(static fn($r)=>(float)$r['low_price'],$rows);$v=array_map(static fn($r)=>(float)$r['volume'],$rows);$n=count($c);$i=$n-1;$last=$c[$i];
        $ema21=$this->ema($c,21);$ema50=$this->ema($c,50);$ema9=$this->ema($c,9);$ema200=$this->ema($c,200);$sma20=$this->sma($c,20);$sma50=$this->sma($c,50);
        $atr=$this->atr($h,$l,$c,14);$rsi14=$this->rsi($c,14);$rsi7=$this->rsi($c,7);$macd=$this->macdHistogram($c);
        $trends=[];$mom=[];$volflow=[];$structure=[];
        $add=static function(array &$set,string $name,$raw,?float $score,string $note=''){$set[$name]=['raw_value'=>$raw,'normalized_value'=>$score===null?null:max(-1.0,min(1.0,$score/2.0)),'directional_score'=>$score,'status'=>$score===null?'UNAVAILABLE':'AVAILABLE','note'=>$note];};
        $add($trends,'EMA 9 x EMA 21',$ema9[$i]-$ema21[$i],$ema9[$i]>$ema21[$i]?2:-2);
        $add($trends,'EMA 50 x EMA 200',$ema50[$i]-$ema200[$i],$ema50[$i]>$ema200[$i]?2:-2);
        $add($trends,'Preço x EMA 200',$last-$ema200[$i],$last>$ema200[$i]?2:-2);
        $add($trends,'SMA 20 x SMA 50',$sma20-$sma50,$sma20>$sma50?1:-1);
        $s21=$this->pct($ema21[$i],$ema21[max(0,$i-5)]);$s50=$this->pct($ema50[$i],$ema50[max(0,$i-10)]);
        $add($trends,'Inclinação EMA 21',$s21,$s21>.15?2:($s21<-.15?-2:0));$add($trends,'Inclinação EMA 50',$s50,$s50>.2?2:($s50<-.2?-2:0));
        $plus=$minus=0.0;$trCount=count($c);for($x=max(1,$trCount-14);$x<$trCount;$x++){ $up=$h[$x]-$h[$x-1];$dn=$l[$x-1]-$l[$x];$plus+=($up>$dn&&$up>0)?$up:0;$minus+=($dn>$up&&$dn>0)?$dn:0; }
        $add($trends,'ADX + DMI',[$plus,$minus],$plus===$minus?0:($plus>$minus?2:-2),'Direção DMI proxy; ADX completo não persistido.');
        $add($trends,'Supertrend proxy ATR',[$last,$ema21[$i],$atr[$i]],$last>$ema21[$i]?1:-1,'Proxy, não implementação canônica do Supertrend.');
        $tenkan=(max(array_slice($h,-9))+min(array_slice($l,-9)))/2;$kijun=(max(array_slice($h,-26))+min(array_slice($l,-26)))/2;$add($trends,'Ichimoku Tenkan/Kijun',[$tenkan,$kijun],$tenkan>$kijun?1:-1);
        $hh55=max(array_slice($h,-55));$ll55=min(array_slice($l,-55));$pos55=($hh55-$ll55)>0?($last-$ll55)/($hh55-$ll55):.5;$add($trends,'Donchian 55',$pos55,$pos55>.65?1:($pos55<.35?-1:0));

        $add($mom,'RSI 14',$rsi14,$rsi14>=55?1:($rsi14<=45?-1:0));$add($mom,'RSI 7',$rsi7,$rsi7>=60?1:($rsi7<=40?-1:0));$add($mom,'MACD histogram',$macd,$macd>0?2:-2);
        $khi=max(array_slice($h,-14));$klo=min(array_slice($l,-14));$stoch=$khi>$klo?($last-$klo)/($khi-$klo)*100:50;$add($mom,'Stochastic K/D',$stoch,$stoch>50?1:-1);
        $rsiSlice=array_slice($this->rsiSeries($c,14),-14);$rsiSlice=array_values(array_filter($rsiSlice,static fn($x)=>$x!==null));$srsi=$rsiSlice?(max($rsiSlice)>min($rsiSlice)?($rsi14-min($rsiSlice))/(max($rsiSlice)-min($rsiSlice))*100:50):null;$add($mom,'Stoch RSI',$srsi,$srsi===null?null:($srsi>=60?1:($srsi<=40?-1:0)));
        $roc=$this->pct($last,$c[$i-12]);$add($mom,'ROC 12',$roc,$roc>.5?1:($roc<-.5?-1:0));$tp=array_map(static fn($a,$b,$cc)=>($a+$b+$cc)/3,$h,$l,$c);$tp20=array_slice($tp,-20);$mean=array_sum($tp20)/20;$md=array_sum(array_map(static fn($x)=>abs($x-$mean),$tp20))/20;$cci=$md>0?($tp[$i]-$mean)/(.015*$md):0;$add($mom,'CCI 20',$cci,$cci>50?1:($cci< -50?-1:0));
        $h14=max(array_slice($h,-14));$l14=min(array_slice($l,-14));$wr=$h14>$l14?-100*(($h14-$last)/($h14-$l14)):-50;$add($mom,'Williams %R',$wr,$wr> -50?1:-1);$m10=$last-$c[$i-10];$add($mom,'Momentum 10',$m10,$m10>0?1:-1);$e12=$this->ema($c,12);$e26=$this->ema($c,26);$ppo=($e26[$i]??0)!=0?($e12[$i]-$e26[$i])/$e26[$i]*100:0;$add($mom,'PPO 12/26',$ppo,$ppo>0?1:-1);

        $avgVol=$this->mean(array_slice($v,-21,20));$vr=$avgVol>0?$v[$i]/$avgVol:1;$dir=$c[$i]>=$o[$i]?1:-1;$add($volflow,'Volume relativo',$vr,$vr>=1.2?$dir:0);$obv=$this->obv($c,$v);$add($volflow,'OBV slope 10',$obv[$i]-$obv[max(0,$i-10)],$obv[$i]>$obv[max(0,$i-10)]?1:-1);
        $mfi=$this->mfi($h,$l,$c,$v,14);$add($volflow,'MFI 14',$mfi,$mfi>55?1:($mfi<45?-1:0));$cmf=$this->cmf($h,$l,$c,$v,20);$add($volflow,'Chaikin money flow',$cmf,$cmf>.05?1:($cmf<-.05?-1:0));
        $vwapDen=array_sum(array_slice($v,-50));$vwap=$vwapDen>0?array_sum(array_map(static fn($hh,$ll,$cc,$vv)=>(($hh+$ll+$cc)/3)*$vv,array_slice($h,-50),array_slice($l,-50),array_slice($c,-50),array_slice($v,-50)))/$vwapDen:$last;$vwapDev=$this->pct($last,$vwap);$add($volflow,'VWAP 50',$vwapDev,$vwapDev>0?1:-1);
        $adl=$this->adl($h,$l,$c,$v);$add($volflow,'Accumulation distribution',$adl[$i]-$adl[max(0,$i-10)],$adl[$i]>$adl[max(0,$i-10)]?1:-1);$pvt=$this->pvt($c,$v);$add($volflow,'Price volume trend',$pvt[$i]-$pvt[max(0,$i-10)],$pvt[$i]>$pvt[max(0,$i-10)]?1:-1);$fi=($c[$i]-$c[$i-1])*$v[$i];$add($volflow,'Force index',$fi,$fi>0?1:-1);
        $add($volflow,'Taker buy ratio',null,null,'PROMETHEUS não persiste taker-buy volume nas candles.');$price5=$this->pct($c[$i],$c[$i-5]);$vol5=$this->mean(array_slice($v,-5));$volPrev=$this->mean(array_slice($v,-10,5));$pvScore=$vol5>$volPrev*1.1?($price5>0?1:-1):0;$add($volflow,'Price x volume',$price5,$pvScore);

        $close20=array_slice($c,-20);$mid=$this->mean($close20);$sd=$this->std($close20);$upper=$mid+2*$sd;$lower=$mid-2*$sd;$bbPos=$upper>$lower?($last-$lower)/($upper-$lower):.5;$add($structure,'Bollinger position',$bbPos,$bbPos>.6?1:($bbPos<.4?-1:0));$bbw=$mid>0?($upper-$lower)/$mid*100:0;$old=array_slice($c,-40,20);$oldMid=$this->mean($old);$oldBw=$oldMid>0?4*$this->std($old)/$oldMid*100:$bbw;$add($structure,'Bollinger bandwidth',$bbw,$bbw>$oldBw*1.08?$dir:0);
        $atrPct=$last>0?$atr[$i]/$last*100:0;$atrOld=$c[$i-10]>0?$atr[$i-10]/$c[$i-10]*100:$atrPct;$add($structure,'ATR 14 percent',$atrPct,$atrPct>$atrOld*1.08?$dir:0);
        $kcPos=$atr[$i]>0?($last-($ema21[$i]-2*$atr[$i]))/(4*$atr[$i]):.5;$add($structure,'Keltner position',$kcPos,$kcPos>.6?1:($kcPos<.4?-1:0));$hhNow=max(array_slice($h,-10));$hhOld=max(array_slice($h,-20,10));$llNow=min(array_slice($l,-10));$llOld=min(array_slice($l,-20,10));$struct=$hhNow>$hhOld&&$llNow>$llOld?2:($hhNow<$hhOld&&$llNow<$llOld?-2:0);$add($structure,'Structure HH HL',[$hhNow,$llNow],$struct);
        $tr=$this->trueRanges($h,$l,$c);$tr14=array_sum(array_slice($tr,-14));$range14=max(array_slice($h,-14))-min(array_slice($l,-14));$chop=$range14>0?100*log10($tr14/$range14)/log10(14):50;$add($structure,'Choppiness index',$chop,$chop<45?($last>$ema21[$i]?1:-1):0);
        $rets=$this->logReturns($c);$rv=$this->std(array_slice($rets,-20))*sqrt(20)*100;$rvOld=$this->std(array_slice($rets,-40,20))*sqrt(20)*100;$add($structure,'Realized volatility',$rv,$rv>$rvOld*1.1?$dir:0);$body=abs($c[$i]-$o[$i])/max(1e-12,$h[$i]-$l[$i]);$add($structure,'Candle impulse',$body,$body>.65?$dir:0);
        $hh20=max(array_slice($h,-20));$ll20=min(array_slice($l,-20));$rp=$hh20>$ll20?($last-$ll20)/($hh20-$ll20):.5;$add($structure,'Range position 20',$rp,$rp>.7?1:($rp<.3?-1:0));$ret3=$this->pct($c[$i],$c[$i-3]);$ret12=$this->pct($c[$i],$c[$i-12]);$align=$ret3>0&&$ret12>0?1:($ret3<0&&$ret12<0?-1:0);$add($structure,'Alignment 3 x 12',[$ret3,$ret12],$align);

        $all=[];$this->mergeGroup($all,'TENDENCY',$trends);$this->mergeGroup($all,'MOMENTUM',$mom);$this->mergeGroup($all,'VOLUME_FLOW',$volflow);$this->mergeGroup($all,'VOLATILITY_STRUCTURE',$structure);
        foreach(['Order book imbalance','Bid ask spread','Walls top 5','Depth plus minus 0.25 percent','Basis mark index','Top traders position ratio'] as $missing)$all[$missing]=['raw_value'=>null,'normalized_value'=>null,'directional_score'=>null,'status'=>'UNAVAILABLE','note'=>'Fonte/timestamp não é persistido pelo PROMETHEUS atual.'];
        return $all;
    }

    private function derivativeFeatures(array &$features,string $symbol,string $asOf,string $asOfUtc,array $rows): void
    {
        $latest=static function(string $metric)use($symbol,$asOf,$asOfUtc){return Database::fetch('SELECT value,observed_at FROM derivatives_data WHERE metric=? AND observed_at<=? AND ingested_at IS NOT NULL AND ingested_at<=? AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND (available_at IS NULL OR (available_at<=? AND ingested_at>=available_at)) ORDER BY observed_at DESC LIMIT 1',[$metric,$asOf,$asOfUtc,$asOfUtc]);};
        $fund=$latest('funding_rate');$oiRows=Database::fetchAll('SELECT value,observed_at FROM derivatives_data WHERE metric="open_interest" AND observed_at<=? AND ingested_at IS NOT NULL AND ingested_at<=? AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND (available_at IS NULL OR (available_at<=? AND ingested_at>=available_at)) ORDER BY observed_at DESC LIMIT 48',[$asOf,$asOfUtc,$asOfUtc]);$ls=$latest('long_short_ratio');
        $close=array_map(static fn($r)=>(float)$r['close_price'],$rows);$n=count($close);$px=$n>1?($close[$n-1]-$close[$n-2])/$close[$n-2]:0;
        $add=static function(string $name,$raw,?float $score,string $note='')use(&$features){$features[$name]=['raw_value'=>$raw,'normalized_value'=>$score===null?null:max(-1.0,min(1.0,$score/2.0)),'directional_score'=>$score,'status'=>$score===null?'UNAVAILABLE':'AVAILABLE','note'=>$note];};
        $f=$fund?(float)$fund['value']:null;$add('Funding rate',$f,$f===null?null:($f>.0005?-1:($f<-.0003?1:($f>0?1:($f<0?-1:0)))));
        $oi=array_map(static fn($r)=>(float)$r['value'],$oiRows);$oiPct=count($oi)>1?($oi[0]-$oi[1])/max(1e-12,$oi[1])*100:null;$add('Open interest plus price',[$oiPct,$px],$oiPct===null?null:(abs($oiPct)>.3?($px>0?2:-2):0));$oiLong=count($oi)>2?($oi[0]-$oi[min(count($oi)-1,23)])/max(1e-12,$oi[min(count($oi)-1,23)])*100:null;$add('Open interest trend',$oiLong,$oiLong===null?null:($oiLong>.8?1:($oiLong<-.8?-1:0)));
        $add('Global long short ratio',$ls?(float)$ls['value']:null,$ls?(((float)$ls['value']>1.8||((float)$ls['value']>.95&&(float)$ls['value']<1.05))?-1:(((float)$ls['value']<.6)?1:0)):null,'Mesma fonte/indicador do HEPHAESTUS; grupo de duplicação explícita.');
        $features['Basis mark index']=['raw_value'=>null,'normalized_value'=>null,'directional_score'=>null,'status'=>'UNAVAILABLE','note'=>'PROMETHEUS ainda não coleta mark/index basis.'];
        $features['Top traders position ratio']=['raw_value'=>null,'normalized_value'=>null,'directional_score'=>null,'status'=>'UNAVAILABLE','note'=>'PROMETHEUS ainda não coleta top-trader ratio.'];
    }

    private function emptyFeatures(string $reason): array
    {
        $names=['EMA 9 x EMA 21','EMA 50 x EMA 200','Preço x EMA 200','SMA 20 x SMA 50','Inclinação EMA 21','Inclinação EMA 50','ADX + DMI','Supertrend proxy ATR','Ichimoku Tenkan/Kijun','Donchian 55','RSI 14','RSI 7','MACD histogram','Stochastic K/D','Stoch RSI','ROC 12','CCI 20','Williams %R','Momentum 10','PPO 12/26','Volume relativo','OBV slope 10','MFI 14','Chaikin money flow','VWAP 50','Accumulation distribution','Price volume trend','Force index','Taker buy ratio','Price x volume','Bollinger position','Bollinger bandwidth','ATR 14 percent','Keltner position','Structure HH HL','Choppiness index','Realized volatility','Candle impulse','Range position 20','Alignment 3 x 12','Order book imbalance','Bid ask spread','Walls top 5','Depth plus minus 0.25 percent','Funding rate','Open interest plus price','Open interest trend','Basis mark index','Global long short ratio','Top traders position ratio'];$out=[];foreach($names as $name)$out[$name]=['raw_value'=>null,'normalized_value'=>null,'directional_score'=>null,'status'=>'UNAVAILABLE','note'=>$reason];return $out;
    }

    private function aggregate(array $features): array
    {
        $groups=['TENDENCY'=>[],'MOMENTUM'=>[],'VOLUME_FLOW'=>[],'VOLATILITY_STRUCTURE'=>[],'MICRO_DERIVATIVES'=>[]];
        foreach($features as $name=>$feature){if($feature['status']!=='AVAILABLE')continue;$group=$this->groupFor($name);$groups[$group][]=(float)$feature['normalized_value'];}
        $summary=[];$groupMeans=[];foreach($groups as $name=>$values){$mean=$values?array_sum($values)/count($values):null;$summary[$name]=['available'=>count($values),'mean_score'=>$mean];if($mean!==null)$groupMeans[]=$mean;}
        $signal=$groupMeans?array_sum($groupMeans)/count($groupMeans):0.0;$coverage=count(array_filter($features,static fn($f)=>$f['status']==='AVAILABLE'))/50;
        $dispersion=count($groupMeans)>1?$this->std($groupMeans):0.0;$confidence=max(.05,min(.9,$coverage*(.45+.45*abs($signal)+.10*(1-$dispersion))));
        return [max(-1,min(1,$signal)),$confidence,$summary,count(array_filter($features,static fn($f)=>$f['status']==='AVAILABLE'))];
    }

    private function groupAblations(array $features): array
    {
        $values=['TENDENCY'=>[],'MOMENTUM'=>[],'VOLUME_FLOW'=>[],'VOLATILITY_STRUCTURE'=>[],'MICRO_DERIVATIVES'=>[]];
        foreach($features as $name=>$feature){if(($feature['status']??'')!=='AVAILABLE')continue;$values[$this->groupFor($name)][]=(float)$feature['normalized_value'];}
        $means=[];foreach($values as $group=>$scores)if($scores)$means[$group]=array_sum($scores)/count($scores);
        $out=[];$coverage=count(array_filter($features,static fn($f)=>($f['status']??'')==='AVAILABLE'))/50;
        foreach(array_keys($values) as $removed){$remaining=$means;unset($remaining[$removed]);if(count($remaining)<3)continue;
            $signal=array_sum($remaining)/count($remaining);$dispersion=$this->std(array_values($remaining));
            $confidence=max(.05,min(.9,$coverage*(.45+.45*abs($signal)+.10*(1-$dispersion))));
            $probability=(new ProbabilityCalculator())->calculate($signal,$confidence)['up'];
            $out[$removed]=['signal'=>$signal,'confidence'=>$confidence,'probability_up'=>$probability,'groups_used'=>count($remaining)];
        }
        return $out;
    }

    private function groupFor(string $name): string
    {
        if(in_array($name,['EMA 9 x EMA 21','EMA 50 x EMA 200','Preço x EMA 200','SMA 20 x SMA 50','Inclinação EMA 21','Inclinação EMA 50','ADX + DMI','Supertrend proxy ATR','Ichimoku Tenkan/Kijun','Donchian 55'],true))return 'TENDENCY';
        if(in_array($name,['RSI 14','RSI 7','MACD histogram','Stochastic K/D','Stoch RSI','ROC 12','CCI 20','Williams %R','Momentum 10','PPO 12/26'],true))return 'MOMENTUM';
        if(in_array($name,['Volume relativo','OBV slope 10','MFI 14','Chaikin money flow','VWAP 50','Accumulation distribution','Price volume trend','Force index','Taker buy ratio','Price x volume'],true))return 'VOLUME_FLOW';
        if(in_array($name,['Bollinger position','Bollinger bandwidth','ATR 14 percent','Keltner position','Structure HH HL','Choppiness index','Realized volatility','Candle impulse','Range position 20','Alignment 3 x 12'],true))return 'VOLATILITY_STRUCTURE';
        return 'MICRO_DERIVATIVES';
    }

    private function mergeGroup(array &$target,string $group,array $features): void { foreach($features as $name=>$feature)$target[$name]=$feature; }
    private function ema(array $a,int $p): array { $k=2/($p+1);$o=[(float)$a[0]];for($i=1;$i<count($a);$i++)$o[$i]=(float)$a[$i]*$k+$o[$i-1]*(1-$k);return $o; }
    private function sma(array $a,int $p): float { return array_sum(array_slice($a,-$p))/$p; }
    private function mean(array $a): float { return $a?array_sum($a)/count($a):0.0; }
    private function std(array $a): float { if(!$a)return 0.0;$m=$this->mean($a);return sqrt($this->mean(array_map(static fn($v)=>($v-$m)**2,$a))); }
    private function pct(float $a,float $b): float { return $b==0?0:($a-$b)/$b*100; }
    private function trueRanges(array $h,array $l,array $c): array { $o=[];for($i=0;$i<count($c);$i++)$o[]=$i===0?$h[$i]-$l[$i]:max($h[$i]-$l[$i],abs($h[$i]-$c[$i-1]),abs($l[$i]-$c[$i-1]));return $o; }
    private function atr(array $h,array $l,array $c,int $p): array { return $this->ema($this->trueRanges($h,$l,$c),$p); }
    private function rsi(array $c,int $p): float { $s=$this->rsiSeries($c,$p);return (float)end($s); }
    private function rsiSeries(array $c,int $p): array { $n=count($c);$out=array_fill(0,$n,null);$g=$l=0.0;for($i=1;$i<=$p;$i++){$d=$c[$i]-$c[$i-1];if($d>=0)$g+=$d;else$l+=-$d;}$ag=$g/$p;$al=$l/$p;$out[$p]=$al==0?100:100-100/(1+$ag/$al);for($i=$p+1;$i<$n;$i++){$d=$c[$i]-$c[$i-1];$ag=($ag*($p-1)+max(0,$d))/$p;$al=($al*($p-1)+max(0,-$d))/$p;$out[$i]=$al==0?100:100-100/(1+$ag/$al);}return $out; }
    private function macdHistogram(array $c): float { $e12=$this->ema($c,12);$e26=$this->ema($c,26);$line=[];foreach($e12 as $i=>$v)$line[]=$v-$e26[$i];$sig=$this->ema($line,9);return end($line)-end($sig); }
    private function obv(array $c,array $v): array { $o=[0.0];for($i=1;$i<count($c);$i++)$o[$i]=$o[$i-1]+($c[$i]>$c[$i-1]?$v[$i]:($c[$i]<$c[$i-1]?-$v[$i]:0));return $o; }
    private function mfi(array $h,array $l,array $c,array $v,int $p): float { $pos=$neg=0.0;$n=count($c);for($i=$n-$p;$i<$n;$i++){if($i<=0)continue;$now=($h[$i]+$l[$i]+$c[$i])/3;$prev=($h[$i-1]+$l[$i-1]+$c[$i-1])/3;$mf=$now*$v[$i];if($now>$prev)$pos+=$mf;else$neg+=$mf;}return $neg==0?100:100-100/(1+$pos/$neg); }
    private function cmf(array $h,array $l,array $c,array $v,int $p): float { $flow=$vol=0.0;for($i=count($c)-$p;$i<count($c);$i++){$range=$h[$i]-$l[$i];$mult=$range>0?(($c[$i]-$l[$i])-($h[$i]-$c[$i]))/$range:0;$flow+=$mult*$v[$i];$vol+=$v[$i];}return $vol>0?$flow/$vol:0; }
    private function adl(array $h,array $l,array $c,array $v): array { $out=[];$value=0.0;for($i=0;$i<count($c);$i++){$range=$h[$i]-$l[$i];$mult=$range>0?(($c[$i]-$l[$i])-($h[$i]-$c[$i]))/$range:0;$value+=$mult*$v[$i];$out[]=$value;}return $out; }
    private function pvt(array $c,array $v): array { $out=[0.0];for($i=1;$i<count($c);$i++)$out[$i]=$out[$i-1]+(($c[$i]-$c[$i-1])/max(1e-12,$c[$i-1]))*$v[$i];return $out; }
    private function logReturns(array $c): array { $r=[];for($i=1;$i<count($c);$i++)$r[]=log($c[$i]/max(1e-12,$c[$i-1]));return $r; }
}
