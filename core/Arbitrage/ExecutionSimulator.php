<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Book-depth market-order replay; returns hypothetical fills only. */
final class ExecutionSimulator
{
    public function marketOrder(array $levels,string $side,float $quantity,float $feeRate=0.001,float $slippageBps=0): array
    {
        $side=strtoupper($side);if(!in_array($side,['BUY','SELL'],true)||$quantity<=0||$feeRate<0||$slippageBps<0)
            throw new \InvalidArgumentException('INVALID_SIMULATION_ORDER');
        $remaining=$quantity;$filled=0.0;$gross=0.0;$fills=[];
        foreach($levels as $level){$price=(float)($level['price']??0);$available=(float)($level['quantity']??0);
            if($price<=0||$available<=0)continue;$take=min($remaining,$available);$gross+=$price*$take;$filled+=$take;$remaining-=$take;
            $fills[]=['price'=>$price,'quantity'=>$take];if($remaining<=1e-12)break;}
        if($filled<=0)return ['status'=>'NO_LIQUIDITY','quantity_filled'=>0.0,'quantity_unfilled'=>$quantity,'vwap'=>null,'fee'=>0.0,'slippage_cost'=>0.0];
        $vwap=$gross/$filled;$impact=$gross*$slippageBps/10000;$fee=$gross*$feeRate;
        return ['status'=>$remaining>1e-12?'PARTIAL':'FILLED','side'=>$side,'quantity_requested'=>$quantity,
            'quantity_filled'=>$filled,'quantity_unfilled'=>max(0,$remaining),'vwap'=>$vwap,'gross_notional'=>$gross,
            'fee'=>$fee,'slippage_cost'=>$impact,'net_cash_flow'=>$side==='BUY'?-$gross-$fee-$impact:$gross-$fee-$impact,
            'fills'=>$fills,'mode'=>'SIMULATION_ONLY'];
    }

    public function hedge(array $buyAsks,array $sellBids,float $quantity,float $feeRate=0.001,float $slippageBps=0): array
    {
        $buy=$this->marketOrder($buyAsks,'BUY',$quantity,$feeRate,$slippageBps);
        $sell=$this->marketOrder($sellBids,'SELL',$quantity,$feeRate,$slippageBps);
        $matched=min($buy['quantity_filled'],$sell['quantity_filled']);
        $balanced=abs($buy['quantity_filled']-$sell['quantity_filled'])<=1e-12;
        return ['status'=>$balanced&&$matched>0?'HEDGED':($matched>0?'PARTIAL_HEDGE':'NO_FILL'),
            'matched_quantity'=>$matched,'residual_quantity'=>$buy['quantity_filled']-$sell['quantity_filled'],
            'buy'=>$buy,'sell'=>$sell,'net_entry_cash_flow'=>($sell['net_cash_flow']??0)+($buy['net_cash_flow']??0),
            'mode'=>'SIMULATION_ONLY'];
    }
}
