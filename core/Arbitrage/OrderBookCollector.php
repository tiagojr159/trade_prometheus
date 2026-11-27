<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

use PDO;
use Prometheus\core\HttpClient;
use Throwable;

/** Public market-data depth adapters. No private credentials or trading endpoints are used. */
final class OrderBookCollector
{
    private HttpClient $http;
    public function __construct(?HttpClient $http=null){$this->http=$http??new HttpClient(8,0);}

    public function collect(PDO $db,string $symbol='BTCUSDT',int $limit=20): array
    {
        if(!preg_match('/^[A-Z0-9]{3,24}$/D',$symbol))throw new \InvalidArgumentException('INVALID_SYMBOL');
        $limit=max(5,min(100,$limit));$base=preg_replace('/USDT$/','',$symbol);$result=[];$errors=[];
        $venues=['BINANCE_SPOT'=>fn()=>$this->binance($symbol,false,$limit),'BINANCE'=>fn()=>$this->binance($symbol,true,$limit),
            'BYBIT_SPOT'=>fn()=>$this->bybit($symbol,'spot',$limit),'BYBIT'=>fn()=>$this->bybit($symbol,'linear',$limit),
            'OKX_SPOT'=>fn()=>$this->okx($base.'-USDT',$limit),'OKX'=>fn()=>$this->okx($base.'-USDT-SWAP',$limit),
            'BITGET_SPOT'=>fn()=>$this->bitget($symbol,'SPOT',$limit),'BITGET'=>fn()=>$this->bitget($symbol,'USDT-FUTURES',$limit),
            'HYPERLIQUID'=>fn()=>$this->hyperliquid($base)];
        foreach($venues as $venue=>$fetch){$requestStarted=microtime(true);try{$data=$fetch();$data['exchange']=$venue;$data['symbol']=$symbol;
            $data['market_type']=substr($venue,-5)==='_SPOT'?'SPOT':'PERP';
            $data['latency_ms']=(int)round(1000*(microtime(true)-$requestStarted));
            $this->persist($db,$data);$result[$venue]=$data;$this->health($db,$venue,'OK',(int)$data['latency_ms'],null);
        }catch(Throwable $e){$errors[$venue]=get_class($e);$this->health($db,$venue,'ERROR',(int)round(1000*(microtime(true)-$requestStarted)),get_class($e));}}
        return ['status'=>count($result)===count($venues)?'COMPLETE':($result?'PARTIAL':'UNAVAILABLE'),
            'symbol'=>$symbol,'snapshot_at'=>gmdate('Y-m-d H:i:s'),'limit'=>$limit,'books'=>[$symbol=>$result],
            'connectors'=>$result,'errors'=>$errors];
    }

    public static function levels(array $rows,bool $ascending): array
    {
        $levels=[];foreach($rows as $row){$price=(float)($row[0]??$row['px']??0);$qty=(float)($row[1]??$row['sz']??0);
            if(!is_finite($price)||!is_finite($qty)||$price<=0||$qty<=0)continue;$levels[]=['price'=>$price,'quantity'=>$qty];}
        usort($levels,static fn(array $a,array $b):int=>$ascending?($a['price']<=>$b['price']):($b['price']<=>$a['price']));
        return $levels;
    }

