<?php
declare(strict_types=1);

namespace Prometheus\core\Arbitrage;

use PDO;
use Throwable;

final class StrategyEngine
{
    private PDO $db;
    private array $strategies;
    private const REGISTRY = [
        FundingRateArbitrage::class, CashAndCarry::class, CrossExchangeMarketMaking::class,
        StatisticalArbitrage::class, SpatialArbitrage::class, TriangularArbitrage::class, MevArbitrage::class,
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->strategies = array_map(static fn(string $class): TradingStrategy => new $class(), self::REGISTRY);
    }

    public function scan(array $snapshot, array $limits = []): array
    {
        $this->db->beginTransaction();
        try {
            $now = gmdate('Y-m-d H:i:s');
            $observedText = (string)($snapshot['snapshot_at'] ?? '');
            $observed = $observedText === '' ? false : strtotime($observedText . ' UTC');
            $maxAge = max(1, (int)($limits['max_data_age_seconds'] ?? 30));
            $snapshot['stale'] = $observed === false || $observed > time() + 5 || time() - $observed > $maxAge
                || $this->hasStaleComponents($snapshot,$maxAge);
            $this->persistSnapshot($snapshot, $now);
            $asOf=$snapshot['snapshot_at']??$now;
            foreach(($snapshot['funding']??[]) as $symbol=>$venues) foreach($venues as $venue=>$unused) {
                $snapshot['funding_history'][$symbol][$venue]=FundingRateAnalytics::summarize($this->db,(string)$symbol,(string)$venue,(string)$asOf);
            }
            $regime=(new MarketRegimeDetector())->detect($snapshot);
            if(empty($snapshot['regime'])||$snapshot['regime']==='UNKNOWN')$snapshot['regime']=$regime['regime'];
            $this->db->prepare('INSERT INTO arbitrage_market_regimes (regime,confidence,observed_at_utc,details_json)
                VALUES (?,?,?,?)')->execute([$snapshot['regime'] ?? 'UNKNOWN', null,
                $snapshot['snapshot_at'] ?? $now, json_encode(['metrics'=>$regime['metrics'],'source'=>'local_ewma_rules'], JSON_THROW_ON_ERROR)]);
            $regimeId = (int)$this->db->lastInsertId();
            $candidates = [];
            foreach ($this->strategies as $strategy) {
                foreach ($strategy->generateSignals($snapshot) as $signal) {
                    $profit = $strategy->estimateProfit($signal);
                    $signal = array_merge($signal, $profit);
                    $signal['stale'] = $snapshot['stale'];
                    $risk = (new RiskManagementEngine())->evaluate($signal, $limits, $limits['portfolio_state']??[]);
                    if(!empty($signal['venue_exposures_pct']))$signal['details']['venue_exposures_pct']=$signal['venue_exposures_pct'];
                    $signal['risk'] = $risk;
                    $signal['status'] = $risk['approved'] ? 'OBSERVED' : 'REJECTED';
                    $signal['rejection_reasons'] = $risk['reasons'];
                    $signal['execution'] = $risk['approved']
                        ? $strategy->simulateExecution($signal, $snapshot)
                        : ['mode'=>'SIMULATION_ONLY','status'=>'BLOCKED_BY_RISK','fillable'=>false,
                            'reasons'=>$risk['reasons'],'live_trading_enabled'=>false];
                    $candidates[] = $signal;
                }
            }
            $oosSharpes=$this->loadComparableBacktestSharpes();foreach($candidates as &$candidate){$key=$candidate['strategy']??'';
                if(isset($oosSharpes[$key])&&!isset($candidate['details']['historical_sharpe'])){
                    $candidate['details']['historical_sharpe']=$oosSharpes[$key]['sharpe'];
                    $candidate['details']['historical_sharpe_source']='SYNCHRONIZED_OUT_OF_SAMPLE_BACKTEST';
                    $candidate['details']['historical_sharpe_test_window']=$oosSharpes[$key]['test_window'];}}
            unset($candidate);
            $scores = $this->rankCandidates($candidates,$limits['score_weights']??[]);
            $stmt = $this->db->prepare('INSERT INTO arbitrage_opportunities
                (strategy_key,symbol,action,status,observed_at_utc,gross_profit,costs,net_profit,score,capital_pct,
                 exchange_exposure_pct,data_quality,rejection_reasons_json,details_json,regime_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($candidates as $i => $candidate) {
                $stmt->execute([$candidate['strategy'],$candidate['symbol'] ?? '',$candidate['action'],$candidate['status'],
                    $snapshot['snapshot_at'] ?? $now,$candidate['gross_profit'],$candidate['costs'],$candidate['net_profit'],
                    $scores[$i]['score'],$candidate['capital_pct'] ?? 0,$candidate['exchange_exposure_pct'] ?? 0,
                    !empty($candidate['data_quality']) ? 1 : 0,json_encode($candidate['rejection_reasons'],JSON_THROW_ON_ERROR),
                    json_encode(['details'=>$candidate['details'] ?? [],'ranking'=>$scores[$i]['components']??[],
                        'execution'=>$candidate['execution'] ?? []],JSON_THROW_ON_ERROR),$regimeId]);
                $opportunityId=(int)$this->db->lastInsertId();
                $this->db->prepare('INSERT INTO arbitrage_execution_events
                    (strategy_key,opportunity_id,event_key,event_type,execution_mode,details_json,occurred_at_utc)
                    VALUES (?,?,?,?,?,?,?)')->execute([$candidate['strategy'],$opportunityId,
                    'scan:'.$opportunityId.':simulation','SIMULATION_RESULT','SIMULATION',
                    json_encode($candidate['execution'] ?? [],JSON_THROW_ON_ERROR),$now]);
                if ($candidate['rejection_reasons']) {
                    $risk=$this->db->prepare('INSERT INTO arbitrage_risk_events
                        (strategy_key,opportunity_id,event_type,reason_code,details_json,created_at_utc) VALUES (?,?,?,?,?,?)');
                    foreach($candidate['rejection_reasons'] as $reason) $risk->execute([$candidate['strategy'],$opportunityId,
                        'OPPORTUNITY_REJECTED',$reason,json_encode($candidate['details']??[],JSON_THROW_ON_ERROR),$now]);
                }
            }
            $this->db->commit();
            foreach ($candidates as $i => &$candidate){$candidate['score']=$scores[$i]['score'];$candidate['score_components']=$scores[$i]['components'];}
            unset($candidate);
            usort($candidates, static fn(array $a,array $b): int => $b['score'] <=> $a['score']);
            return ['status'=>'COMPLETED','mode'=>'PAPER_AND_OBSERVATION_ONLY','scanned_at_utc'=>$now,'count'=>count($candidates),'opportunities'=>$candidates];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function rankCandidates(array $rows,array $configuredWeights=[]): array
    {
        if (!$rows) return [];
        $weights=array_merge(['expected_return'=>0.30,'probability'=>0.20,'liquidity'=>0.15,'persistence'=>0.10,
            'sharpe'=>0.15,'data_quality'=>0.10,'risk_penalty'=>0.10],$configuredWeights);
        foreach($weights as $key=>$weight)if(!is_numeric($weight)||!is_finite((float)$weight)||(float)$weight<0)
            throw new \InvalidArgumentException('INVALID_OPPORTUNITY_SCORE_WEIGHT');
        $raw=[];foreach($rows as $i=>$row){$details=$row['details']??[];$capital=(float)($row['capital_required']??0);
            $probability=null;if(($details['fill_probability_status']??null)==='CALIBRATED'&&is_numeric($details['fill_probability']??null))
                $probability=(float)$details['fill_probability'];
            elseif(($details['revert_probability_status']??null)==='CALIBRATED'&&is_numeric($details['revert_probability']??null))
                $probability=1-(float)$details['revert_probability'];
            $persistence=[];foreach(($details['history']??[]) as $venueHistory)foreach(['7d','30d','90d'] as $window)
                if(is_numeric($venueHistory[$window]['persistence_fraction']??null))$persistence[]=(float)$venueHistory[$window]['persistence_fraction'];
            $raw[$i]=['expected_return'=>$capital>0?(float)($row['net_profit']??0)/$capital:null,
                'probability'=>$probability!==null&&$probability>=0&&$probability<=1?$probability:null,
                'liquidity'=>is_numeric($details['liquidity_available_usd']??null)?log1p(max(0,(float)$details['liquidity_available_usd'])):null,
                'persistence'=>$persistence?min($persistence):null,
                'sharpe'=>is_numeric($details['historical_sharpe']??null)?(float)$details['historical_sharpe']:null,
                'data_quality'=>!empty($row['data_quality'])?1.0:0.0,'risk_penalty'=>empty($row['risk']['approved'])?1.0:0.0];}
        $positiveFactors=['expected_return','probability','liquidity','persistence','sharpe','data_quality'];$normalized=[];
        foreach($positiveFactors as $factor){$values=[];foreach($raw as $i=>$f)if($f[$factor]!==null)$values[$i]=$f[$factor];
            if(!$values)continue;$min=min($values);$max=max($values);foreach($values as $i=>$value)$normalized[$i][$factor]=$max>$min?($value-$min)/($max-$min):0.5;}
        $scores=[];foreach($raw as $i=>$f){$sum=0.0;$used=0.0;$parts=[];
            foreach($positiveFactors as $factor){if(!isset($normalized[$i][$factor]))continue;$weight=(float)($weights[$factor]??0);
                if($weight<=0)continue;$sum+=$weight*$normalized[$i][$factor];$used+=$weight;$parts[$factor]=['raw'=>$f[$factor],'normalized'=>$normalized[$i][$factor],'weight'=>$weight];}
            $riskWeight=(float)($weights['risk_penalty']??0);$riskValue=$f['risk_penalty'];$riskCost=$riskWeight*$riskValue;
            $score=($used>0?$sum/$used:0)-$riskCost;$parts['risk_penalty']=['raw'=>$riskValue,'cost'=>$riskCost,'weight'=>$riskWeight];
            $scores[$i]=['score'=>round($score,6),'components'=>['factors'=>$parts,'available_positive_weight'=>$used,
                'omitted_factors'=>array_values(array_diff($positiveFactors,array_keys($parts))),
                'weights_source'=>'CONFIGURED_OR_EXPERIMENTAL_DEFAULTS','probability_requires_calibration'=>true]];}
        return $scores;
    }

    /** Uses Sharpe only when at least two strategies have completed backtests on the same test window. */
    private function loadComparableBacktestSharpes(): array
    {
        $rows=$this->db->query('SELECT b.strategy_key,b.test_start_utc,b.test_end_utc,b.status,b.metrics_json
            FROM arbitrage_strategy_backtests b JOIN (SELECT strategy_key,MAX(id) id FROM arbitrage_strategy_backtests GROUP BY strategy_key) latest
              ON latest.id=b.id ORDER BY b.strategy_key')->fetchAll(PDO::FETCH_ASSOC);$byWindow=[];
        foreach($rows as $row){if($row['status']!=='COMPLETED')continue;$metrics=json_decode((string)$row['metrics_json'],true);
            if(!is_array($metrics))continue;$metrics=is_array($metrics['metrics']??null)?$metrics['metrics']:$metrics;
            if(!is_numeric($metrics['sharpe']??null)||!is_finite((float)$metrics['sharpe']))continue;
            $window=(string)$row['test_start_utc'].'|'.(string)$row['test_end_utc'];$byWindow[$window][$row['strategy_key']]=(float)$metrics['sharpe'];}
        $best=null;foreach($byWindow as $window=>$sharpes)if(count($sharpes)>=2&&($best===null||count($sharpes)>count($byWindow[$best])))$best=$window;
        if($best===null)return [];$out=[];foreach($byWindow[$best] as $strategy=>$sharpe)$out[$strategy]=['sharpe'=>$sharpe,'test_window'=>$best];
        return $out;
    }

    private function persistSnapshot(array $snapshot, string $now): void
    {
        foreach (($snapshot['funding'] ?? []) as $symbol => $venues) foreach ($venues as $venue => $rate) {
            if (!is_array($rate) || !is_numeric($rate['rate'] ?? null) || !is_numeric($rate['interval_hours'] ?? null)
                || (float)$rate['interval_hours'] <= 0) continue;
            $at = (string)($rate['observed_at'] ?? $snapshot['snapshot_at'] ?? $now);
            $hash = hash('sha256', json_encode($rate, JSON_THROW_ON_ERROR));
            $this->db->prepare('INSERT INTO arbitrage_funding_rate_history
                (exchange_name,symbol,funding_rate,interval_hours,mark_price,next_funding_at_utc,observed_at_utc,source,raw_hash)
                VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE funding_rate=VALUES(funding_rate),raw_hash=VALUES(raw_hash)')
                ->execute([$venue,$symbol,$rate['rate'],$rate['interval_hours'],$rate['mark']??null,$rate['next_funding_at']??null,$at,
                    $rate['source']??'NORMALIZED_INPUT',$hash]);
        }
        foreach (($snapshot['books'] ?? []) as $symbol => $venues) foreach ($venues as $venue => $book) {
            if (!is_array($book) || !is_numeric($book['bid']??null) || !is_numeric($book['ask']??null)
                || !is_numeric($book['bid_qty']??null) || !is_numeric($book['ask_qty']??null)
                || (float)$book['bid'] <= 0 || (float)$book['ask'] < (float)$book['bid']) continue;
            $this->db->prepare('INSERT INTO arbitrage_orderbook_snapshots
                (exchange_name,symbol,market_type,bid,ask,bid_qty,ask_qty,observed_at_utc,latency_ms,depth_json)
                VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$venue,$symbol,$book['market_type']??'PERP',$book['bid'],$book['ask'],
                    $book['bid_qty'],$book['ask_qty'],$book['observed_at']??$snapshot['snapshot_at']??$now,$book['latency_ms']??null,
                    json_encode($book['depth']??[],JSON_THROW_ON_ERROR)]);
        }
        foreach (($snapshot['basis'] ?? []) as $basis) {
            if (!is_array($basis) || !isset($basis['spot_ask'],$basis['spot_bid'],$basis['future_ask'],$basis['future_bid'],$basis['symbol'])) continue;
            $this->db->prepare('INSERT INTO arbitrage_basis_history
                (symbol,spot_venue,future_venue,spot_bid,spot_ask,future_bid,future_ask,expiry_at_utc,observed_at_utc)
                VALUES (?,?,?,?,?,?,?,?,?)')->execute([$basis['symbol'],$basis['spot_venue']??'UNKNOWN',$basis['future_venue']??'UNKNOWN',
                    $basis['spot_bid'],$basis['spot_ask'],$basis['future_bid'],$basis['future_ask'],$basis['expiry_at']??null,
                    $basis['observed_at']??$snapshot['snapshot_at']??$now]);
        }
    }

    private function hasStaleComponents(array $snapshot,int $maxAge): bool
    {
        $observed=[];
        foreach(($snapshot['funding']??[]) as $venues)foreach($venues as $row)if(isset($row['observed_at']))$observed[]=$row['observed_at'];
        foreach(($snapshot['books']??[]) as $venues)foreach($venues as $row)if(isset($row['observed_at']))$observed[]=$row['observed_at'];
        foreach(['basis','xemm','pairs','triangular_edges','dex_routes'] as $section){
            foreach(($snapshot[$section]??[]) as $row)if(is_array($row))$this->collectObservedTimes($row,$observed);
        }
        foreach($observed as $time){$ts=strtotime((string)$time.' UTC');if($ts===false||$ts>time()+5||time()-$ts>$maxAge)return true;}
        return false;
    }

    private function collectObservedTimes(array $row,array &$observed): void
    {
        if(isset($row['observed_at']))$observed[]=$row['observed_at'];
        foreach($row as $value)if(is_array($value))$this->collectObservedTimes($value,$observed);
    }

    public static function registry(): array
    {
        return ['FUNDING_RATE'=>'Dynamic Funding Rate','CASH_AND_CARRY'=>'Cash and Carry',
            'CROSS_EXCHANGE_MM'=>'Cross-Exchange Market Making','STATISTICAL'=>'Statistical Arbitrage',
            'SPATIAL'=>'Spatial Arbitrage','TRIANGULAR'=>'Triangular Arbitrage','MEV_OBSERVATION'=>'MEV (observation only)'];
    }
}
