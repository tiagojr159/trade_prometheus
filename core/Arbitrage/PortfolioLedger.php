<?php
declare(strict_types=1);
namespace Prometheus\core\Arbitrage;

use PDO;
use Throwable;

final class PortfolioLedger
{
    private PDO $db;
    public function __construct(PDO $db){$this->db=$db;}

    public function ensurePortfolio(string $strategy,string $key,float $initialEquity,string $startedAtUtc): int
    {
        if(!isset(StrategyEngine::registry()[$strategy])||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$key)||$initialEquity<=0)
            throw new \InvalidArgumentException('INVALID_PORTFOLIO');
        $stmt=$this->db->prepare('INSERT INTO arbitrage_strategy_portfolios
            (strategy_key,portfolio_key,initial_equity,cash,equity,peak_equity,started_at_utc)
            VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
        $stmt->execute([$strategy,$key,$initialEquity,$initialEquity,$initialEquity,$initialEquity,$startedAtUtc]);
        return (int)$this->db->lastInsertId();
    }

    public function recordClosedTrade(int $portfolioId,string $tradeKey,array $trade,string $classification='PAPER'): array
    {
        if(!in_array($classification,['PAPER','BACKTEST'],true)||!preg_match('/^[A-Za-z0-9:_-]{1,96}$/D',$tradeKey))
            throw new \InvalidArgumentException('LIVE_OR_INVALID_LEDGER_WRITE_BLOCKED');
        foreach(['symbol','opened_at_utc','closed_at_utc','gross_pnl','fees','funding_received','funding_paid','slippage'] as $field)
            if(!array_key_exists($field,$trade))throw new \InvalidArgumentException('TRADE_FIELD_MISSING:'.$field);
        $net=(float)$trade['gross_pnl']-(float)$trade['fees']+(float)$trade['funding_received']-(float)$trade['funding_paid']-(float)$trade['slippage'];
        $this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT * FROM arbitrage_strategy_portfolios WHERE id=? FOR UPDATE');$q->execute([$portfolioId]);$p=$q->fetch(PDO::FETCH_ASSOC);
            if(!$p)throw new \RuntimeException('PORTFOLIO_NOT_FOUND');
            if($p['status']==='KILL_SWITCH'||(float)$p['max_drawdown_pct']>=10)throw new \RuntimeException('PAPER_PORTFOLIO_KILL_SWITCH');
            $check=$this->db->prepare('SELECT id,net_pnl,portfolio_id FROM arbitrage_strategy_trades WHERE trade_key=?');$check->execute([$tradeKey]);$existing=$check->fetch(PDO::FETCH_ASSOC);
            if($existing){if((int)$existing['portfolio_id']!==$portfolioId)throw new \RuntimeException('TRADE_KEY_PORTFOLIO_CONFLICT');
                $this->db->commit();return ['status'=>'DUPLICATE','trade_id'=>(int)$existing['id'],'net_pnl'=>(float)$existing['net_pnl']];}
            $opportunity=$trade['opportunity_id']??null;
            $this->db->prepare('INSERT INTO arbitrage_strategy_trades
                (portfolio_id,opportunity_id,trade_key,symbol,classification,gross_pnl,fees,funding_received,funding_paid,slippage,net_pnl,opened_at_utc,closed_at_utc)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$portfolioId,$opportunity,$tradeKey,$trade['symbol'],$classification,
                $trade['gross_pnl'],$trade['fees'],$trade['funding_received'],$trade['funding_paid'],$trade['slippage'],$net,$trade['opened_at_utc'],$trade['closed_at_utc']]);
            $id=(int)$this->db->lastInsertId();$capitalUsed=max(0,(float)($trade['capital_used']??0));
            $this->db->prepare('INSERT INTO arbitrage_strategy_trade_metrics(trade_id,capital_used) VALUES (?,?)')->execute([$id,$capitalUsed]);
            $equity=(float)$p['equity']+$net;$peak=max((float)$p['peak_equity'],$equity);
            $dd=$peak>0?($peak-$equity)/$peak*100:0;$maxDd=max((float)$p['max_drawdown_pct'],$dd);
            $this->db->prepare('UPDATE arbitrage_strategy_portfolios SET cash=cash+?,equity=?,realized_pnl=realized_pnl+?,peak_equity=?,max_drawdown_pct=? WHERE id=?')
                ->execute([$net,$equity,$net,$peak,$maxDd,$portfolioId]);
            $trades=$this->db->prepare('SELECT t.net_pnl,t.closed_at_utc,COALESCE(m.capital_used,0) capital_used FROM arbitrage_strategy_trades t
                LEFT JOIN arbitrage_strategy_trade_metrics m ON m.trade_id=t.id WHERE t.portfolio_id=? ORDER BY t.closed_at_utc,t.id');
            $trades->execute([$portfolioId]);$tradeRows=$trades->fetchAll(PDO::FETCH_ASSOC);
            $daily=[];$running=(float)$p['initial_equity'];$daily[substr($p['started_at_utc'],0,10)]=$running;
            foreach($tradeRows as $tr){$date=substr($tr['closed_at_utc'],0,10);$running+=(float)$tr['net_pnl'];$daily[$date]=$running;}
            $equityPoints=[];foreach($daily as $date=>$value)$equityPoints[]=['at'=>$date.' 00:00:00','equity'=>$value];
            $metrics=PerformanceAnalytics::calculate($tradeRows,$equityPoints);
            $this->db->prepare('INSERT INTO arbitrage_strategy_performance
                (strategy_key,as_of_utc,classification,net_pnl,equity,drawdown_pct,sharpe,sortino,profit_factor,calmar,win_rate,capital_used)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$p['strategy_key'],$trade['closed_at_utc'],$classification,$net,$equity,$maxDd,
                $metrics['sharpe'],$metrics['sortino'],$metrics['profit_factor'],$metrics['calmar'],$metrics['win_rate'],$metrics['capital_used']]);
            $this->db->commit();return ['status'=>'RECORDED','trade_id'=>$id,'net_pnl'=>$net,'equity'=>$equity,'max_drawdown_pct'=>$maxDd,'metrics'=>$metrics];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function openSimulatedPosition(int $portfolioId,int $opportunityId,string $idempotencyKey,string $leg,string $venue,
        string $instrumentType,string $side,float $quantity,float $entryPrice,float $entryFee=0,float $entrySlippage=0,
        ?string $openedAtUtc=null,float $maxAllocationPct=5,float $maxExchangeExposurePct=25): array
    {
        $instrumentType=strtoupper($instrumentType);$side=strtoupper($side);$openedAtUtc=$openedAtUtc??gmdate('Y-m-d H:i:s');
        if(!in_array($instrumentType,['SPOT','PERP'],true)||!in_array($side,['LONG','SHORT'],true)
            ||($instrumentType==='SPOT'&&$side!=='LONG')||$quantity<=0||$entryPrice<=0||$entryFee<0||$entrySlippage<0
            ||!preg_match('/^[A-Za-z0-9:_-]{1,96}$/D',$idempotencyKey)||!preg_match('/^[A-Za-z0-9_-]{1,16}$/D',$leg)
            ||!preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$venue))throw new \InvalidArgumentException('INVALID_SIMULATED_POSITION');
        $notional=$quantity*$entryPrice;$cashCost=$notional+$entryFee+$entrySlippage;
        $ownsTransaction=!$this->db->inTransaction();if($ownsTransaction)$this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT * FROM arbitrage_strategy_portfolios WHERE id=? FOR UPDATE');$q->execute([$portfolioId]);$p=$q->fetch(PDO::FETCH_ASSOC);
            if(!$p)throw new \RuntimeException('PORTFOLIO_NOT_FOUND');
            $opp=$this->db->prepare('SELECT strategy_key,symbol,status,details_json FROM arbitrage_opportunities WHERE id=? FOR UPDATE');$opp->execute([$opportunityId]);$o=$opp->fetch(PDO::FETCH_ASSOC);
            if(!$o||$o['strategy_key']!==$p['strategy_key']||$o['status']!=='OBSERVED')throw new \RuntimeException('OPPORTUNITY_NOT_APPROVED_FOR_PAPER');
            $opDetails=json_decode((string)$o['details_json'],true)?:[];$sim=$opDetails['execution']??[];
            if(!in_array($sim['status']??'', ['HEDGED','OPENED_SIMULATED_CASH_AND_CARRY'],true))throw new \RuntimeException('SIMULATED_FILL_NOT_CONFIRMED');
            $duplicate=$this->db->prepare('SELECT s.position_id,p.portfolio_id FROM arbitrage_strategy_position_state s JOIN arbitrage_strategy_positions p ON p.id=s.position_id WHERE s.idempotency_key=?');$duplicate->execute([$idempotencyKey]);
            if($duplicateRow=$duplicate->fetch(PDO::FETCH_ASSOC)){if((int)$duplicateRow['portfolio_id']!==$portfolioId)throw new \RuntimeException('POSITION_KEY_PORTFOLIO_CONFLICT');
                if($ownsTransaction)$this->db->commit();return ['status'=>'DUPLICATE','position_id'=>(int)$duplicateRow['position_id']];}
            $grossQ=$this->db->prepare("SELECT COALESCE(SUM(quantity*COALESCE(mark_price,entry_price)),0) FROM arbitrage_strategy_positions WHERE portfolio_id=? AND status='OPEN'");
            $grossQ->execute([$portfolioId]);$gross=(float)$grossQ->fetchColumn();$equity=(float)$p['equity'];
            $venueQ=$this->db->prepare("SELECT COALESCE(SUM(quantity*COALESCE(mark_price,entry_price)),0) FROM arbitrage_strategy_positions WHERE portfolio_id=? AND venue=? AND status='OPEN'");
            $venueQ->execute([$portfolioId,$venue]);$venueGross=(float)$venueQ->fetchColumn();
            $oppGrossQ=$this->db->prepare("SELECT COALESCE(SUM(quantity*COALESCE(mark_price,entry_price)),0) FROM arbitrage_strategy_positions WHERE portfolio_id=? AND opportunity_id=? AND status='OPEN'");
            $oppGrossQ->execute([$portfolioId,$opportunityId]);$opportunityGross=(float)$oppGrossQ->fetchColumn();
            if($equity<=0||($opportunityGross+$notional)/$equity*100>$maxAllocationPct)throw new \RuntimeException('POSITION_ALLOCATION_LIMIT');
            if(($gross+$notional)>$equity)throw new \RuntimeException('GROSS_EXPOSURE_EXCEEDS_EQUITY');
            if(($venueGross+$notional)/$equity*100>$maxExchangeExposurePct)throw new \RuntimeException('EXCHANGE_EXPOSURE_LIMIT');
            if((float)$p['cash']<$cashCost)throw new \RuntimeException('INSUFFICIENT_VIRTUAL_CASH');
            $margin=$instrumentType==='PERP'?$notional:0.0;
            $this->db->prepare('INSERT INTO arbitrage_strategy_positions
                (portfolio_id,opportunity_id,symbol,leg,venue,side,quantity,entry_price,mark_price,margin_reserved,status,opened_at_utc)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$portfolioId,$opportunityId,$o['symbol'],$leg,$venue,$side,$quantity,$entryPrice,
                $entryPrice,$margin,'OPEN',$openedAtUtc]);$positionId=(int)$this->db->lastInsertId();
            $this->db->prepare('INSERT INTO arbitrage_strategy_position_state
                (position_id,idempotency_key,instrument_type,entry_fees,entry_slippage,classification) VALUES (?,?,?,?,?,"PAPER")')
                ->execute([$positionId,$idempotencyKey,$instrumentType,$entryFee,$entrySlippage]);
            $this->db->prepare('UPDATE arbitrage_strategy_portfolios SET cash=cash-? WHERE id=?')->execute([$cashCost,$portfolioId]);
            $this->refreshEquity($portfolioId);
            $this->db->prepare('INSERT INTO arbitrage_execution_events
                (strategy_key,opportunity_id,event_key,event_type,execution_mode,details_json,occurred_at_utc) VALUES (?,?,?,?,?,?,?)')
                ->execute([$p['strategy_key'],$opportunityId,'position:'.$positionId.':open','POSITION_OPENED','SIMULATION',
                    json_encode(['position_id'=>$positionId,'venue'=>$venue,'side'=>$side,'quantity'=>$quantity,'entry_price'=>$entryPrice],JSON_THROW_ON_ERROR),$openedAtUtc]);
            if($ownsTransaction)$this->db->commit();return ['status'=>'OPENED_SIMULATED','position_id'=>$positionId,'notional'=>$notional,'cash'=>$p['cash']-$cashCost];
        }catch(Throwable $e){if($ownsTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /** Opens a delta-neutral multi-leg paper group in one database transaction. */
    public function openSimulatedPositionGroup(int $portfolioId,int $opportunityId,string $groupKey,array $legs,
        float $maxAllocationPct=5,float $maxExchangeExposurePct=25,float $maxNetDeltaPct=1): array
    {
        if(count($legs)<2||count($legs)>4||!preg_match('/^[A-Za-z0-9:_-]{1,64}$/D',$groupKey))
            throw new \InvalidArgumentException('INVALID_POSITION_GROUP');
        $grossNotional=0.0;$netDelta=0.0;$keys=[];
        foreach($legs as $leg){$side=strtoupper((string)($leg['side']??''));$qty=(float)($leg['quantity']??0);$price=(float)($leg['entry_price']??0);
            $name=(string)($leg['leg']??'');if(!in_array($side,['LONG','SHORT'],true)||$qty<=0||$price<=0||$name===''||!isset($leg['venue'],$leg['instrument_type']))
                throw new \InvalidArgumentException('INVALID_POSITION_GROUP_LEG');
            $key=$groupKey.':'.$name;if(strlen($key)>96||isset($keys[$key]))throw new \InvalidArgumentException('INVALID_POSITION_GROUP_KEY');
            $keys[$key]=true;$notional=$qty*$price;$grossNotional+=$notional;$netDelta+=($side==='LONG'?1:-1)*$notional;}
        if($grossNotional<=0||abs($netDelta)/$grossNotional*100>$maxNetDeltaPct)throw new \InvalidArgumentException('POSITION_GROUP_NOT_DELTA_NEUTRAL');
        $ownsTransaction=!$this->db->inTransaction();$savepoint=$ownsTransaction?null:'arb_open_group_'.bin2hex(random_bytes(4));
        if($ownsTransaction)$this->db->beginTransaction();else $this->db->exec('SAVEPOINT '.$savepoint);
        try{$q=$this->db->prepare('SELECT id FROM arbitrage_strategy_portfolios WHERE id=? FOR UPDATE');$q->execute([$portfolioId]);
            if(!$q->fetchColumn())throw new \RuntimeException('PORTFOLIO_NOT_FOUND');$known=[];
            $q=$this->db->prepare('SELECT s.idempotency_key,p.id position_id,p.portfolio_id,p.status FROM arbitrage_strategy_position_state s
                JOIN arbitrage_strategy_positions p ON p.id=s.position_id WHERE s.idempotency_key=?');
            foreach(array_keys($keys) as $key){$q->execute([$key]);if($row=$q->fetch(PDO::FETCH_ASSOC)){
                if((int)$row['portfolio_id']!==$portfolioId)throw new \RuntimeException('POSITION_GROUP_KEY_PORTFOLIO_CONFLICT');$known[$key]=(int)$row['position_id'];}}
            if($known){if(count($known)!==count($keys))throw new \RuntimeException('POSITION_GROUP_PARTIAL_DUPLICATE');
                if($ownsTransaction)$this->db->commit();else $this->db->exec('RELEASE SAVEPOINT '.$savepoint);
                return ['status'=>'DUPLICATE','position_ids'=>$known];}
            $positions=[];foreach($legs as $leg){$name=(string)$leg['leg'];$position=$this->openSimulatedPosition($portfolioId,$opportunityId,$groupKey.':'.$name,$name,
                    (string)$leg['venue'],(string)$leg['instrument_type'],(string)$leg['side'],(float)$leg['quantity'],(float)$leg['entry_price'],
                    (float)($leg['entry_fee']??0),(float)($leg['entry_slippage']??0),$leg['opened_at_utc']??null,$maxAllocationPct,$maxExchangeExposurePct);
                if(($position['status']??'')!=='OPENED_SIMULATED')throw new \RuntimeException('POSITION_GROUP_LEG_NOT_OPENED');$positions[$name]=$position['position_id'];}
            if($ownsTransaction)$this->db->commit();else $this->db->exec('RELEASE SAVEPOINT '.$savepoint);
            return ['status'=>'OPENED_SIMULATED_GROUP','position_ids'=>$positions,
                'gross_notional'=>$grossNotional,'net_delta'=>$netDelta];
        }catch(Throwable $e){if($ownsTransaction&&$this->db->inTransaction())$this->db->rollBack();elseif($this->db->inTransaction()){
                $this->db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$this->db->exec('RELEASE SAVEPOINT '.$savepoint);}throw $e;}
    }

    public function markSimulatedPosition(int $positionId,float $markPrice,string $markedAtUtc): array
    {
        if($markPrice<=0||$this->utcTimestamp($markedAtUtc)>time())throw new \InvalidArgumentException('INVALID_MARK_PRICE_OR_FUTURE_MARK');$this->db->beginTransaction();
        try{$q=$this->db->prepare("SELECT portfolio_id FROM arbitrage_strategy_positions WHERE id=? AND status='OPEN' FOR UPDATE");
            $q->execute([$positionId]);$portfolioId=$q->fetchColumn();if(!$portfolioId)throw new \RuntimeException('OPEN_POSITION_NOT_FOUND');
            $this->db->prepare('UPDATE arbitrage_strategy_positions SET mark_price=? WHERE id=?')->execute([$markPrice,$positionId]);
            $result=$this->refreshEquity((int)$portfolioId);$this->db->prepare('INSERT INTO arbitrage_execution_events
                (strategy_key,opportunity_id,event_key,event_type,execution_mode,details_json,occurred_at_utc)
                SELECT p.strategy_key,sp.opportunity_id,CONCAT("position:",sp.id,":mark:",?),"POSITION_MARKED","SIMULATION",?,?
                FROM arbitrage_strategy_positions sp JOIN arbitrage_strategy_portfolios p ON p.id=sp.portfolio_id WHERE sp.id=?
                ON DUPLICATE KEY UPDATE details_json=VALUES(details_json)')
                ->execute([$markedAtUtc, json_encode(['mark_price'=>$markPrice,'equity'=>$result['equity']],JSON_THROW_ON_ERROR),$markedAtUtc,$positionId]);
            $this->db->commit();return ['status'=>'MARKED','position_id'=>$positionId]+$result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function settleSimulatedFunding(int $positionId,string $settlementKey,float $rate,float $markPrice,string $settledAtUtc,string $availableAtUtc): array
    {
        if($rate< -1||$rate>1||$markPrice<=0||$this->utcTimestamp($settledAtUtc)>time()||$this->utcTimestamp($availableAtUtc)>time()
            ||$this->utcTimestamp($availableAtUtc)<$this->utcTimestamp($settledAtUtc)||!preg_match('/^[A-Za-z0-9:_-]{1,96}$/D',$settlementKey))
            throw new \InvalidArgumentException('INVALID_OR_FUTURE_FUNDING_SETTLEMENT');
        $this->db->beginTransaction();
        try{$q=$this->db->prepare("SELECT sp.*,p.strategy_key,s.* FROM arbitrage_strategy_positions sp
                JOIN arbitrage_strategy_portfolios p ON p.id=sp.portfolio_id JOIN arbitrage_strategy_position_state s ON s.position_id=sp.id
                WHERE sp.id=? AND sp.status='OPEN' FOR UPDATE");$q->execute([$positionId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['instrument_type']!=='PERP')throw new \RuntimeException('OPEN_PERP_POSITION_NOT_FOUND');
            if($this->utcTimestamp($settledAtUtc)<=$this->utcTimestamp($row['opened_at_utc']))throw new \RuntimeException('FUNDING_PRECEDES_POSITION_OPEN');
            $dup=$this->db->prepare('SELECT id,cash_delta FROM arbitrage_position_funding_settlements WHERE position_id=? AND settlement_key=?');
            $dup->execute([$positionId,$settlementKey]);if($existing=$dup->fetch(PDO::FETCH_ASSOC)){$this->db->commit();return ['status'=>'DUPLICATE','cash_delta'=>(float)$existing['cash_delta']];}
            $amount=(float)$row['quantity']*$markPrice*abs($rate);$receive=(($row['side']==='SHORT'&&$rate>0)||($row['side']==='LONG'&&$rate<0));$cashDelta=$receive?$amount:-$amount;
            $this->db->prepare('INSERT INTO arbitrage_position_funding_settlements
                (position_id,settlement_key,funding_rate,mark_price,amount,cash_delta,settled_at_utc,available_at_utc) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$positionId,$settlementKey,$rate,$markPrice,$amount,$cashDelta,$settledAtUtc,$availableAtUtc]);
            $this->db->prepare('UPDATE arbitrage_strategy_position_state SET funding_received=funding_received+?,funding_paid=funding_paid+? WHERE position_id=?')
                ->execute([max(0,$cashDelta),max(0,-$cashDelta),$positionId]);
            $this->db->prepare('UPDATE arbitrage_strategy_portfolios SET cash=cash+? WHERE id=?')->execute([$cashDelta,$row['portfolio_id']]);
            $equity=$this->refreshEquity((int)$row['portfolio_id']);$this->db->commit();
            return ['status'=>'SETTLED_SIMULATED','amount'=>$amount,'cash_delta'=>$cashDelta,'equity'=>$equity['equity']];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function closeSimulatedPosition(int $positionId,string $tradeKey,float $closePrice,float $closeQuantity,float $feeRate,
        float $slippageBps,string $closedAtUtc): array
    {
        if($closePrice<=0||$closeQuantity<=0||$feeRate<0||$slippageBps<0||$this->utcTimestamp($closedAtUtc)>time()
            ||!preg_match('/^[A-Za-z0-9:_-]{1,96}$/D',$tradeKey))throw new \InvalidArgumentException('INVALID_SIMULATED_CLOSE');
        $ownsTransaction=!$this->db->inTransaction();if($ownsTransaction)$this->db->beginTransaction();
        try{
            $q=$this->db->prepare('SELECT id,net_pnl,portfolio_id FROM arbitrage_strategy_trades WHERE trade_key=?');$q->execute([$tradeKey]);
            if($old=$q->fetch(PDO::FETCH_ASSOC)){if((int)$old['portfolio_id']!==$this->positionPortfolioId($positionId))throw new \RuntimeException('TRADE_KEY_PORTFOLIO_CONFLICT');
                if($ownsTransaction)$this->db->commit();return ['status'=>'DUPLICATE','trade_id'=>(int)$old['id'],'net_pnl'=>(float)$old['net_pnl']];}
            $q=$this->db->prepare("SELECT sp.*,p.strategy_key,p.initial_equity,p.cash,p.equity,p.peak_equity,p.max_drawdown_pct,p.started_at_utc,s.instrument_type,s.entry_fees,s.entry_slippage,s.funding_received,s.funding_paid
                FROM arbitrage_strategy_positions sp JOIN arbitrage_strategy_portfolios p ON p.id=sp.portfolio_id
                JOIN arbitrage_strategy_position_state s ON s.position_id=sp.id WHERE sp.id=? AND sp.status='OPEN' FOR UPDATE");
            $q->execute([$positionId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new \RuntimeException('OPEN_POSITION_NOT_FOUND');
            if($closeQuantity>(float)$row['quantity']+1e-12)throw new \RuntimeException('CLOSE_QUANTITY_EXCEEDS_POSITION');
            $fraction=$closeQuantity/(float)$row['quantity'];$direction=$row['side']==='SHORT'?-1:1;
            $gross=$direction*($closePrice-(float)$row['entry_price'])*$closeQuantity;$exitNotional=$closePrice*$closeQuantity;
            $exitFee=$exitNotional*$feeRate;$exitSlippage=$exitNotional*$slippageBps/10000;
            $entryFee=(float)$row['entry_fees']*$fraction;$entrySlippage=(float)$row['entry_slippage']*$fraction;
            $fundingReceived=(float)$row['funding_received']*$fraction;$fundingPaid=(float)$row['funding_paid']*$fraction;
            $net=$gross-$entryFee-$exitFee-$entrySlippage-$exitSlippage+$fundingReceived-$fundingPaid;
            $marginRelease=$row['instrument_type']==='PERP'?(float)$row['margin_reserved']*$fraction:0.0;
            $cashDelta=$row['instrument_type']==='PERP'?$marginRelease+$gross-$exitFee-$exitSlippage:$exitNotional-$exitFee-$exitSlippage;
            $this->db->prepare('INSERT INTO arbitrage_strategy_trades
                (portfolio_id,opportunity_id,trade_key,symbol,classification,gross_pnl,fees,funding_received,funding_paid,slippage,net_pnl,opened_at_utc,closed_at_utc)
                VALUES (?,?,?,?,"PAPER",?,?,?,?,?,?,?,?)')->execute([$row['portfolio_id'],$row['opportunity_id'],$tradeKey,$row['symbol'],$gross,
                    $entryFee+$exitFee,$fundingReceived,$fundingPaid,$entrySlippage+$exitSlippage,$net,$row['opened_at_utc'],$closedAtUtc]);
            $tradeId=(int)$this->db->lastInsertId();$capitalUsed=(float)$row['entry_price']*$closeQuantity;
            $this->db->prepare('INSERT INTO arbitrage_strategy_trade_metrics(trade_id,capital_used) VALUES (?,?)')->execute([$tradeId,$capitalUsed]);
            $remaining=(float)$row['quantity']-$closeQuantity;
            if($remaining<=1e-12)$this->db->prepare("UPDATE arbitrage_strategy_positions SET quantity=0,mark_price=?,margin_reserved=0,status='CLOSED',closed_at_utc=? WHERE id=?")
                ->execute([$closePrice,$closedAtUtc,$positionId]);
            else $this->db->prepare('UPDATE arbitrage_strategy_positions SET quantity=?,mark_price=?,margin_reserved=margin_reserved-? WHERE id=?')
                ->execute([$remaining,$closePrice,$marginRelease,$positionId]);
            $this->db->prepare('UPDATE arbitrage_strategy_position_state SET entry_fees=entry_fees-?,entry_slippage=entry_slippage-?,funding_received=funding_received-?,funding_paid=funding_paid-? WHERE position_id=?')
                ->execute([$entryFee,$entrySlippage,$fundingReceived,$fundingPaid,$positionId]);
            $this->db->prepare('UPDATE arbitrage_strategy_portfolios SET cash=cash+?,realized_pnl=realized_pnl+? WHERE id=?')
                ->execute([$cashDelta,$net,$row['portfolio_id']]);$state=$this->refreshEquity((int)$row['portfolio_id']);
            $trades=$this->db->prepare('SELECT t.net_pnl,t.closed_at_utc,COALESCE(m.capital_used,0) capital_used FROM arbitrage_strategy_trades t
                LEFT JOIN arbitrage_strategy_trade_metrics m ON m.trade_id=t.id WHERE t.portfolio_id=? ORDER BY t.closed_at_utc,t.id');
            $trades->execute([$row['portfolio_id']]);$tradeRows=$trades->fetchAll(PDO::FETCH_ASSOC);$daily=[];$running=(float)$row['initial_equity'];
            $daily[substr($row['started_at_utc'],0,10)]=$running;foreach($tradeRows as $tradeRow){$running+=(float)$tradeRow['net_pnl'];$daily[substr($tradeRow['closed_at_utc'],0,10)]=$running;}
            $points=[];foreach($daily as $date=>$value)$points[]=['at'=>$date.' 00:00:00','equity'=>$value];$metrics=PerformanceAnalytics::calculate($tradeRows,$points);
            $this->db->prepare('INSERT INTO arbitrage_strategy_performance
                (strategy_key,as_of_utc,classification,net_pnl,equity,drawdown_pct,sharpe,sortino,profit_factor,calmar,win_rate,capital_used)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$row['strategy_key'],$closedAtUtc,'PAPER',$net,$state['equity'],$state['max_drawdown_pct'],
                    $metrics['sharpe'],$metrics['sortino'],$metrics['profit_factor'],$metrics['calmar'],$metrics['win_rate'],$metrics['capital_used']]);
            $this->db->prepare('INSERT INTO arbitrage_execution_events
                (strategy_key,opportunity_id,event_key,event_type,execution_mode,details_json,occurred_at_utc) VALUES (?,?,?,?,?,?,?)')
                ->execute([$row['strategy_key'],$row['opportunity_id'],'trade:'.$tradeId.':close','POSITION_CLOSED','SIMULATION',
                    json_encode(['position_id'=>$positionId,'net_pnl'=>$net,'remaining_quantity'=>max(0,$remaining)],JSON_THROW_ON_ERROR),$closedAtUtc]);
            if($ownsTransaction)$this->db->commit();return ['status'=>$remaining<=1e-12?'CLOSED_SIMULATED':'PARTIALLY_CLOSED_SIMULATED',
                'trade_id'=>$tradeId,'net_pnl'=>$net,'equity'=>$state['equity'],'remaining_quantity'=>max(0,$remaining)];
        }catch(Throwable $e){if($ownsTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /** Closes all requested legs atomically; a failed close rolls back the entire group. */
    public function closeSimulatedPositionGroup(array $closures,float $feeRate,float $slippageBps,string $closedAtUtc): array
    {
        if(count($closures)<2||count($closures)>4||$feeRate<0||$slippageBps<0)
            throw new \InvalidArgumentException('INVALID_POSITION_GROUP_CLOSE');$ownsTransaction=!$this->db->inTransaction();
        $savepoint=$ownsTransaction?null:'arb_close_group_'.bin2hex(random_bytes(4));
        if($ownsTransaction)$this->db->beginTransaction();else $this->db->exec('SAVEPOINT '.$savepoint);
        try{$portfolioId=null;$tradeKeys=[];$positionIds=[];$existing=[];
            foreach($closures as $closure){$positionId=(int)($closure['position_id']??0);$tradeKey=(string)($closure['trade_key']??'');
                if($positionId<=0||!preg_match('/^[A-Za-z0-9:_-]{1,96}$/D',$tradeKey)||isset($tradeKeys[$tradeKey])||isset($positionIds[$positionId]))
                    throw new \InvalidArgumentException('INVALID_POSITION_GROUP_CLOSE_LEG');$tradeKeys[$tradeKey]=true;
                $positionIds[$positionId]=true;
                $thisPortfolio=$this->positionPortfolioId($positionId);if($portfolioId!==null&&$portfolioId!==$thisPortfolio)
                    throw new \RuntimeException('POSITION_GROUP_PORTFOLIO_MISMATCH');$portfolioId=$thisPortfolio;
                $q=$this->db->prepare('SELECT id,portfolio_id,net_pnl FROM arbitrage_strategy_trades WHERE trade_key=?');$q->execute([$tradeKey]);
                if($row=$q->fetch(PDO::FETCH_ASSOC)){if((int)$row['portfolio_id']!==$portfolioId)throw new \RuntimeException('TRADE_KEY_PORTFOLIO_CONFLICT');
                    $existing[$tradeKey]=['status'=>'DUPLICATE','trade_id'=>(int)$row['id'],'net_pnl'=>(float)$row['net_pnl']];
                }else{
                    $position=$this->db->prepare("SELECT quantity,status FROM arbitrage_strategy_positions WHERE id=? FOR UPDATE");$position->execute([$positionId]);
                    $positionRow=$position->fetch(PDO::FETCH_ASSOC);
                    if(!$positionRow||$positionRow['status']!=='OPEN'||(float)($closure['close_quantity']??0)<(float)$positionRow['quantity']-1e-12)
                        throw new \RuntimeException('POSITION_GROUP_CLOSE_MUST_CLOSE_EACH_LEG');
                }}
            if($existing){if(count($existing)!==count($closures))throw new \RuntimeException('POSITION_GROUP_PARTIAL_CLOSE_DUPLICATE');
                if($ownsTransaction)$this->db->commit();else $this->db->exec('RELEASE SAVEPOINT '.$savepoint);
                return ['status'=>'DUPLICATE_GROUP','trades'=>$existing];}
            $results=[];foreach($closures as $closure){$tradeKey=(string)$closure['trade_key'];$results[$tradeKey]=$this->closeSimulatedPosition(
                (int)$closure['position_id'],$tradeKey,(float)$closure['close_price'],(float)$closure['close_quantity'],$feeRate,$slippageBps,$closedAtUtc);
                if(($results[$tradeKey]['status']??'')!=='CLOSED_SIMULATED')
                    throw new \RuntimeException('POSITION_GROUP_CLOSE_FAILED');}
            if($ownsTransaction)$this->db->commit();else $this->db->exec('RELEASE SAVEPOINT '.$savepoint);
            return ['status'=>'CLOSED_SIMULATED_GROUP','trades'=>$results];
        }catch(Throwable $e){if($ownsTransaction&&$this->db->inTransaction())$this->db->rollBack();elseif($this->db->inTransaction()){
                $this->db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$this->db->exec('RELEASE SAVEPOINT '.$savepoint);}throw $e;}
    }

    public function reconcilePortfolio(int $portfolioId,bool $repair=false): array
    {
        $this->db->beginTransaction();
        try{$q=$this->db->prepare('SELECT * FROM arbitrage_strategy_portfolios WHERE id=? FOR UPDATE');$q->execute([$portfolioId]);$p=$q->fetch(PDO::FETCH_ASSOC);
            if(!$p)throw new \RuntimeException('PORTFOLIO_NOT_FOUND');$calculated=$this->calculateEquity($portfolioId,(float)$p['cash']);
            $difference=$calculated['equity']-(float)$p['equity'];if($repair)$this->storeEquity($portfolioId,$calculated['equity']);
            $missing=$this->db->prepare("SELECT COUNT(*) FROM arbitrage_strategy_positions sp LEFT JOIN arbitrage_strategy_position_state s ON s.position_id=sp.id WHERE sp.portfolio_id=? AND sp.status='OPEN' AND s.position_id IS NULL");
            $missing->execute([$portfolioId]);$missingState=(int)$missing->fetchColumn();$this->db->commit();
            return ['status'=>abs($difference)<0.0000001&&$missingState===0?'RECONCILED':'DIVERGENCE',
                'stored_equity'=>(float)$p['equity'],'calculated_equity'=>$calculated['equity'],'difference'=>$difference,
                'open_positions_without_state'=>$missingState,'repair_applied'=>$repair];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function refreshEquity(int $portfolioId): array
    {
        $q=$this->db->prepare('SELECT cash FROM arbitrage_strategy_portfolios WHERE id=? FOR UPDATE');$q->execute([$portfolioId]);$cash=$q->fetchColumn();
        if($cash===false)throw new \RuntimeException('PORTFOLIO_NOT_FOUND');$calc=$this->calculateEquity($portfolioId,(float)$cash);
        return $this->storeEquity($portfolioId,$calc['equity']);
    }

    private function calculateEquity(int $portfolioId,float $cash): array
    {
        $q=$this->db->prepare("SELECT sp.quantity,sp.entry_price,COALESCE(sp.mark_price,sp.entry_price) mark_price,sp.side,sp.margin_reserved,s.instrument_type
            FROM arbitrage_strategy_positions sp LEFT JOIN arbitrage_strategy_position_state s ON s.position_id=sp.id
            WHERE sp.portfolio_id=? AND sp.status='OPEN'");$q->execute([$portfolioId]);$equity=$cash;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p){$qty=(float)$p['quantity'];$entry=(float)$p['entry_price'];$mark=(float)$p['mark_price'];
            if(($p['instrument_type']??'SPOT')==='PERP')$equity+=(float)$p['margin_reserved']+(($p['side']==='SHORT'?$entry-$mark:$mark-$entry)*$qty);
            else $equity+=$qty*$mark;}
        return ['equity'=>$equity];
    }

    private function storeEquity(int $portfolioId,float $equity): array
    {
        $q=$this->db->prepare('SELECT peak_equity,max_drawdown_pct FROM arbitrage_strategy_portfolios WHERE id=? FOR UPDATE');$q->execute([$portfolioId]);$p=$q->fetch(PDO::FETCH_ASSOC);
        if(!$p)throw new \RuntimeException('PORTFOLIO_NOT_FOUND');$peak=max((float)$p['peak_equity'],$equity);$drawdown=$peak>0?($peak-$equity)/$peak*100:0;
        $maxDd=max((float)$p['max_drawdown_pct'],$drawdown);$status=$maxDd>=10?'KILL_SWITCH':'OBSERVATION';
        $this->db->prepare('UPDATE arbitrage_strategy_portfolios SET equity=?,peak_equity=?,max_drawdown_pct=?,status=? WHERE id=?')
            ->execute([$equity,$peak,$maxDd,$status,$portfolioId]);return ['equity'=>$equity,'peak_equity'=>$peak,'drawdown_pct'=>$drawdown,
                'max_drawdown_pct'=>$maxDd,'kill_switch_active'=>$status==='KILL_SWITCH'];
    }

    private function utcTimestamp(string $value): int
    {
        $ts=strtotime($value.' UTC');if($ts===false)throw new \InvalidArgumentException('INVALID_UTC_TIMESTAMP');return $ts;
    }

    private function positionPortfolioId(int $positionId): int
    {
        $q=$this->db->prepare('SELECT portfolio_id FROM arbitrage_strategy_positions WHERE id=?');$q->execute([$positionId]);
        $id=$q->fetchColumn();if(!$id)throw new \RuntimeException('POSITION_NOT_FOUND');return (int)$id;
    }
}
