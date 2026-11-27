<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

final class PerformanceAnalytics
{
    public static function calculate(array $trades,array $equityPoints=[]): array
    {
        $pnls=array_map(static fn(array $t):float=>(float)($t['net_pnl']??$t['net_profit']??0),$trades);
        $wins=array_sum(array_map(static fn(float $v):float=>max(0,$v),$pnls));
        $losses=abs(array_sum(array_map(static fn(float $v):float=>min(0,$v),$pnls)));
        $equities=array_values(array_map(static fn($p):array=>is_array($p)?['equity'=>(float)($p['equity']??0),'at'=>$p['at']??null]:['equity'=>(float)$p,'at'=>null],$equityPoints));
        $peak=0.0;$maxDd=0.0;$returns=[];$firstAt=null;$lastAt=null;
        foreach($equities as $i=>$point){$eq=$point['equity'];$peak=max($peak,$eq);if($peak>0)$maxDd=max($maxDd,($peak-$eq)/$peak);
            if(is_string($point['at'])){$firstAt=$firstAt??$point['at'];$lastAt=$point['at'];}
            if($i>0&&$equities[$i-1]['equity']>0){$ret=$eq/$equities[$i-1]['equity']-1;$returns[]=$ret;}}
        $sharpe=self::ratio($returns,false);$sortino=self::ratio($returns,true);
        $elapsedDays=$firstAt!==null&&$lastAt!==null?max(1,(strtotime($lastAt.' UTC')-strtotime($firstAt.' UTC'))/86400):count($returns);
        $annualReturn=$elapsedDays>0&&$equities?end($equities)['equity']/max(1e-12,$equities[0]['equity']):null;
        $calmar=$annualReturn!==null&&$maxDd>0?($annualReturn**(365/$elapsedDays)-1)/$maxDd:null;
        $net=array_sum($pnls);
        return ['trade_count'=>count($pnls),'net_pnl'=>$net,'win_rate'=>$pnls?count(array_filter($pnls,static fn(float $v):bool=>$v>0))/count($pnls):null,
            'profit_factor'=>$losses>0?$wins/$losses:null,'max_drawdown_pct'=>$maxDd*100,'sharpe'=>$sharpe,'sortino'=>$sortino,
            'calmar'=>$calmar,'capital_used'=>max(0,(float)($trades?max(array_map(static fn(array $t):float=>(float)($t['capital_used']??0),$trades)):0)),
            'classification'=>'PAPER_OR_BACKTEST_ONLY'];
    }

    private static function ratio(array $returns,bool $downside): ?float
    {
        if(count($returns)<2)return null;$mean=array_sum($returns)/count($returns);$dev=[];
        foreach($returns as $r){$d=$downside?min(0,$r):$r;$dev[]=($downside?$d:$d-$mean)**2;}
        $sd=sqrt(array_sum($dev)/($downside?count($returns):count($dev)-1));if($sd<=0)return null;
        return $mean/$sd*sqrt(365);
    }
}
