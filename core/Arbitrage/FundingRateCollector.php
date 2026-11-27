<?php
declare(strict_types=1);

namespace Prometheus\core\Arbitrage;

use PDO;
use Prometheus\core\HttpClient;
use Throwable;

/** Public market-data adapters only. This class has no private API or trading methods. */
final class FundingRateCollector
{
    private HttpClient $http;
    public function __construct(?HttpClient $http=null) { $this->http=$http??new HttpClient(8,0); }

    public function collect(PDO $db,string $symbol='BTCUSDT'): array
    {
        if (!preg_match('/^[A-Z0-9]{3,24}$/D',$symbol)) throw new \InvalidArgumentException('INVALID_SYMBOL');
        $now=gmdate('Y-m-d H:i:s');$results=[];$errors=[];
        $collectors=['BINANCE'=>fn()=>$this->binance($symbol),'BYBIT'=>fn()=>$this->bybit($symbol),
            'OKX'=>fn()=>$this->okx($symbol),'BITGET'=>fn()=>$this->bitget($symbol),'HYPERLIQUID'=>fn()=>$this->hyperliquid($symbol)];
        foreach($collectors as $venue=>$fetch){
            $started=microtime(true);try{$row=$fetch();if(!$row)throw new \RuntimeException('RATE_UNAVAILABLE');
                $row['exchange']=$venue;$row['symbol']=$symbol;$row['observed_at']=$now;
                $this->persist($db,$row);$results[$venue]=$row;$this->health($db,$venue,'OK',(int)round(1000*(microtime(true)-$started)),null);
            }catch(Throwable $e){$errors[$venue]=get_class($e);$this->health($db,$venue,'ERROR',(int)round(1000*(microtime(true)-$started)),get_class($e));}
        }
        return ['status'=>count($results)===count($collectors)?'COMPLETE':($results?'PARTIAL':'UNAVAILABLE'),'symbol'=>$symbol,
            'snapshot_at'=>$now,'observed_at_utc'=>$now,'venues'=>array_keys($results),'errors'=>$errors,'funding'=>[$symbol=>$results]];
    }

    private function binance(string $symbol): array
    {
        $premium=$this->http->getJson('https://fapi.binance.com/fapi/v1/premiumIndex?symbol='.rawurlencode($symbol));
        $history=$this->http->getJson('https://fapi.binance.com/fapi/v1/fundingRate?symbol='.rawurlencode($symbol).'&limit=2');
        $hours=8.0;
        if(is_array($history)&&count($history)>=2){$times=array_map(static fn($r)=>(int)($r['fundingTime']??0),$history);$delta=abs($times[0]-$times[1])/3600000;if($delta>0&&$delta<=24)$hours=$delta;}
        return $this->normalized($premium['lastFundingRate']??null,$hours,$premium['markPrice']??null,$premium['nextFundingTime']??null,'LAST_SETTLED_RATE');
    }

    private function bybit(string $symbol): array
    {
        $r=$this->http->getJson('https://api.bybit.com/v5/market/tickers?category=linear&symbol='.rawurlencode($symbol));
        $x=$r['result']['list'][0]??[];
        return $this->normalized($x['fundingRate']??null,((float)($x['fundingIntervalHour']??0))?:null,$x['markPrice']??null,$x['nextFundingTime']??null,'CURRENT_ESTIMATE');
    }

    private function okx(string $symbol): array
    {
        $instId=substr($symbol,0,-4).'-USDT-SWAP';
        $r=$this->http->getJson('https://www.okx.com/api/v5/public/funding-rate?instId='.rawurlencode($instId));
        if(($r['code']??null)!=='0')throw new \RuntimeException('OKX_RESPONSE_ERROR');$x=$r['data'][0]??[];
        $from=(int)($x['fundingTime']??0);$to=(int)($x['nextFundingTime']??0);$hours=$from>0&&$to>$from?($to-$from)/3600000:null;
        return $this->normalized($x['fundingRate']??null,$hours,null,$x['nextFundingTime']??null,'CURRENT_ESTIMATE');
    }

