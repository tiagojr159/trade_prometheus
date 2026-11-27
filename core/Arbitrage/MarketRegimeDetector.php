<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

final class MarketRegimeDetector
{
    public function detect(array $snapshot): array
    {
        $prices=array_values(array_filter($snapshot['prices']??[],static fn($v):bool=>is_numeric($v)&&(float)$v>0));
        if(count($prices)<20)return ['regime'=>'UNKNOWN','confidence'=>null,'metrics'=>['observations'=>count($prices)]];
        $returns=[];for($i=1;$i<count($prices);$i++)$returns[]=(float)$prices[$i]/(float)$prices[$i-1]-1;
        $window=array_slice($returns,-20);$mean=array_sum($window)/count($window);$variance=0;$ewmaVariance=0.0;$lambda=0.94;
        foreach($window as $r){$variance+=($r-$mean)**2;$ewmaVariance=$lambda*$ewmaVariance+(1-$lambda)*($r-$mean)**2;}
        $vol=sqrt($ewmaVariance);
        $trend=(float)end($prices)/(float)$prices[max(0,count($prices)-20)]-1;
        $funding=[];foreach(($snapshot['funding']??[]) as $venues)foreach($venues as $r)if(is_numeric($r['rate']??null)&&is_numeric($r['interval_hours']??null)&&$r['interval_hours']>0)$funding[]=(float)$r['rate']*24/(float)$r['interval_hours'];
        $meanFunding=$funding?array_sum($funding)/count($funding):null;$books=[];foreach(($snapshot['books']??[]) as $venues)foreach($venues as $b)if(is_numeric($b['bid']??null)&&is_numeric($b['ask']??null)&&$b['ask']>0)$books[]=((float)$b['ask']-(float)$b['bid'])/((float)$b['ask']+(float)$b['bid'])*2;
        $meanSpread=$books?array_sum($books)/count($books):null;
        $mids=[];foreach(($snapshot['books']??[]) as $venues)foreach($venues as $b)
            if(is_numeric($b['bid']??null)&&is_numeric($b['ask']??null)&&(float)$b['bid']>0)$mids[]=((float)$b['bid']+(float)$b['ask'])/2;
        $venueDispersion=null;if(count($mids)>=2){sort($mids,SORT_NUMERIC);$venueDispersion=(end($mids)-reset($mids))/max(1e-12,(end($mids)+reset($mids))/2);}
        $regime=$trend>=0.02?'BULLISH':($trend<=-0.02?'BEARISH':'SIDEWAYS');
        if($vol>=0.03)$regime.='_HIGH_VOLATILITY';elseif($vol<=0.002)$regime.='_LOW_VOLATILITY';
        if($meanFunding!==null&&abs($meanFunding)>=0.001)$regime.='_FUNDING_ELEVATED';
        if($meanFunding!==null&&$meanFunding<0)$regime.='_FUNDING_INVERTED';
        if($meanSpread!==null&&$meanSpread>=0.002)$regime.='_LOW_LIQUIDITY';
        if($venueDispersion!==null&&$venueDispersion>=0.005)$regime.='_VENUE_INSTABILITY';
        return ['regime'=>$regime,'confidence'=>null,'metrics'=>['observations'=>count($prices),'trend_return'=>$trend,
            'ewma_volatility_proxy'=>$vol,'volatility_estimator'=>'EWMA_LAMBDA_0.94','sample_volatility'=>$variance>0?sqrt($variance/max(1,count($window)-1)):0.0,
            'mean_daily_funding'=>$meanFunding,'mean_relative_spread'=>$meanSpread,'venue_mid_dispersion'=>$venueDispersion,
            'probability_status'=>'NOT_CALIBRATED']];
    }
}
