<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

use PDO;
use Prometheus\core\HttpClient;
use Throwable;

/** Public Bybit USDT linear delivery-futures and same-quote spot depth. */
final class DatedFuturesCollector
{
    private HttpClient $http;
    private BasisAnalysisEngine $basis;
    public function __construct(?HttpClient $http=null,?BasisAnalysisEngine $basis=null)
    {$this->http=$http??new HttpClient(8,0);$this->basis=$basis??new BasisAnalysisEngine();}

    public function collect(PDO $db,string $symbol='BTCUSDT',int $contractLimit=5,int $depthLimit=50): array
    {
        if(!preg_match('/^([A-Z0-9]{2,12})(USDT)$/D',$symbol,$match))throw new \InvalidArgumentException('DATED_FUTURES_REQUIRE_USDT_SYMBOL');
        $base=$match[1];$contractLimit=max(1,min(20,$contractLimit));$depthLimit=max(5,min(200,$depthLimit));
        $started=microtime(true);$errors=[];$basisRows=[];$connectors=[];
        try{
            $contracts=$this->instruments($base);$contracts=array_values(array_filter($contracts,static function(array $c):bool{
                return ($c['contractType']??'')==='LinearFutures'&&($c['status']??'')==='Trading'
                    &&($c['quoteCoin']??'')==='USDT'&&is_numeric($c['deliveryTime']??null)&&(int)$c['deliveryTime']>0;
            }));
            usort($contracts,static fn(array $a,array $b):int=>(int)$a['deliveryTime']<=>(int)$b['deliveryTime']);
            $contracts=array_slice($contracts,0,$contractLimit);
            if(!$contracts)throw new \RuntimeException('NO_TRADING_LINEAR_DELIVERY_FUTURES');
            $spot=$this->book($symbol,'spot',$depthLimit);$snapshotAt=gmdate('Y-m-d H:i:s');
            $this->persistBook($db,'BYBIT_SPOT',$symbol,'SPOT',$spot);$this->health($db,'BYBIT','DATED_SPOT','OK',
                (int)round(1000*(microtime(true)-$started)),null);
            foreach($contracts as $contract){
                $futureSymbol=(string)$contract['symbol'];$contractStart=microtime(true);
                try{$future=$this->book($futureSymbol,'linear',$depthLimit);
                    $expiry=gmdate('Y-m-d H:i:s',(int)floor((int)$contract['deliveryTime']/1000));
                    $row=$this->basis->candidate($symbol,'BYBIT_SPOT','BYBIT_DATED',$spot,$future,$expiry,$snapshotAt);
                    if(!$row)throw new \RuntimeException('INVALID_SPOT_FUTURE_BOOK');
                    $row['future_symbol']=$futureSymbol;$row['contract_type']=$contract['contractType'];
                    $row['contract_status']=$contract['status'];$row['contract_qty_step']=$contract['lotSizeFilter']['qtyStep']??null;
                    $row['contract_min_qty']=$contract['lotSizeFilter']['minOrderQty']??null;
                    $basisRows[]=$row;$this->persistBook($db,'BYBIT_DATED',$futureSymbol,'DATED',$future);
                    $this->health($db,'BYBIT','DATED_FUTURES','OK',(int)round(1000*(microtime(true)-$contractStart)),null);
                }catch(Throwable $e){$errors[$futureSymbol]=get_class($e);$this->health($db,'BYBIT','DATED_FUTURES','ERROR',
                    (int)round(1000*(microtime(true)-$contractStart)),get_class($e));}
            }
            if(!$basisRows&&empty($errors))$errors['instrument-info']='NO_TRADABLE_CONTRACTS';
        }catch(Throwable $e){$errors['BYBIT']=get_class($e);$this->health($db,'BYBIT','DATED_FUTURES','ERROR',
            (int)round(1000*(microtime(true)-$started)),get_class($e));}
        $status=$basisRows?($errors?'PARTIAL':'COMPLETE'):'UNAVAILABLE';
        return ['status'=>$status,'symbol'=>$symbol,'snapshot_at'=>$snapshotAt??gmdate('Y-m-d H:i:s'),
            'basis'=>$basisRows,'venues'=>array_values(array_unique(array_column($basisRows,'future_venue'))),
            'errors'=>$errors,'connectors'=>$connectors,'cost_model_complete'=>false];
    }

