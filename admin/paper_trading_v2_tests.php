<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/StrategyDecisionEngine.php';
require_once dirname(__DIR__) . '/core/PaperTrader.php';
require_once dirname(__DIR__) . '/prediction/Athena50Shadow.php';

use Prometheus\core\StrategyDecisionEngine;
use Prometheus\core\PaperTrader;
use Prometheus\prediction\Athena50Shadow;

function ptCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo "OK: {$message}\n";
}

function ptPrediction(string $direction, float $up, float $confidence = 0.8): array
{
    return ['id'=>1,'created_at'=>date('Y-m-d H:i:s',time()-5),'horizon'=>'15m','predicted_direction'=>$direction,
        'probability_up'=>$up,'probability_down'=>1-$up,'edge'=>abs($up-.5),'confidence'=>$confidence];
}

$engine = new StrategyDecisionEngine();
$config = ['allow_short'=>1,'reversal_policy'=>'CLOSE_REVERSE','min_confidence'=>.2,'fee_pct'=>.1,'slippage_pct'=>.02,'min_edge_pct'=>.03];
$flat = ['position_side'=>'FLAT'];

$up = ptPrediction('UP',.70);
$down = ptPrediction('DOWN',.30);
ptCheck($engine->decide('DIRECTIONAL',$up,[],$flat,$config,[],true)['action']==='OPEN_LONG','FLAT + UP abre LONG sintético');
ptCheck($engine->decide('DIRECTIONAL',$down,[],$flat,$config,[],true)['action']==='OPEN_SHORT','FLAT + DOWN abre SHORT sintético');
ptCheck($engine->decide('DIRECTIONAL',$down,[],['position_side'=>'LONG'],$config,[],true)['action']==='CLOSE_LONG+OPEN_SHORT','LONG + DOWN fecha e aplica reversão configurada');
ptCheck($engine->decide('DIRECTIONAL',$up,[],['position_side'=>'SHORT'],$config,[],true)['action']==='CLOSE_SHORT+OPEN_LONG','SHORT + UP fecha e aplica reversão configurada');
ptCheck($engine->decide('DIRECTIONAL',$up,[],$flat,$config,[],true)['action']==='OPEN_LONG','repetição lógica é determinística (idempotência é garantida pelo índice SQL)');
$indeterminate=ptPrediction('INDETERMINATE',.5);
ptCheck($engine->decide('DIRECTIONAL',$indeterminate,[],$flat,$config,[],true)['action']==='INDETERMINATE','INDETERMINATE não abre posição');
ptCheck($engine->decide('DIRECTIONAL',$up,[],$flat,$config,[],false)['reason_codes']===['STALE_DATA'],'sinal sem dados frescos é rejeitado');
$future=$up; $future['created_at']=date('Y-m-d H:i:s',time()+600);
ptCheck($engine->decide('DIRECTIONAL',$future,[],$flat,$config,[],true)['reason_codes']===['FUTURE_PREDICTION'],'previsão com created_at futuro é rejeitada');

$horizons=['15m'=>ptPrediction('UP',.90),'1h'=>ptPrediction('UP',.85)];
$evidence=['n'=>40,'avg_abs_return_pct'=>1.0];
$strategy=$engine->decide('STRATEGY',$up,$horizons,$flat,$config,$evidence,true);
ptCheck($strategy['action']==='OPEN_LONG' && $strategy['expected_edge_pct']>$strategy['estimated_cost_pct'],'STRATEGY opera somente com confirmação e expectativa acima dos custos');
$disagree=$horizons; $disagree['1h']=ptPrediction('DOWN',.2);
ptCheck($engine->decide('STRATEGY',$up,$disagree,$flat,$config,$evidence,true)['action']==='NO_TRADE','discordância entre horizontes resulta em NO_TRADE');
ptCheck($engine->decide('STRATEGY',$up,$horizons,$flat,$config,['n'=>10,'avg_abs_return_pct'=>1.0],true)['reason_codes']===['INSUFFICIENT_HISTORY'],'amostra histórica insuficiente não abre trade');
$highCost=$config; $highCost['fee_pct']=1.0; $highCost['slippage_pct']=.5;
ptCheck($engine->decide('STRATEGY',$up,$horizons,$flat,$highCost,$evidence,true)['reason_codes']===['COST_TOO_HIGH'],'cenário de custo alto fica fora');

$schema=(string)file_get_contents(dirname(__DIR__).'/sql/migrations/011_paper_trading_v2.sql');
$underwaterShort=['position_side'=>'SHORT','cash_balance'=>74.9,'reserved_cash'=>25.0,'entry_price'=>100.0,'quantity_btc'=>1.0];
ptCheck(PaperTrader::markedEquity($underwaterShort,300.0)===0.0,'equity sintética nunca fica negativa após perda extrema em SHORT');
$healthyLong=['position_side'=>'LONG','cash_balance'=>75.0,'reserved_cash'=>0.0,'entry_price'=>100.0,'quantity_btc'=>.25];
ptCheck(abs(PaperTrader::markedEquity($healthyLong,100.0)-100.0)<.000001,'equity LONG soma cash e posição marcada a mercado');
$simulation=(string)file_get_contents(dirname(__DIR__).'/admin/simulation.php');
ptCheck(strpos($simulation,'$heartbeatAge>300')!==false&&strpos($simulation,"'ATRASADO'")!==false,'tela sinaliza cron parado há mais de cinco minutos');
ptCheck(strpos($schema,'UNIQUE KEY uniq_paper_mode_prediction (mode, prediction_id)')!==false,'schema impede decisão duplicada por modo e prediction_id');
$cutoff='2026-09-28 12:00:00';$cutoffUtc='2026-09-28 15:00:00';
$candle=['close_time'=>'2026-09-28 11:59:00','ingested_at'=>'2026-09-28 14:59:00','available_at'=>'2026-09-28 14:58:00'];
$rows=Athena50Shadow::filterPointInTimeRows([$candle,array_replace($candle,['close_time'=>'2026-09-28 12:01:00']),array_replace($candle,['ingested_at'=>'2026-09-28 15:01:00']),array_replace($candle,['available_at'=>'2026-09-28 15:01:00'])],$cutoff,$cutoffUtc);
ptCheck(count($rows)===1,'candle aberto, ingestão futura e disponibilidade futura são rejeitados no shadow');
