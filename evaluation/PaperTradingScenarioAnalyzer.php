<?php
declare(strict_types=1);

namespace Prometheus\evaluation;

/** Reprices completed paper trades under cost multipliers without changing the ledger. */
final class PaperTradingScenarioAnalyzer
{
    public static function analyze(array $trades, float $initialBalance, float $costMultiplier): array
    {
        usort($trades, static fn(array $a,array $b): int => strcmp((string)$a['exit_at'],(string)$b['exit_at']));
        $equity=max(0.0,$initialBalance);$peak=$equity;$maxDrawdown=0.0;$wins=0;$losses=0;
        $gainSum=0.0;$lossSum=0.0;$netSum=0.0;$costSum=0.0;$winSum=0.0;$lossTotal=0.0;
        foreach($trades as $trade){
            $fees=max(0.0,(float)($trade['fees_usd']??0));$slippage=max(0.0,(float)($trade['slippage_usd']??0));
            $cost=($fees+$slippage)*max(0.0,$costMultiplier);
            // gross_pnl uses adverse fill prices, so add recorded slippage back before repricing.
            $net=(float)($trade['gross_pnl']??0)+$slippage-$cost;
            $netSum+=$net;$costSum+=$cost;$equity=max(0.0,$equity+$net);$peak=max($peak,$equity);
            if($peak>0)$maxDrawdown=max($maxDrawdown,($peak-$equity)/$peak*100);
            if($net>0){$wins++;$gainSum+=$net;$winSum+=$net;}elseif($net<0){$losses++;$lossSum+=abs($net);$lossTotal+=$net;}
        }
        $n=count($trades);
        return ['trades'=>$n,'wins'=>$wins,'losses'=>$losses,'net_pnl'=>$netSum,
            'net_return_pct'=>$n>0&&$initialBalance>0?$netSum/$initialBalance*100:null,
            'win_rate_pct'=>$n>0?$wins/$n*100:null,'average_win'=>$wins>0?$winSum/$wins:null,
            'average_loss'=>$losses>0?$lossTotal/$losses:null,'expectancy'=>$n>0?$netSum/$n:null,
            'profit_factor'=>$lossSum>0?$gainSum/$lossSum:null,'max_drawdown_pct'=>$maxDrawdown,'costs'=>$costSum];
    }
}
