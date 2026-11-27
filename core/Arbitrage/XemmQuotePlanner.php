<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

/** Builds paper/replay quote intents from executable books; contains no venue client or order API. */
final class XemmQuotePlanner
{
    public function plan(array $snapshot,array $config): array
    {
        $symbol=(string)($config['symbol']??'');$makerVenue=(string)($config['maker_venue']??'');$hedgeVenue=(string)($config['hedge_venue']??'');
        $side=strtoupper((string)($config['maker_side']??''));$quantity=(float)($config['quantity']??0);
        $makerFee=$this->number($config['maker_fee_rate']??null);$takerFee=$this->number($config['taker_fee_rate']??null);
        $slippage=$this->number($config['slippage_bps']??null);$adverse=$this->number($config['adverse_selection_cost']??null);
        $threshold=$this->number($config['minimum_net_profit_usd']??null);
        if($symbol===''||$makerVenue===''||$hedgeVenue===''||$makerVenue===$hedgeVenue||!in_array($side,['BUY','SELL'],true)
            ||$quantity<=0||$makerFee===null||$makerFee<0||$takerFee===null||$takerFee<0||$slippage===null||$slippage<0
            ||$adverse===null||$adverse<0||$threshold===null)
            return ['status'=>'REJECTED','reason'=>'INCOMPLETE_QUOTE_CONFIGURATION','execution_mode'=>'PAPER_REPLAY_ONLY'];
        $books=$snapshot['books']??[];$maker=$books[$symbol][$makerVenue]??$books[$makerVenue][$symbol]??null;
        $hedge=$books[$symbol][$hedgeVenue]??$books[$hedgeVenue][$symbol]??null;
        if(!is_array($maker)||!is_array($hedge))return ['status'=>'REJECTED','reason'=>'BOOK_UNAVAILABLE','execution_mode'=>'PAPER_REPLAY_ONLY'];
        $makerDepthBook=$snapshot['book_depth'][$makerVenue][$symbol]??[];$hedgeDepthBook=$snapshot['book_depth'][$hedgeVenue][$symbol]??[];
        foreach(['bids','asks'] as $levels){if(empty($maker[$levels])&&!empty($makerDepthBook[$levels]))$maker[$levels]=$makerDepthBook[$levels];
            if(empty($hedge[$levels])&&!empty($hedgeDepthBook[$levels]))$hedge[$levels]=$hedgeDepthBook[$levels];}
        foreach([$maker,$hedge] as $book)if(empty($book['observed_at'])||!is_numeric($book['bid']??null)||!is_numeric($book['ask']??null)
            ||(float)$book['bid']<=0||(float)$book['ask']<(float)$book['bid'])
            return ['status'=>'REJECTED','reason'=>'INVALID_OR_UNDATED_BOOK','execution_mode'=>'PAPER_REPLAY_ONLY'];
        $makerPrice=(float)($side==='BUY'?$maker['bid']:$maker['ask']);
        if($side==='BUY'&&$makerPrice>=(float)$maker['ask']||$side==='SELL'&&$makerPrice<=(float)$maker['bid'])
            return ['status'=>'REJECTED','reason'=>'MAKER_PRICE_WOULD_TAKE','execution_mode'=>'PAPER_REPLAY_ONLY'];
        $makerDepth=(float)($side==='BUY'?$maker['bid_qty']??0:$maker['ask_qty']??0);
        $hedgeSide=$side==='BUY'?'SELL':'BUY';$hedgeLevels=$hedgeSide==='SELL'?($hedge['bids']??[]):($hedge['asks']??[]);
        $qty=min($quantity,$makerDepth);if($qty<=0||!$hedgeLevels)return ['status'=>'REJECTED','reason'=>'INSUFFICIENT_EXECUTABLE_DEPTH','execution_mode'=>'PAPER_REPLAY_ONLY'];
        $hedgeFill=(new ExecutionSimulator())->marketOrder($hedgeLevels,$hedgeSide,$qty,$takerFee,$slippage);
        if((float)$hedgeFill['quantity_filled']+1e-12<$qty)return ['status'=>'REJECTED','reason'=>'PARTIAL_HEDGE_DEPTH','hedge_simulation'=>$hedgeFill,'execution_mode'=>'PAPER_REPLAY_ONLY'];
        $makerFeeUsd=$makerPrice*$qty*$makerFee;$makerCash=$makerPrice*$qty*($side==='BUY'?-1:1)-$makerFeeUsd;
        $netIfFilled=$makerCash+(float)$hedgeFill['net_cash_flow']-$adverse;
        if($netIfFilled<=$threshold)return ['status'=>'NO_QUOTE','reason'=>'NET_EDGE_BELOW_CONFIGURED_THRESHOLD','net_if_filled_usd'=>$netIfFilled,
            'execution_mode'=>'PAPER_REPLAY_ONLY'];
        $observed=(string)($snapshot['snapshot_at']??$maker['observed_at']);$available=(string)($snapshot['available_at_utc']??$observed);
        $quoteId=(string)($config['quote_id']??('xemm:'.substr(hash('sha256',$observed.'|'.$symbol.'|'.$makerVenue.'|'.$hedgeVenue.'|'.$side),0,32)));
        if(!preg_match('/^[A-Za-z0-9:_-]{1,96}$/D',$quoteId))return ['status'=>'REJECTED','reason'=>'INVALID_QUOTE_ID','execution_mode'=>'PAPER_REPLAY_ONLY'];
        return ['status'=>'POST_CANDIDATE','execution_mode'=>'PAPER_REPLAY_ONLY','live_execution'=>false,
            'signal'=>['action'=>'POST','quote_id'=>$quoteId,'symbol'=>$symbol,'maker_venue'=>$makerVenue,'hedge_venue'=>$hedgeVenue,
                'maker_side'=>$side,'price'=>$makerPrice,'quantity'=>$qty,'maker_fee_rate'=>$makerFee,
                'observed_at_utc'=>$observed,'available_at_utc'=>$available],
            'expected_net_if_filled_usd'=>$netIfFilled,'fill_probability'=>null,'fill_probability_status'=>'REQUIRES_CALIBRATED_HISTORY',
            'costs'=>['maker_fee_usd'=>$makerFeeUsd,'hedge_fee_usd'=>$hedgeFill['fee'],'hedge_slippage_usd'=>$hedgeFill['slippage_cost'],
                'adverse_selection_usd'=>$adverse],'hedge_fill_simulation'=>$hedgeFill,
            'maker_depth_qty'=>$makerDepth,'quantity_limited_by_depth'=>$qty+1e-12<$quantity,
            'post_only_price'=>true,'quote_staleness_seconds'=>(int)($config['max_quote_age_seconds']??5)];
    }

    private function number($value): ?float
    {return is_numeric($value)&&is_finite((float)$value)?(float)$value:null;}
}
