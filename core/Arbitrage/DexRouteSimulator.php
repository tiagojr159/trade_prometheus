<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Research-only exact-input route simulation for explicitly declared constant-product pools. */
final class DexRouteSimulator
{
    public static function configuredRoutes(): array
    {
        $json=getenv('PROMETHEUS_DEX_ROUTES');if(!is_string($json)||trim($json)==='')return [];
        $routes=json_decode($json,true);if(!is_array($routes)||array_values($routes)!==$routes)throw new \RuntimeException('INVALID_PROMETHEUS_DEX_ROUTES_JSON');
        return $routes;
    }

    public function simulate(array $route,array $buy,array $sell,?array $gas): array
    {
        $reasons=[];$num=static fn($v):?float=>is_numeric($v)&&is_finite((float)$v)?(float)$v:null;
        $input=$num($route['input_quote_amount']??null);$quoteUsd=$num($route['quote_token_price_usd']??$buy['quote_token_price_usd']??null);
        $baseBuy=$num($buy['base_token_balance']??null);$quoteBuy=$num($buy['quote_token_balance']??null);
        $baseSell=$num($sell['base_token_balance']??null);$quoteSell=$num($sell['quote_token_balance']??null);
        if(strtoupper((string)($buy['amm_model']??''))!=='CONSTANT_PRODUCT'||strtoupper((string)($sell['amm_model']??''))!=='CONSTANT_PRODUCT')$reasons[]='POOL_MODEL_UNSUPPORTED';
        foreach(['network','base_token_address','quote_token_address'] as $key)if(strtolower((string)($buy[$key]??''))!==strtolower((string)($sell[$key]??'')))$reasons[]='POOL_PAIR_MISMATCH';
        foreach(['network','base_token_address','quote_token_address'] as $key)if(trim((string)($buy[$key]??''))==='')$reasons[]='POOL_IDENTITY_INCOMPLETE';
        if($input===null||$input<=0||$quoteUsd===null||$quoteUsd<=0||$baseBuy===null||$baseBuy<=0||$quoteBuy===null||$quoteBuy<=0||$baseSell===null||$baseSell<=0||$quoteSell===null||$quoteSell<=0)$reasons[]='INVALID_POOL_OR_INPUT_DATA';
        $buyAt=self::utcTimestamp($buy['observed_at_utc']??null);$sellAt=self::utcTimestamp($sell['observed_at_utc']??null);
        if(!$buyAt||!$sellAt||abs($buyAt-$sellAt)>(int)($route['max_pool_skew_seconds']??60))$reasons[]='POOL_SNAPSHOT_SKEW';
        $maxAge=(int)($route['max_pool_age_seconds']??120);$asOf=self::utcTimestamp($route['as_of_utc']??gmdate('Y-m-d H:i:s'));
        if(!$asOf||!$buyAt||!$sellAt||$asOf-$buyAt>$maxAge||$asOf-$sellAt>$maxAge||$buyAt-$asOf>5||$sellAt-$asOf>5)$reasons[]='STALE_POOL_SNAPSHOT';
        $sellQuoteUsd=$num($sell['quote_token_price_usd']??null);$tolerance=$num($route['quote_price_tolerance_pct']??1.0);
        if($sellQuoteUsd===null||$sellQuoteUsd<=0||$tolerance===null||$tolerance<0||abs($sellQuoteUsd-$quoteUsd)/$quoteUsd*100>$tolerance)$reasons[]='QUOTE_PRICE_MISMATCH';
        $buyFee=$num($route['buy_fee_rate']??null);$sellFee=$num($route['sell_fee_rate']??null);
        if($buyFee===null||$sellFee===null||$buyFee<0||$buyFee>=1||$sellFee<0||$sellFee>=1)$reasons[]='EXPLICIT_FEE_RATES_REQUIRED';
        $gasUsd=null;$gasPrice=$gas['gas_price_wei']??null;$gasUnits=$num($route['gas_units']??null);$nativeUsd=$num($route['native_token_price_usd']??null);
        if(!is_string($gasPrice)&&!is_int($gasPrice)||!preg_match('/^\d+$/D',(string)$gasPrice)||$gasUnits===null||$gasUnits<=0||$nativeUsd===null||$nativeUsd<=0){$reasons[]='GAS_COST_INPUTS_REQUIRED';}
        else{$gasFloat=(float)$gasPrice;if(!is_finite($gasFloat)||$gasFloat<=0)$reasons[]='INVALID_GAS_PRICE';else $gasUsd=$gasFloat*$gasUnits*$nativeUsd/1e18;
            $gasAt=self::utcTimestamp($gas['observed_at_utc']??null);if(!$gasAt||!$asOf||$asOf-$gasAt>$maxAge||$gasAt-$asOf>5)$reasons[]='STALE_GAS_SNAPSHOT';}
        if($reasons)return ['status'=>'REJECTED','rejection_reasons'=>array_values(array_unique($reasons)),'data_quality'=>false,'execution_mode'=>'OBSERVATION_ONLY'];

        $idealBase=$input*$baseBuy/$quoteBuy;$idealOut=$idealBase*$quoteSell/$baseSell;
        $effectiveBuy=$input*(1-$buyFee);$baseOut=$effectiveBuy*$baseBuy/($quoteBuy+$effectiveBuy);
        $noFeeBase=$input*$baseBuy/($quoteBuy+$input);$noFeeOut=$noFeeBase*$quoteSell/($baseSell+$noFeeBase);
        $effectiveSell=$baseOut*(1-$sellFee);$quoteOut=$effectiveSell*$quoteSell/($baseSell+$effectiveSell);
        $gross=($idealOut-$input)*$quoteUsd;$impact=max(0,($idealOut-$noFeeOut)*$quoteUsd);
        $poolFees=max(0,($noFeeOut-$quoteOut)*$quoteUsd);$netBeforeGas=($quoteOut-$input)*$quoteUsd;
        $observedAt=gmdate('Y-m-d H:i:s',min($buyAt,$sellAt));
        return ['status'=>'SIMULATED','model'=>'CONSTANT_PRODUCT','route'=>(string)($buy['pool_label']??$buy['pool_address']).' > '.(string)($sell['pool_label']??$sell['pool_address']),
            'buy_pool_address'=>$buy['pool_address']??null,'sell_pool_address'=>$sell['pool_address']??null,'network'=>$buy['network'],
            'input_quote_amount'=>$input,'base_amount_out'=>$baseOut,'quote_amount_out'=>$quoteOut,'quote_token_price_usd'=>$quoteUsd,
            'liquidity_available_usd'=>min($quoteBuy,$quoteSell)*$quoteUsd,
            'gross_edge'=>$gross,'price_impact_cost'=>$impact,'pool_fee_cost'=>$poolFees,'gas_cost'=>$gasUsd,
            'deterministic_net_after_gas'=>$netBeforeGas-$gasUsd,'slippage_cost'=>0.0,'revert_cost'=>$gasUsd,'revert_probability'=>null,
            'revert_probability_calibrated'=>false,'observed_at'=>$observedAt,'data_quality'=>true,'execution_mode'=>'OBSERVATION_ONLY',
            'pool_reserves'=>['buy'=>['base'=>$baseBuy,'quote'=>$quoteBuy],'sell'=>['base'=>$baseSell,'quote'=>$quoteSell]],
            'cost_reconciliation'=>['gross_edge_usd'=>$gross,'curve_impact_usd'=>$impact,'pool_fees_usd'=>$poolFees,'actual_pnl_before_gas_usd'=>$netBeforeGas]];
    }

    private static function utcTimestamp($value): ?int
    {
        if(!is_string($value)||trim($value)==='')return null;
        try{return (new \DateTimeImmutable($value,new \DateTimeZone('UTC')))->getTimestamp();}catch(\Throwable $e){return null;}
    }
}
