<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Research-only DEX/CEX route simulation. It cannot place orders or submit transactions. */
final class DexCexRouteSimulator
{
    public static function configuredRoutes(): array
    {
        $json=getenv('PROMETHEUS_DEX_CEX_ROUTES');
        if(!is_string($json)||trim($json)==='')return [];
        $routes=json_decode($json,true);
        if(!is_array($routes)||array_values($routes)!==$routes)throw new \RuntimeException('INVALID_PROMETHEUS_DEX_CEX_ROUTES_JSON');
        return $routes;
    }

    public function simulate(array $config,array $pool,array $book,?array $gas): array
    {
        $bad=[];$n=static fn($v):?float=>is_numeric($v)&&is_finite((float)$v)?(float)$v:null;
        $dir=(string)($config['direction']??'');$feeDex=$n($config['dex_fee_rate']??null);$feeCex=$n($config['cex_fee_rate']??null);
        $slipBps=$n($config['cex_slippage_bps']??null);$adverse=$n($config['adverse_selection_cost_usd']??null);
        $quoteUsd=$n($config['quote_token_price_usd']??null);$bx=$n($pool['base_token_balance']??null);$qy=$n($pool['quote_token_balance']??null);
        if(strtoupper((string)($pool['amm_model']??''))!=='CONSTANT_PRODUCT')$bad[]='POOL_MODEL_UNSUPPORTED';
        if(!in_array($dir,['BUY_DEX_SELL_CEX','BUY_CEX_SELL_DEX'],true))$bad[]='INVALID_ROUTE_DIRECTION';
        if($feeDex===null||$feeDex<0||$feeDex>=1||$feeCex===null||$feeCex<0||$feeCex>=1||$slipBps===null||$slipBps<0||$adverse===null||$adverse<0
            ||$quoteUsd===null||$quoteUsd<=0||$bx===null||$bx<=0||$qy===null||$qy<=0)$bad[]='INVALID_ROUTE_COST_OR_RESERVE_INPUT';
        $qty=$dir==='BUY_DEX_SELL_CEX'?$n($config['quote_input_amount']??null):$n($config['base_quantity']??null);
        if($qty===null||$qty<=0)$bad[]='INVALID_ROUTE_QUANTITY';
        if(strtolower((string)($config['network']??''))!==strtolower((string)($pool['network']??'')))$bad[]='NETWORK_MISMATCH';
        $at=self::ts($config['as_of_utc']??null);$pt=self::ts($pool['observed_at_utc']??null);$bt=self::ts($book['observed_at']??null);$gt=self::ts($gas['observed_at_utc']??null);
        $age=max(1,(int)($config['max_data_age_seconds']??120));
        if(!$at||!$pt||!$bt||abs($at-$pt)>$age||abs($at-$bt)>$age||$pt-$at>5||$bt-$at>5)$bad[]='STALE_POOL_OR_BOOK';
        if(!$gt||!$at||$at-$gt>$age||$gt-$at>5)$bad[]='STALE_GAS_SNAPSHOT';
        $gwei=$gas['gas_price_wei']??null;$units=$n($config['gas_units']??null);$nativeUsd=$n($config['native_token_price_usd']??null);
        if((!is_string($gwei)&&!is_int($gwei))||!preg_match('/^\d+$/D',(string)$gwei)||$units===null||$units<=0||$nativeUsd===null||$nativeUsd<=0)$bad[]='GAS_COST_INPUTS_REQUIRED';
        if(!is_array($book['bids']??null)||!$book['bids']||!is_array($book['asks']??null)||!$book['asks'])$bad[]='CEX_DEPTH_REQUIRED';
        if($bad)return ['status'=>'REJECTED','rejection_reasons'=>array_values(array_unique($bad)),'execution_mode'=>'OBSERVATION_ONLY'];

        $gasUsd=(float)$gwei*(float)$units*(float)$nativeUsd/1e18;$exec=new ExecutionSimulator();
        if($dir==='BUY_DEX_SELL_CEX'){
            $ideal=$qty*$bx/$qy;$withoutFee=$qty*(1-$feeDex)*$bx/($qy+$qty*(1-$feeDex));$baseOut=$withoutFee;
            $cex=$exec->marketOrder($book['bids'],'SELL',$baseOut,$feeCex,$slipBps);
            if(($cex['quantity_filled']??0)+1e-12<$baseOut)return ['status'=>'REJECTED','rejection_reasons'=>['CEX_DEPTH_INSUFFICIENT'],'cex_fill_simulation'=>$cex,'execution_mode'=>'OBSERVATION_ONLY'];
            $gross=($ideal*(float)$cex['vwap']-$qty)*$quoteUsd;$impact=max(0,($ideal-$qty*$bx/($qy+$qty))*(float)$cex['vwap']*$quoteUsd);
            $poolFee=max(0,($qty*$bx/($qy+$qty)-$baseOut)*(float)$cex['vwap']*$quoteUsd);
            $actual=((float)$cex['net_cash_flow']-$qty)*$quoteUsd;$capital=$qty*$quoteUsd;$base=$baseOut;
        }else{
            $cex=$exec->marketOrder($book['asks'],'BUY',$qty,$feeCex,$slipBps);
            if(($cex['quantity_filled']??0)+1e-12<$qty)return ['status'=>'REJECTED','rejection_reasons'=>['CEX_DEPTH_INSUFFICIENT'],'cex_fill_simulation'=>$cex,'execution_mode'=>'OBSERVATION_ONLY'];
            $ideal=$qty*$qy/$bx;$withoutFee=$qty*(1-$feeDex)*$qy/($bx+$qty*(1-$feeDex));$quoteOut=$withoutFee;
            $gross=($ideal-(float)$cex['gross_notional'])*$quoteUsd;$impact=max(0,($ideal-$qty*$qy/($bx+$qty))*$quoteUsd);
            $poolFee=max(0,($qty*$qy/($bx+$qty)-$quoteOut)*$quoteUsd);
            $actual=($quoteOut-(float)$cex['gross_notional']-(float)$cex['fee']-(float)$cex['slippage_cost'])*$quoteUsd;
            $capital=((float)$cex['gross_notional']+(float)$cex['fee']+(float)$cex['slippage_cost'])*$quoteUsd;$base=$qty;
        }
        $cexFee=(float)$cex['fee']*$quoteUsd;$cexSlip=(float)$cex['slippage_cost']*$quoteUsd;$net=$actual-$gasUsd-(float)$adverse;
        return ['status'=>'SIMULATED','model'=>'CONSTANT_PRODUCT_PLUS_CEX_DEPTH','direction'=>$dir,
            'route'=>(string)($pool['pool_label']??$pool['pool_address']??'DEX').' > '.(string)($config['cex_venue']??'CEX'),
            'pool_address'=>$pool['pool_address']??null,'cex_venue'=>$config['cex_venue']??null,'symbol'=>$config['symbol']??null,
            'gross_edge'=>$gross,'price_impact_cost'=>$impact,'pool_fee_cost'=>$poolFee,'gas_cost'=>$gasUsd,'cex_fee_cost'=>$cexFee,
            'slippage_cost'=>$cexSlip,'adverse_selection_cost'=>(float)$adverse,'revert_cost'=>$gasUsd,'revert_probability'=>null,
            'revert_probability_calibrated'=>false,'net_before_gas_and_adverse_cost'=>$actual,'deterministic_net_after_gas'=>$net,
            'capital_required'=>$capital,'liquidity_available_usd'=>max(0,(float)$cex['gross_notional']*$quoteUsd),
            'base_quantity'=>$base,'cex_vwap'=>$cex['vwap'],'cex_fill_simulation'=>$cex,
            'observed_at'=>gmdate('Y-m-d H:i:s',min($at,$pt,$bt,$gt)),'data_quality'=>true,'execution_mode'=>'OBSERVATION_ONLY',
            'cost_reconciliation'=>['gross_edge_usd'=>$gross,'curve_impact_usd'=>$impact,'pool_fee_usd'=>$poolFee,
                'cex_fee_usd'=>$cexFee,'cex_slippage_usd'=>$cexSlip,'actual_pnl_before_gas_adverse_usd'=>$actual]];
    }

    private static function ts($value):?int
    {if(!is_string($value)||trim($value)==='')return null;try{return (new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->getTimestamp();}catch(\Throwable $e){return null;}}
}