    private function instruments(string $base): array
    {
        $cursor=null;$contracts=[];
        for($page=0;$page<10;$page++){
            $url='https://api.bybit.com/v5/market/instruments-info?category=linear&baseCoin='.rawurlencode($base).'&limit=1000';
            if($cursor!==null&&$cursor!=='')$url.='&cursor='.rawurlencode($cursor);
            $r=$this->http->getJson($url);if((int)($r['retCode']??-1)!==0)throw new \RuntimeException('BYBIT_INSTRUMENTS_ERROR');
            $result=$r['result']??[];foreach(($result['list']??[]) as $item)if(is_array($item))$contracts[]=$item;
            $cursor=(string)($result['nextPageCursor']??'');if($cursor==='')break;
        }
        return $contracts;
    }

    private function book(string $symbol,string $category,int $limit): array
    {
        $r=$this->http->getJson('https://api.bybit.com/v5/market/orderbook?category='.$category.'&symbol='.rawurlencode($symbol).'&limit='.$limit);
        if((int)($r['retCode']??-1)!==0)throw new \RuntimeException('BYBIT_BOOK_ERROR');$x=$r['result']??[];
        $bids=OrderBookCollector::levels($x['b']??[],false);$asks=OrderBookCollector::levels($x['a']??[],true);
        if(!$bids||!$asks||$bids[0]['price']>$asks[0]['price'])throw new \RuntimeException('INVALID_BYBIT_BOOK');
        return ['bid'=>$bids[0]['price'],'ask'=>$asks[0]['price'],'bid_qty'=>$bids[0]['quantity'],'ask_qty'=>$asks[0]['quantity'],
            'bids'=>$bids,'asks'=>$asks,'observed_at'=>isset($x['ts'])?gmdate('Y-m-d H:i:s',(int)floor((int)$x['ts']/1000)):gmdate('Y-m-d H:i:s'),
            'sequence'=>$x['u']??null,'source'=>'BYBIT_V5_ORDERBOOK'];
    }

    private function persistBook(PDO $db,string $venue,string $symbol,string $marketType,array $book): void
    {
        $db->prepare('INSERT INTO arbitrage_orderbook_snapshots
            (exchange_name,symbol,market_type,bid,ask,bid_qty,ask_qty,observed_at_utc,latency_ms,depth_json)
            VALUES (?,?,?,?,?,?,?,?,NULL,?)')->execute([$venue,$symbol,$marketType,$book['bid'],$book['ask'],$book['bid_qty'],$book['ask_qty'],
                $book['observed_at'],json_encode(['bids'=>$book['bids'],'asks'=>$book['asks'],'source'=>$book['source']],JSON_THROW_ON_ERROR)]);
    }

    private function health(PDO $db,string $venue,string $type,string $status,int $latency,?string $error): void
    {
        $db->prepare('INSERT INTO arbitrage_connector_status
            (exchange_name,connector_type,status,last_attempt_at_utc,last_success_at_utc,latency_ms,last_error_code,details_json)
            VALUES (?,?,?,UTC_TIMESTAMP(6),IF(?="OK",UTC_TIMESTAMP(6),NULL),?,?,JSON_OBJECT())
            ON DUPLICATE KEY UPDATE status=VALUES(status),last_attempt_at_utc=VALUES(last_attempt_at_utc),
            last_success_at_utc=IF(VALUES(status)="OK",VALUES(last_success_at_utc),last_success_at_utc),
            latency_ms=VALUES(latency_ms),last_error_code=VALUES(last_error_code)')
            ->execute([$venue,$type,$status,$status,max(0,$latency),$error]);
    }
}