    private function bitget(string $symbol): array
    {
        $r=$this->http->getJson('https://api.bitget.com/api/v3/market/current-fund-rate?category=USDT-FUTURES&symbol='.rawurlencode($symbol));
        if(($r['code']??null)!=='00000')throw new \RuntimeException('BITGET_RESPONSE_ERROR');$x=$r['data'][0]??[];
        return $this->normalized($x['fundingRate']??null,$x['fundingRateInterval']??null,null,$x['nextUpdate']??null,'CURRENT_ESTIMATE');
    }

    private function hyperliquid(string $symbol): array
    {
        $coin=preg_replace('/USDT$/','',$symbol);
        $r=$this->http->postJson('https://api.hyperliquid.xyz/info',['type'=>'metaAndAssetCtxs']);
        if(!is_array($r[0]['universe']??null)||!is_array($r[1]??null))throw new \RuntimeException('HYPERLIQUID_RESPONSE_ERROR');
        foreach($r[0]['universe'] as $i=>$asset){if(($asset['name']??null)===$coin){$ctx=$r[1][$i]??[];
            return $this->normalized($ctx['funding']??null,1,$ctx['markPx']??null,null,'HOURLY_CURRENT_RATE');}}
        throw new \RuntimeException('HYPERLIQUID_ASSET_NOT_FOUND');
    }

    private function normalized($rate,$hours,$mark,$next,string $kind): array
    {
        if(!is_numeric($rate)||!is_numeric($hours)||(float)$hours<=0||!is_finite((float)$rate))throw new \RuntimeException('INVALID_FUNDING_DATA');
        return ['rate'=>(float)$rate,'interval_hours'=>(float)$hours,'mark'=>is_numeric($mark)?(float)$mark:null,
            'next_funding_at'=>$this->utcMillis($next),'source'=>$kind,'observed_at'=>gmdate('Y-m-d H:i:s')];
    }

    private function utcMillis($value): ?string
    {
        if(!is_numeric($value)||(float)$value<=0)return null;$seconds=(int)floor((float)$value/1000);
        return gmdate('Y-m-d H:i:s',$seconds);
    }

    private function persist(PDO $db,array $row): void
    {
        $hash=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
        $db->prepare('INSERT INTO arbitrage_funding_rate_history
            (exchange_name,symbol,funding_rate,interval_hours,mark_price,next_funding_at_utc,observed_at_utc,source,raw_hash)
            VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE funding_rate=VALUES(funding_rate),interval_hours=VALUES(interval_hours),
            mark_price=VALUES(mark_price),next_funding_at_utc=VALUES(next_funding_at_utc),source=VALUES(source),raw_hash=VALUES(raw_hash)')
            ->execute([$row['exchange'],$row['symbol'],$row['rate'],$row['interval_hours'],$row['mark'],$row['next_funding_at'],
                $row['observed_at'],$row['source'],$hash]);
    }
    private function health(PDO $db,string $venue,string $status,int $latency,?string $error): void
    {
        $db->prepare('INSERT INTO arbitrage_connector_status
            (exchange_name,connector_type,status,last_attempt_at_utc,last_success_at_utc,latency_ms,last_error_code,details_json)
            VALUES (?,"FUNDING",?,UTC_TIMESTAMP(6),IF(?="OK",UTC_TIMESTAMP(6),NULL),?,?,JSON_OBJECT())
            ON DUPLICATE KEY UPDATE status=VALUES(status),last_attempt_at_utc=VALUES(last_attempt_at_utc),
            last_success_at_utc=IF(VALUES(status)="OK",VALUES(last_success_at_utc),last_success_at_utc),
            latency_ms=VALUES(latency_ms),last_error_code=VALUES(last_error_code)')
            ->execute([$venue,$status,$status,$latency,$error]);
    }
}
