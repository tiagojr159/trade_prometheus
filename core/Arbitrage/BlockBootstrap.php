<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Circular block bootstrap for trade returns; deterministic for audit/replay. */
final class BlockBootstrap
{
    public static function analyze(array $trades, float $initialEquity, int $trials=1000, int $blockSize=5, string $seed=''): array
    {
        $returns=[];
        foreach($trades as $trade){
            $pnl=$trade['net_pnl']??$trade['net_profit']??null;
            $capital=$trade['capital_used']??null;
            if(is_numeric($pnl)&&is_numeric($capital)&&(float)$capital>0)$returns[]=(float)$pnl/(float)$capital;
            elseif(is_numeric($pnl)&&$initialEquity>0)$returns[]=(float)$pnl/$initialEquity;
        }
        if(!$returns||$initialEquity<=0)return ['status'=>'INSUFFICIENT_TRADES','trials'=>0,'block_size'=>0,
            'final_equity_percentiles'=>null,'max_drawdown_percentiles'=>null,'probability_of_loss'=>null];
        $trials=max(100,min(10000,$trials));$blockSize=max(1,min(count($returns),$blockSize));
        $state=(int)(hexdec(substr(hash('sha256',$seed.'|'.count($returns).'|'.$trials.'|'.$blockSize),0,7))%2147483646)+1;
        $final=[];$drawdowns=[];$losses=0;$n=count($returns);
        for($trial=0;$trial<$trials;$trial++){
            $equity=$initialEquity;$peak=$initialEquity;$maxDrawdown=0.0;$picked=0;
            while($picked<$n){
                $state=($state*48271)%2147483647;$start=$state%$n;
                for($j=0;$j<$blockSize&&$picked<$n;$j++,$picked++){
                    $equity*=max(0.0,1.0+$returns[($start+$j)%$n]);
                    $peak=max($peak,$equity);if($peak>0)$maxDrawdown=max($maxDrawdown,($peak-$equity)/$peak);
                }
            }
            $final[]=$equity;$drawdowns[]=$maxDrawdown*100;if($equity<$initialEquity)$losses++;
        }
        sort($final,SORT_NUMERIC);sort($drawdowns,SORT_NUMERIC);
        return ['status'=>'ESTIMATED_BLOCK_BOOTSTRAP','trials'=>$trials,'block_size'=>$blockSize,'observations'=>$n,
            'final_equity_percentiles'=>['p05'=>self::percentile($final,0.05),'p50'=>self::percentile($final,0.5),'p95'=>self::percentile($final,0.95)],
            'max_drawdown_percentiles'=>['p05'=>self::percentile($drawdowns,0.05),'p50'=>self::percentile($drawdowns,0.5),'p95'=>self::percentile($drawdowns,0.95)],
            'probability_of_loss'=>$losses/$trials,'seed_sha256'=>hash('sha256',$seed)];
    }

    private static function percentile(array $sorted,float $p): float
    {
        $position=(count($sorted)-1)*$p;$lo=(int)floor($position);$hi=(int)ceil($position);
        if($lo===$hi)return (float)$sorted[$lo];$weight=$position-$lo;
        return (float)$sorted[$lo]*(1-$weight)+(float)$sorted[$hi]*$weight;
    }
}
