<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

use PDO;
use Prometheus\core\HttpClient;
use Throwable;

/** Read-only GeckoTerminal pool observations; it never submits swaps. */
final class DexPoolCollector
{
    private HttpClient $http;
    public function __construct(?HttpClient $http=null){$this->http=$http??new HttpClient(10,0);}

    public static function configuredPools(): array
    {
        $json=getenv('PROMETHEUS_DEX_POOLS');if(!is_string($json)||trim($json)==='')return [];
        $pools=json_decode($json,true);if(!is_array($pools)||array_values($pools)!==$pools)throw new \RuntimeException('INVALID_PROMETHEUS_DEX_POOLS_JSON');
        return $pools;
    }

    public function collect(PDO $db,array $pools): array
    {
        if($pools===[])return ['status'=>'NOT_CONFIGURED','pools'=>[],'errors'=>[]];
        $results=[];$errors=[];$configuredCount=count($pools);$truncated=$configuredCount>8;$pools=array_slice($pools,0,8);
        foreach($pools as $pool){$network=(string)($pool['network']??'');$address=(string)($pool['pool_address']??'');
            if(!preg_match('/^[A-Za-z0-9_-]{1,48}$/D',$network)||!preg_match('/^[A-Za-z0-9_-]{8,128}$/D',$address)){
                $errors[]=['pool'=>substr($network.':'.$address,0,160),'error'=>'INVALID_POOL_CONFIGURATION'];continue;}
            $started=microtime(true);$key=$network.':'.$address;
            try{$response=$this->http->getJson('https://api.geckoterminal.com/api/v2/networks/'.rawurlencode($network).'/pools/'.rawurlencode($address).'?include=base_token,quote_token,dex',
                    ['Accept'=>'application/json;version=20230203']);
                $normalized=self::normalizePool($pool,$response);$this->persist($db,$normalized);
                $results[]=$normalized;$this->health($db,'OK',(int)round(1000*(microtime(true)-$started)),null);
            }catch(Throwable $e){$errors[]=['pool'=>$key,'error'=>get_class($e)];
                $this->health($db,'ERROR',(int)round(1000*(microtime(true)-$started)),get_class($e));}
        }
        return ['status'=>$errors?($results?'PARTIAL':'UNAVAILABLE'):($truncated?'PARTIAL':'COMPLETE'),
            'pools'=>$results,'errors'=>$errors,'configured_count'=>$configuredCount,'request_limit_applied'=>$truncated?8:null,
            'source'=>'GECKOTERMINAL_PUBLIC_API','source_cache_max_age_seconds'=>60,'execution_mode'=>'OBSERVATION_ONLY'];
    }

