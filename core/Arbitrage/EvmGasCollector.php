<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

use PDO;
use Prometheus\core\HttpClient;
use Throwable;

/** Read-only eth_gasPrice observations from operator-configured JSON-RPC endpoints. */
final class EvmGasCollector
{
    private HttpClient $http;
    public function __construct(?HttpClient $http=null){$this->http=$http??new HttpClient(10,0);}

    public static function configuredEndpoints(): array
    {
        $json=getenv('PROMETHEUS_EVM_RPC_ENDPOINTS');if(!is_string($json)||trim($json)==='')return [];
        $endpoints=json_decode($json,true);if(!is_array($endpoints))throw new \RuntimeException('INVALID_PROMETHEUS_EVM_RPC_ENDPOINTS_JSON');
        return $endpoints;
    }

    public function collect(PDO $db,array $endpoints): array
    {
        if($endpoints===[])return ['status'=>'NOT_CONFIGURED','gas'=>[],'errors'=>[]];
        $results=[];$errors=[];$truncated=count($endpoints)>8;$endpoints=array_slice($endpoints,0,8,true);
        foreach($endpoints as $chain=>$url){$chain=(string)$chain;$url=(string)$url;
            if(!preg_match('/^[A-Za-z0-9_-]{1,48}$/D',$chain)||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'){
                $errors[$chain?:'unknown']='INVALID_RPC_CONFIGURATION';continue;}
            $started=microtime(true);
            try{$response=$this->http->postJson($url,['jsonrpc'=>'2.0','id'=>1,'method'=>'eth_gasPrice','params'=>[]]);
                $hex=$response['result']??null;if(isset($response['error'])||!is_string($hex)||!preg_match('/^0x[0-9a-f]+$/iD',$hex))
                    throw new \RuntimeException('INVALID_EVM_GAS_RESPONSE');
                $wei=self::hexToDecimal(substr($hex,2));$observed=gmdate('Y-m-d H:i:s');
                $hash=hash('sha256',strtolower($hex));$db->prepare('INSERT INTO arbitrage_chain_gas_snapshots
                    (chain_name,gas_price_wei,observed_at_utc,source,response_hash) VALUES (?,?,?,?,?)')
                    ->execute([$chain,$wei,$observed,'EVM_JSON_RPC',$hash]);
                $results[$chain]=['gas_price_wei'=>$wei,'observed_at_utc'=>$observed,'source'=>'EVM_JSON_RPC'];
                $this->health($db,'OK',(int)round(1000*(microtime(true)-$started)),null);
            }catch(Throwable $e){$errors[$chain]=get_class($e);$this->health($db,'ERROR',(int)round(1000*(microtime(true)-$started)),get_class($e));}
        }
        return ['status'=>$errors?($results?'PARTIAL':'UNAVAILABLE'):($truncated?'PARTIAL':'COMPLETE'),
            'gas'=>$results,'errors'=>$errors,'request_limit_applied'=>$truncated?8:null,'execution_mode'=>'OBSERVATION_ONLY'];
    }

    public static function hexToDecimal(string $hex): string
    {
        $decimal='0';foreach(str_split(strtolower($hex)) as $char){$digit=hexdec($char);$carry=$digit;$out='';
            for($i=strlen($decimal)-1;$i>=0;$i--){$value=((int)$decimal[$i])*16+$carry;$out=($value%10).$out;$carry=intdiv($value,10);}
            while($carry>0){$out=($carry%10).$out;$carry=intdiv($carry,10);}$decimal=ltrim($out,'0')?:'0';}
        return $decimal;
    }

    private function health(PDO $db,string $status,int $latency,?string $error): void
    {
        $db->prepare('INSERT INTO arbitrage_connector_status
            (exchange_name,connector_type,status,last_attempt_at_utc,last_success_at_utc,latency_ms,last_error_code,details_json)
            VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),last_attempt_at_utc=VALUES(last_attempt_at_utc),
            last_success_at_utc=COALESCE(VALUES(last_success_at_utc),last_success_at_utc),latency_ms=VALUES(latency_ms),
            last_error_code=VALUES(last_error_code),details_json=VALUES(details_json)')
            ->execute(['EVM_RPC','GAS',$status,gmdate('Y-m-d H:i:s'),$status==='OK'?gmdate('Y-m-d H:i:s'):null,$latency,$error,
                json_encode(['mode'=>'OBSERVATION_ONLY'],JSON_THROW_ON_ERROR)]);
    }
}
