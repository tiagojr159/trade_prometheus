<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Prometheus\core\Database;
use Prometheus\core\DirectionPolicy;
use Prometheus\core\AsOfTime;
$rows=Database::fetchAll('SELECT p.horizon,p.created_at,p.probability_up,r.actual_direction FROM predictions p JOIN prediction_results r ON r.prediction_id=p.id WHERE p.symbol=? AND r.evaluation_version=2 AND r.actual_direction IN ("UP","DOWN") ORDER BY p.created_at,p.id',[prometheus_config('default_symbol','BTCUSDT')]);
$matrix=[];$bins=[];$used=0;
foreach($rows as $r){$d=DirectionPolicy::predictFromUp((float)$r['probability_up'])['direction'];if(!in_array($d,['UP','DOWN'],true))continue;AsOfTime::set((string)$r['created_at']);$candles=Database::fetchAll('SELECT close_time,close_price FROM market_data WHERE symbol=? AND interval_name=? AND close_time<=? AND ingested_at IS NOT NULL AND temporal_quality IN ("EXACT","INGESTION_ONLY") AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . ' AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at)) ORDER BY open_time DESC LIMIT 2',['BTCUSDT',prometheus_config('collector.market_interval','1m'),$r['created_at']]);AsOfTime::clear();if(count($candles)<2)continue;$a=$r['actual_direction'];$hit=DirectionPolicy::hit($d,$a);if($hit===null)continue;$matrix[$d][$a]=($matrix[$d][$a]??0)+1;$i=min(9,(int)floor((float)$r['probability_up']*10));$bins[$i]['n']=($bins[$i]['n']??0)+1;$bins[$i]['p']=($bins[$i]['p']??0)+(float)$r['probability_up'];$bins[$i]['up']=($bins[$i]['up']??0)+(int)($a==='UP');$used++;}
echo "CURRENT V2 ALIGNMENT REPORT — lineage filtered; not predictive validation\n";
echo "N=$used\nCONFUSION predicted,actual,n\n";foreach($matrix as $pred=>$actuals)foreach($actuals as $actual=>$n)echo "$pred,$actual,$n\n";echo "CALIBRATION bin,n,mean_p,observed_up\n";foreach($bins as $bin=>$b)printf("%d0-%d0%%,%d,%.4f,%.4f\n",$bin,$bin+1,$b['n'],$b['p']/$b['n'],$b['up']/$b['n']);