    private function binance(string $symbol,bool $perp,int $limit): array
    {
        $host=$perp?'https://fapi.binance.com/fapi/v1/depth':'https://api.binance.com/api/v3/depth';
        $r=$this->http->getJson($host.'?symbol='.rawurlencode($symbol).'&limit='.$limit);
        return $this->book($r['bids']??[],$r['asks']??[],null,'EXCHANGE_SEQUENCE');
    }
    private function bybit(string $symbol,string $category,int $limit): array
    {
        $r=$this->http->getJson('https://api.bybit.com/v5/market/orderbook?category='.$category.'&symbol='.rawurlencode($symbol).'&limit='.$limit);
        if((int)($r['retCode']??-1)!==0)throw new \RuntimeException('BYBIT_RESPONSE_ERROR');$x=$r['result']??[];
        return $this->book($x['b']??[],$x['a']??[],$x['ts']??null,'BYBIT_SNAPSHOT');
    }
    private function okx(string $instId,int $limit): array
    {
        $r=$this->http->getJson('https://www.okx.com/api/v5/market/books?instId='.rawurlencode($instId).'&sz='.$limit);
        if(($r['code']??null)!=='0')throw new \RuntimeException('OKX_RESPONSE_ERROR');$x=$r['data'][0]??[];
        return $this->book($x['bids']??[],$x['asks']??[],$x['ts']??null,'OKX_SNAPSHOT');
    }
    private function bitget(string $symbol,string $category,int $limit): array
    {
        $r=$this->http->getJson('https://api.bitget.com/api/v3/market/orderbook?category='.$category.'&symbol='.rawurlencode($symbol).'&limit='.$limit);
        if(($r['code']??null)!=='00000')throw new \RuntimeException('BITGET_RESPONSE_ERROR');$x=$r['data']??[];
        return $this->book($x['b']??[],$x['a']??[],$x['ts']??null,'BITGET_SNAPSHOT');
    }
    private function hyperliquid(string $coin): array
    {
        $r=$this->http->postJson('https://api.hyperliquid.xyz/info',['type'=>'l2Book','coin'=>$coin]);
        if(!is_array($r['levels'][0]??null)||!is_array($r['levels'][1]??null))throw new \RuntimeException('HYPERLIQUID_RESPONSE_ERROR');
        return $this->book($r['levels'][0],$r['levels'][1],$r['time']??null,'HYPERLIQUID_L2_SNAPSHOT');
    }
    private function book(array $bids,array $asks,$timestamp,string $source): array
    {
        $b=self::levels($bids,false);$a=self::levels($asks,true);if(!$b||!$a)throw new \RuntimeException('EMPTY_ORDER_BOOK');
        $bid=$b[0]['price'];$ask=$a[0]['price'];if($bid>$ask)throw new \RuntimeException('CROSSED_ORDER_BOOK');
        $observed=is_numeric($timestamp)&&($ts=gmdate('Y-m-d H:i:s',(int)floor((float)$timestamp/1000)))?$ts:gmdate('Y-m-d H:i:s');
        return ['bid'=>$bid,'ask'=>$ask,'bid_qty'=>$b[0]['quantity'],'ask_qty'=>$a[0]['quantity'],
            'bids'=>$b,'asks'=>$a,'depth'=>['bids'=>$b,'asks'=>$a,'source'=>$source],'observed_at'=>$observed,'source'=>$source];
    }
    private function persist(PDO $db,array $book): void
    {
        $db->prepare('INSERT INTO arbitrage_orderbook_snapshots
            (exchange_name,symbol,market_type,bid,ask,bid_qty,ask_qty,observed_at_utc,latency_ms,depth_json)
            VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$book['exchange'],$book['symbol'],$book['market_type'],$book['bid'],$book['ask'],
            $book['bid_qty'],$book['ask_qty'],$book['observed_at'],$book['latency_ms'],json_encode($book['depth'],JSON_THROW_ON_ERROR)]);
    }
    private function health(PDO $db,string $venue,string $status,int $latency,?string $error): void
    {
        $db->prepare('INSERT INTO arbitrage_connector_status
            (exchange_name,connector_type,status,last_attempt_at_utc,last_success_at_utc,latency_ms,last_error_code,details_json)
            VALUES (?,"ORDERBOOK",?,UTC_TIMESTAMP(6),IF(?="OK",UTC_TIMESTAMP(6),NULL),?,?,JSON_OBJECT())
            ON DUPLICATE KEY UPDATE status=VALUES(status),last_attempt_at_utc=VALUES(last_attempt_at_utc),
            last_success_at_utc=IF(VALUES(status)="OK",VALUES(last_success_at_utc),last_success_at_utc),
            latency_ms=VALUES(latency_ms),last_error_code=VALUES(last_error_code)')
            ->execute([$venue,$status,$status,$latency,$error]);
    }
}