    public static function normalizePool(array $configured,array $response): array
    {
        $data=$response['data']??[];$a=$data['attributes']??[];$relationships=$data['relationships']??[];$included=[];
        if(($data['type']??null)!=='pool'||!is_array($a)||!isset($a['address']))throw new \RuntimeException('INVALID_DEX_POOL_RESPONSE');
        $returnedAddress=(string)$a['address'];$expectedAddress=(string)$configured['pool_address'];
        $samePool=preg_match('/^0x[0-9a-f]{40}$/iD',$returnedAddress)&&preg_match('/^0x[0-9a-f]{40}$/iD',$expectedAddress)
            ?strtolower($returnedAddress)===strtolower($expectedAddress):$returnedAddress===$expectedAddress;
        if(!$samePool)throw new \RuntimeException('DEX_POOL_ADDRESS_MISMATCH');
        foreach(($response['included']??[]) as $row)if(isset($row['id']))$included[$row['id']]=$row['attributes']??[];
        $token=static function(string $name)use($relationships,$included):array{
            $id=(string)($relationships[$name]['data']['id']??'');return ['id'=>$id,'attributes'=>$included[$id]??[]];};
        $base=$token('base_token');$quote=$token('quote_token');$dex=$relationships['dex']['data']['id']??null;
        $num=static function($v, bool $positive=false, bool $nonnegative=false):?float{return is_numeric($v)&&is_finite((float)$v)
            &&(!$positive||(float)$v>0)&&(!$nonnegative||(float)$v>=0)?(float)$v:null;};
        $reserve=$num($a['reserve_in_usd']??null,false,true);$basePrice=$num($a['base_token_price_usd']??null,true);
        $quotePrice=$num($a['quote_token_price_usd']??null,true);$baseBalance=$num($a['base_token_balance']??null,false,true);
        $quoteBalance=$num($a['quote_token_balance']??null,false,true);$volume=$num($a['volume_usd']['h24']??null,false,true);
        $address=(string)$a['address'];
        return ['network'=>(string)$configured['network'],'amm_model'=>strtoupper((string)($configured['amm_model']??'')),'dex_name'=>is_string($dex)?$dex:null,'pool_address'=>$address,
            'pool_label'=>is_string($a['name']??null)?$a['name']:($configured['label']??null),
            'base_token_address'=>$base['attributes']['address']??null,'base_token_symbol'=>$base['attributes']['symbol']??null,
            'quote_token_address'=>$quote['attributes']['address']??null,'quote_token_symbol'=>$quote['attributes']['symbol']??null,
            'base_token_price_usd'=>$basePrice,'quote_token_price_usd'=>$quotePrice,'base_token_balance'=>$baseBalance,
            'quote_token_balance'=>$quoteBalance,'reserve_usd'=>$reserve,'volume_24h_usd'=>$volume,
            'observed_at_utc'=>gmdate('Y-m-d H:i:s'),'source'=>'GECKOTERMINAL',
            'details'=>['pool_id'=>$data['id']??null,'pool_created_at'=>$a['pool_created_at']??null,
                'base_token_price_quote_token'=>$num($a['base_token_price_quote_token']??null,true),
                'pool_fee_percentage'=>$num($a['pool_fee_percentage']??null,false,true),'price_change_percentage'=>$a['price_change_percentage']??null,
                'data_quality'=>($basePrice!==null&&$quotePrice!==null&&$reserve!==null&&$baseBalance!==null&&$quoteBalance!==null),
                'raw_response_hash'=>hash('sha256',json_encode($response,JSON_THROW_ON_ERROR))]];
    }

    private function persist(PDO $db,array $row): void
    {
        $db->prepare('INSERT INTO arbitrage_dex_pool_snapshots
            (network,dex_name,pool_address,pool_label,base_token_address,base_token_symbol,quote_token_address,quote_token_symbol,
             base_token_price_usd,quote_token_price_usd,base_token_balance,quote_token_balance,reserve_usd,volume_24h_usd,
             observed_at_utc,source,details_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$row['network'],$row['dex_name'],$row['pool_address'],$row['pool_label'],$row['base_token_address'],$row['base_token_symbol'],
                $row['quote_token_address'],$row['quote_token_symbol'],$row['base_token_price_usd'],$row['quote_token_price_usd'],
                $row['base_token_balance'],$row['quote_token_balance'],$row['reserve_usd'],$row['volume_24h_usd'],$row['observed_at_utc'],
                $row['source'],json_encode($row['details'],JSON_THROW_ON_ERROR)]);
    }

    private function health(PDO $db,string $status,int $latency,?string $error): void
    {
        $db->prepare('INSERT INTO arbitrage_connector_status
            (exchange_name,connector_type,status,last_attempt_at_utc,last_success_at_utc,latency_ms,last_error_code,details_json)
            VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),last_attempt_at_utc=VALUES(last_attempt_at_utc),
            last_success_at_utc=COALESCE(VALUES(last_success_at_utc),last_success_at_utc),latency_ms=VALUES(latency_ms),
            last_error_code=VALUES(last_error_code),details_json=VALUES(details_json)')
            ->execute(['GECKOTERMINAL','DEX',$status,gmdate('Y-m-d H:i:s'),$status==='OK'?gmdate('Y-m-d H:i:s'):null,$latency,$error,
                json_encode(['mode'=>'OBSERVATION_ONLY'],JSON_THROW_ON_ERROR)]);
    }
}
