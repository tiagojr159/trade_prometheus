<?php
declare(strict_types=1);

namespace Prometheus\core;

use PDO;

/** Long/short, no-leverage paper simulator with an auditable point-in-time decision ledger. */
final class PaperTrader
{
    private StrategyDecisionEngine $decisionEngine;

    public function __construct(?StrategyDecisionEngine $decisionEngine = null)
    {
        $this->decisionEngine = $decisionEngine ?: new StrategyDecisionEngine();
    }

    public function run(string $symbol = 'BTCUSDT'): array
    {
        $results = [];
        foreach (['DIRECTIONAL', 'STRATEGY'] as $mode) {
            try {
                $results[$mode] = $this->runMode($mode, $symbol);
            } catch (\Throwable $e) {
                $this->heartbeatError($mode, $e->getMessage());
                $results[$mode] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }
        return ['status' => 'completed', 'modes' => $results];
    }

    private function runMode(string $mode, string $symbol): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $configStmt = $pdo->prepare('SELECT * FROM paper_trading_config WHERE mode=?');
            $configStmt->execute([$mode]);
            $config = $configStmt->fetch(PDO::FETCH_ASSOC);
            $accountStmt = $pdo->prepare('SELECT * FROM paper_trading_v2_accounts WHERE mode=? FOR UPDATE');
            $accountStmt->execute([$mode]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
            if (!$config || !$account) throw new \RuntimeException('A migration 011 do paper trading ainda não foi aplicada.');
            $pdo->prepare('UPDATE paper_trading_heartbeat SET status=?,last_started_at=NOW(),last_error=NULL WHERE mode=?')->execute([(int)$config['enabled'] ? 'ACTIVE' : 'STOPPED', $mode]);
            if (!(int)$config['enabled']) {
                $pdo->commit();
                return ['status' => 'stopped', 'processed' => 0];
            }

            $market = $this->latestExecutablePrice($pdo, $symbol);
            if (!$market || !$this->isFreshMarket($market)) throw new \RuntimeException('Sem candle fechado, recente e disponível para execução simulada.');

            $predictions = Database::fetchAll(
                'SELECT p.*,1 AS is_fresh FROM predictions p LEFT JOIN paper_trading_decisions d ON d.prediction_id=p.id AND d.mode=?
                 WHERE p.symbol=? AND p.horizon="15m" AND d.id IS NULL AND p.created_at<=NOW()
                   AND p.created_at>=DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                 ORDER BY p.created_at DESC,p.id DESC LIMIT 1',
                [$mode, $symbol]
            );
            $processed = 0; $lastDecision = null; $lastPrediction = null; $lastReason = null;
            foreach ($predictions as $p) {
                $before = $account['position_side'];
                $freshPrediction = (int)($p['is_fresh']??0)===1;
                $fresh = $freshPrediction && (float)$market['close_price'] > 0;
                $horizons = $mode === 'STRATEGY' ? $this->knownHorizons($pdo, $symbol, (string)$p['created_at']) : [];
                $evidence = $mode === 'STRATEGY' ? $this->historicalMagnitude($pdo, $p) : [];
                $decision = $this->decisionEngine->decide($mode, $p, $horizons, $account, $config, $evidence, $fresh);
                $decision['position_before'] = $before;

                $lastOrder = $pdo->prepare('SELECT MAX(created_at) FROM paper_trading_v2_orders WHERE mode=?');
                $lastOrder->execute([$mode]);
                $lastOrderAt = $lastOrder->fetchColumn();
                $withinCooldown = $lastOrderAt && (time() - strtotime((string)$lastOrderAt)) < (int)$config['cooldown_minutes'] * 60;
                $actionWantsOpen = strpos((string)$decision['action'], 'OPEN_') !== false;
                if ($withinCooldown && $actionWantsOpen) {
                    // During cooldown, allow a signal reversal to close the current
                    // position but never open the opposite side in the same event.
                    $pairedClose = strpos((string)$decision['action'], '+OPEN_');
                    if (strpos((string)$decision['action'], 'CLOSE_') === 0 && $pairedClose !== false) {
                        $decision['action'] = substr((string)$decision['action'], 0, $pairedClose);
                        $decision['reason_codes'] = ['COOLDOWN', 'DIRECTION_REVERSAL'];
                        $decision['explanation'] = 'O intervalo mínimo entre ordens está ativo; fecha a posição e aguarda antes de abrir a direção oposta.';
                    } else {
                        $decision['action'] = 'NO_TRADE';
                        $decision['reason_codes'] = ['COOLDOWN'];
                        $decision['explanation'] = 'O intervalo mínimo entre ordens está ativo; aguarda antes de abrir uma nova posição.';
                    }
                }

                $riskExit = $this->riskExit($account, (float)$market['close_price'], $config);
                if ($riskExit !== null && $account['position_side'] !== 'FLAT') {
                    $decision['action'] = 'CLOSE_' . $account['position_side'];
                    $decision['reason_codes'] = ['RISK_EXIT'];
                    $decision['explanation'] = $riskExit;
                }

                $regime = Database::fetch('SELECT regime,confidence FROM market_regimes WHERE created_at<=? ORDER BY created_at DESC,id DESC LIMIT 1', [$p['created_at']]);
                $p['regime']=(string)($regime['regime']??$p['regime']);
                $p['regime_confidence']=$regime['confidence']??null;
                $estimatedCost = (float)$decision['estimated_cost_pct'];
                $after = $this->targetSide((string)$decision['action'], $before);
                $insertDecision = $pdo->prepare(
                    'INSERT INTO paper_trading_decisions (mode,prediction_id,prediction_created_at,horizon,predicted_direction,probability_up,probability_down,edge,confidence,regime,regime_confidence,ensemble_signal,strategy_score,expected_edge_pct,estimated_cost_pct,position_before,position_after,decision,reason_codes,reason,signals_json,weights_json,market_data_id,price_timestamp,available_at,ingested_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $insertDecision->execute([
                    $mode,$p['id'],$p['created_at'],$p['horizon'],$p['predicted_direction'],$p['probability_up'],$p['probability_down'],$p['edge'],$p['confidence'],
                    $regime['regime'] ?? $p['regime'],$regime['confidence'] ?? null,$p['ensemble_signal'],$decision['score'],$decision['expected_edge_pct'],$estimatedCost,
                    $before,$after,$decision['action'],json_encode($decision['reason_codes'], JSON_UNESCAPED_UNICODE),$decision['explanation'],$p['signals_json'],$p['weights_json'],$market['id'],$market['close_time'],$market['available_at'],$market['ingested_at']
                ]);
                $decisionId = (int)$pdo->lastInsertId();
                $action = (string)$decision['action'];
                $reason = (string)$decision['explanation'];
                if (strpos($action, 'CLOSE_') === 0 || strpos($action, 'CLOSE_') !== false) {
                    if ($account['position_side'] !== 'FLAT') $this->closePosition($pdo,$mode,$account,$market,$p,$decision,$decisionId,$config,$reason);
                }
                if (strpos($action, 'OPEN_') === 0 || strpos($action, '+OPEN_') !== false) {
                    $side = strpos($action, 'OPEN_SHORT') !== false ? 'SHORT' : 'LONG';
                    if ($account['position_side'] === 'FLAT') $this->openPosition($pdo,$mode,$account,$market,$p,$decision,$decisionId,$config,$side,$reason);
                }

                $equity = $this->equity($account, (float)$market['close_price']);
                $peak = max((float)$account['peak_equity'], $equity);
                $dd = $peak > 0 ? max((float)$account['max_drawdown_pct'], ($peak - $equity) / $peak * 100) : 0.0;
                $account['peak_equity'] = $peak; $account['max_drawdown_pct'] = $dd;
                $update = $pdo->prepare('UPDATE paper_trading_v2_accounts SET peak_equity=?,max_drawdown_pct=? WHERE mode=?');
                $update->execute([$peak,$dd,$mode]);
                $this->equitySnapshot($pdo,$mode,$p,$market,$account,$equity);
                $pdo->prepare('UPDATE paper_trading_decisions SET position_after=? WHERE id=?')->execute([$account['position_side'],$decisionId]);
                $processed++; $lastDecision=$action; $lastPrediction=(int)$p['id']; $lastReason=$reason;
            }

            $latestHandled = Database::fetch('SELECT last_prediction_id,last_decision,last_reason FROM paper_trading_heartbeat WHERE mode=?', [$mode]);
            $heartbeatId = $lastPrediction ?? ($latestHandled['last_prediction_id'] ?? null);
            $heartbeatDecision = $lastDecision ?? ($latestHandled['last_decision'] ?? 'NO_TRADE');
            $heartbeatReason = $processed ? $lastReason : ($latestHandled['last_reason'] ?? 'Nenhuma previsão nova de 15m aguardando decisão.');
            if ($processed === 0) {
                $equity=$this->equity($account,(float)$market['close_price']);
                $peak=max((float)$account['peak_equity'],$equity);
                $dd=$peak>0?max((float)$account['max_drawdown_pct'],($peak-$equity)/$peak*100):0.0;
                $account['peak_equity']=$peak;$account['max_drawdown_pct']=$dd;
                $pdo->prepare('UPDATE paper_trading_v2_accounts SET peak_equity=?,max_drawdown_pct=? WHERE mode=?')->execute([$peak,$dd,$mode]);
                $this->equitySnapshot($pdo,$mode,null,$market,$account,$equity);
            }
            $pdo->prepare('UPDATE paper_trading_heartbeat SET status="ACTIVE",last_finished_at=NOW(),last_prediction_id=?,last_decision=?,last_reason=?,last_error=NULL,next_evaluation_at=DATE_ADD(NOW(),INTERVAL 1 MINUTE) WHERE mode=?')
                ->execute([$heartbeatId,$heartbeatDecision,$heartbeatReason,$mode]);
            $pdo->commit();
            return ['status' => 'active', 'processed' => $processed, 'last_prediction_id' => $lastPrediction, 'last_decision' => $lastDecision];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function latestExecutablePrice(PDO $pdo, string $symbol): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id,close_price,open_time,close_time,available_at,ingested_at FROM market_data
             WHERE symbol=? AND interval_name=? AND close_time<=NOW() AND ingested_at IS NOT NULL AND ingested_at<=UTC_TIMESTAMP()
               AND temporal_quality IN ("EXACT","INGESTION_ONLY")
               AND (available_at IS NULL OR (available_at<=UTC_TIMESTAMP() AND ingested_at>=available_at))
             ORDER BY close_time DESC,open_time DESC LIMIT 1'
        );
        $stmt->execute([$symbol,prometheus_config('collector.market_interval','1m')]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function isFreshMarket(array $market): bool
    {
        $interval = (string)prometheus_config('collector.market_interval', '1m');
        if (!preg_match('/^(\\d+)([mhd])$/', $interval, $matches)) return false;
        $seconds = (int)$matches[1] * ['m'=>60,'h'=>3600,'d'=>86400][$matches[2]];
        $closeTimestamp = strtotime((string)$market['close_time']);
        return $closeTimestamp !== false && $closeTimestamp <= time() && time() - $closeTimestamp <= max(300, $seconds * 2);
    }

    private function knownHorizons(PDO $pdo, string $symbol, string $asOf): array
    {
        $out=[];
        foreach (['15m'=>1800,'1h'=>7200,'4h'=>28800,'24h'=>172800] as $horizon=>$maxAge) {
            $stmt=$pdo->prepare('SELECT * FROM predictions WHERE symbol=? AND horizon=? AND created_at<=? AND created_at>=DATE_SUB(?,INTERVAL ? SECOND) ORDER BY created_at DESC,id DESC LIMIT 1');
            $stmt->execute([$symbol,$horizon,$asOf,$asOf,$maxAge]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC); if($row)$out[$horizon]=$row;
        }
        return $out;
    }

    private function historicalMagnitude(PDO $pdo, array $p): array
    {
        $rows=Database::fetch('SELECT COUNT(*) n,AVG(ABS(r.return_pct)) avg_abs_return_pct FROM predictions p JOIN prediction_results r ON r.prediction_id=p.id WHERE p.horizon=? AND p.regime=? AND p.created_at<? AND r.evaluated_at<=? AND r.evaluation_version=2 AND r.actual_direction IN ("UP","DOWN")',[$p['horizon'],$p['regime'],$p['created_at'],$p['created_at']]);
        return ['n'=>(int)($rows['n']??0),'avg_abs_return_pct'=>$rows&&$rows['avg_abs_return_pct']!==null?(float)$rows['avg_abs_return_pct']:null];
    }

    private function riskExit(array $account, float $price, array $config): ?string
    {
        if ($account['position_side']==='FLAT'||(float)$account['quantity_btc']<=0||(float)$account['entry_price']<=0) return null;
        $entry=(float)$account['entry_price']; $side=$account['position_side'];
        $return=(($price-$entry)/$entry*100)*($side==='LONG'?1:-1);
        if($return<=-(float)$config['stop_loss_pct'])return 'Stop de risco atingido; encerra a posição simulada.';
        if($return>=(float)$config['take_profit_pct'])return 'Alvo de risco atingido; encerra a posição simulada.';
        if($account['entry_at']&&time()-strtotime((string)$account['entry_at'])>=(int)$config['max_position_minutes']*60)return 'Tempo máximo da posição atingido.';
        return null;
    }

    private function openPosition(PDO $pdo,string $mode,array &$account,array $market,array $p,array $decision,int $decisionId,array $config,string $side,string $reason): void
    {
        $reference=(float)$market['close_price']; $slipPct=(float)$config['slippage_pct'];
        $fill=$reference*($side==='LONG'?1+$slipPct/100:1-$slipPct/100);
        $equity=max(0.0,$this->equity($account,$reference)); $budget=$equity*min(100.0,(float)$config['allocation_pct'])/100.0;
        if($side==='LONG')$budget=min($budget,(float)$account['cash_balance']);
        else $budget=min($budget,(float)$account['cash_balance']);
        $qty=$budget/max(1e-12,$fill*(1+(float)$config['fee_pct']/100));
        if($qty<=0)return;
        $notional=$fill*$qty; $fee=$notional*(float)$config['fee_pct']/100; $slippage=abs($fill-$reference)*$qty;
        if($side==='LONG')$account['cash_balance']=max(0.0,(float)$account['cash_balance']-$notional-$fee);
        else {$account['cash_balance']=max(0.0,(float)$account['cash_balance']-$budget-$fee);$account['reserved_cash']=$budget;}
        $account['position_side']=$side;$account['quantity_btc']=$qty;$account['entry_price']=$fill;
        $account['entry_market_data_id']=$market['id'];$account['entry_prediction_id']=$p['id'];$account['entry_at']=$market['close_time'];
        $account['entry_reason']=$reason;$account['entry_fee_usd']=$fee;$account['entry_slippage_usd']=$slippage;
        $account['total_fees']=(float)$account['total_fees']+$fee;$account['total_slippage']=(float)$account['total_slippage']+$slippage;
        $this->writeOrder($pdo,$mode,$account,$market,$p,$decision,$decisionId,$side==='LONG'?'OPEN_LONG':'OPEN_SHORT',$reference,$fill,$qty,$fee,$slippage,0.0,$reason,'FLAT');
        $this->persistAccount($pdo,$mode,$account);
    }

    private function closePosition(PDO $pdo,string $mode,array &$account,array $market,array $p,array $decision,int $decisionId,array $config,string $reason): void
    {
        $side=(string)$account['position_side']; if($side==='FLAT')return;
        $reference=(float)$market['close_price'];$qty=(float)$account['quantity_btc'];$entry=(float)$account['entry_price'];
        $fill=$reference*($side==='LONG'?1-(float)$config['slippage_pct']/100:1+(float)$config['slippage_pct']/100);
        $notional=$fill*$qty;$fee=$notional*(float)$config['fee_pct']/100;
        $gross=($fill-$entry)*$qty*($side==='LONG'?1:-1);$slippage=abs($fill-$reference)*$qty;
        $allFees=(float)$account['entry_fee_usd']+$fee;$allSlippage=(float)$account['entry_slippage_usd']+$slippage;
        $net=$gross-$allFees;
        if($side==='LONG')$account['cash_balance']+=(float)$fill*$qty-$fee;
        else $account['cash_balance']+=(float)$account['reserved_cash']+$gross-$fee;
        $account['cash_balance']=max(0.0,(float)$account['cash_balance']);
        $pdo->prepare('INSERT INTO paper_trading_v2_trades (mode,side,entry_prediction_id,exit_prediction_id,entry_market_data_id,exit_market_data_id,entry_at,exit_at,entry_price,exit_price,quantity_btc,gross_pnl,fees_usd,slippage_usd,net_pnl,entry_reason,exit_reason,hold_seconds) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$mode,$side,$account['entry_prediction_id'],$p['id'],$account['entry_market_data_id'],$market['id'],$account['entry_at'],$market['close_time'],$entry,$fill,$qty,$gross,$allFees,$allSlippage,$net,$account['entry_reason'],$reason,max(0,time()-strtotime((string)$account['entry_at']))]);
        $account['realized_pnl']=(float)$account['realized_pnl']+$net;$account['total_fees']=(float)$account['total_fees']+$fee;$account['total_slippage']=(float)$account['total_slippage']+$slippage;
        $account['position_side']='FLAT';$account['quantity_btc']=0;$account['entry_price']=null;$account['entry_market_data_id']=null;$account['entry_prediction_id']=null;$account['entry_at']=null;$account['entry_reason']=null;$account['entry_fee_usd']=0;$account['entry_slippage_usd']=0;$account['reserved_cash']=0;
        $this->writeOrder($pdo,$mode,$account,$market,$p,$decision,$decisionId,$side==='LONG'?'CLOSE_LONG':'CLOSE_SHORT',$reference,$fill,$qty,$fee,$slippage,$net,$reason,$side);
        $this->persistAccount($pdo,$mode,$account);
    }

    private function writeOrder(PDO $pdo,string $mode,array $account,array $market,array $p,array $decision,int $decisionId,string $event,float $reference,float $fill,float $qty,float $fee,float $slippage,float $realized,string $reason,string $positionBefore): void
    {
        $pdo->prepare('INSERT INTO paper_trading_v2_orders (mode,decision_id,prediction_id,prediction_created_at,horizon,predicted_direction,probability_up,probability_down,edge,confidence,regime,regime_confidence,ensemble_signal,strategy_score,position_before,position_after,event_type,market_data_id,price_timestamp,available_at,ingested_at,reference_price,fill_price,entry_price,exit_price,quantity_btc,notional_usd,fee_usd,slippage_usd,realized_pnl,unrealized_pnl,cash_after,reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$mode,$decisionId,$p['id'],$p['created_at'],$p['horizon'],$p['predicted_direction'],$p['probability_up'],$p['probability_down'],$p['edge'],$p['confidence'],$p['regime'],$p['regime_confidence']??null,$p['ensemble_signal'],$decision['score'],$positionBefore,$account['position_side'],$event,$market['id'],$market['close_time'],$market['available_at'],$market['ingested_at'],$reference,$fill,$event==='OPEN_LONG'||$event==='OPEN_SHORT'?$fill:null,$event==='CLOSE_LONG'||$event==='CLOSE_SHORT'?$fill:null,$qty,$fill*$qty,$fee,$slippage,$realized,$this->unrealized($account,$reference),$account['cash_balance'],$reason]);
    }

    private function persistAccount(PDO $pdo,string $mode,array $a): void
    {
        $pdo->prepare('UPDATE paper_trading_v2_accounts SET cash_balance=?,position_side=?,quantity_btc=?,entry_price=?,entry_market_data_id=?,entry_prediction_id=?,entry_at=?,entry_reason=?,entry_fee_usd=?,entry_slippage_usd=?,reserved_cash=?,realized_pnl=?,total_fees=?,total_slippage=? WHERE mode=?')
            ->execute([$a['cash_balance'],$a['position_side'],$a['quantity_btc'],$a['entry_price'],$a['entry_market_data_id'],$a['entry_prediction_id'],$a['entry_at'],$a['entry_reason'],$a['entry_fee_usd'],$a['entry_slippage_usd'],$a['reserved_cash'],$a['realized_pnl'],$a['total_fees'],$a['total_slippage'],$mode]);
    }

    private function equity(array $pdoAccount,float $price): float
    {
        $side=$pdoAccount['position_side']??'FLAT';$qty=(float)($pdoAccount['quantity_btc']??0);$cash=(float)($pdoAccount['cash_balance']??0);
        if($side==='LONG')return $cash+$qty*$price;
        if($side==='SHORT')return max(0.0,$cash+(float)($pdoAccount['reserved_cash']??0)+$this->unrealized($pdoAccount,$price));
        return $cash;
    }

    public static function markedEquity(array $account,float $price): float
    {
        $side=$account['position_side']??'FLAT';$qty=(float)($account['quantity_btc']??0);$cash=(float)($account['cash_balance']??0);
        if($side==='LONG')return max(0.0,$cash+$qty*$price);
        if($side==='SHORT'){
            $unrealized=($price-(float)($account['entry_price']??0))*$qty*-1;
            return max(0.0,$cash+(float)($account['reserved_cash']??0)+$unrealized);
        }
        return max(0.0,$cash);
    }

    private function unrealized(array $a,float $price): float
    {
        if(($a['position_side']??'FLAT')==='FLAT')return 0.0;
        return ($price-(float)$a['entry_price'])*(float)$a['quantity_btc']*(($a['position_side']??'')==='LONG'?1:-1);
    }

    private function equitySnapshot(PDO $pdo,string $mode,?array $p,array $market,array $account,float $equity): void
    {
        $pdo->prepare('INSERT INTO paper_trading_equity (mode,prediction_id,market_data_id,price_timestamp,available_at,ingested_at,reference_price,cash_balance,position_side,quantity_btc,equity,realized_pnl,unrealized_pnl) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$mode,$p['id']??null,$market['id'],$market['close_time'],$market['available_at'],$market['ingested_at'],$market['close_price'],$account['cash_balance'],$account['position_side'],$account['quantity_btc'],$equity,$account['realized_pnl'],$this->unrealized($account,(float)$market['close_price'])]);
    }

    private function targetSide(string $action,string $before): string
    {
        if(strpos($action,'OPEN_SHORT')!==false)return 'SHORT';
        if(strpos($action,'OPEN_LONG')!==false)return 'LONG';
        if(strpos($action,'CLOSE_')===0)return 'FLAT';
        return $before;
    }

    private function heartbeatError(string $mode,string $message): void
    {
        try { Database::execute('UPDATE paper_trading_heartbeat SET status="ERROR",last_finished_at=NOW(),last_error=? WHERE mode=?', [mb_substr($message,0,2000),$mode]); }
        catch (\Throwable $ignored) { /* original cron failure remains the primary error */ }
    }
}
