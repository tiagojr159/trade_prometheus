<?php
declare(strict_types=1);

namespace Prometheus\core;

use DateTimeImmutable;
use PDO;

final class QuantTournament
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        if (!extension_loaded('bcmath')) throw new \RuntimeException('QUANT_BCMATH_REQUIRED');
        $this->db = $db;
    }

    public function start(string $key, string $symbol, DateTimeImmutable $now, array $evidence = []): int
    {
        if (!preg_match('/^[a-z0-9_-]{1,64}$/D', $key) || $symbol !== 'BTCUSDT') {
            throw new \InvalidArgumentException('INVALID_SEASON_OR_SYMBOL');
        }
        $definition = QuantPolicy::definition();
        $definition['validated_lower_bound_bps'] = $this->validateEvidence($definition, $evidence, $now);
        $policy = json_encode($definition, JSON_THROW_ON_ERROR);
        if (array_keys(QuantPolicy::definition()['strategies']) !== array_keys(PaperStrategyEngine::modes())) {
            throw new \LogicException('STRATEGY_REGISTRY_MISMATCH');
        }
        return $this->transaction(function () use ($key, $symbol, $now, $policy): int {
            $at = QuantClock::sql($now);
            $hash = hash('sha256', $policy);
            $this->query('INSERT INTO quant_tournament_seasons
                (season_key,symbol,started_at_utc,policy_sha256,policy_json) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', [$key, $symbol, $at, $hash, $policy]);
            $id = (int)$this->db->lastInsertId();
            $season = $this->query('SELECT * FROM quant_tournament_seasons WHERE id=?', [$id])->fetch();
            if ($season['symbol'] !== $symbol || !hash_equals($season['policy_sha256'], $hash)) {
                throw new \RuntimeException('SEASON_POLICY_MISMATCH');
            }
            $existing = (int)$this->query('SELECT COUNT(*) FROM quant_tournament_accounts WHERE season_id=?', [$id])->fetchColumn();
            if ($existing === 15) return $id;
            if ($existing !== 0) throw new \RuntimeException('SEASON_INCOMPLETE');
            foreach (array_keys(QuantPolicy::definition()['strategies']) as $strategy) {
                $this->query('INSERT INTO quant_tournament_accounts
                    (season_id,strategy_key,strategy_version,initial_equity,cash,peak_equity,next_decision_at_utc)
                    VALUES (?,?,?,100,100,100,?)', [$id, $strategy, QuantPolicy::VERSION, $at]);
            }
            return $id;
        });
    }

    public function tick(string $symbol, DateTimeImmutable $now): array
    {
        $at = QuantClock::sql($now);
        $runId = bin2hex(random_bytes(8));
        $started = microtime(true);
        $seasons = $this->query('SELECT * FROM quant_tournament_seasons WHERE symbol=? AND started_at_utc<=?', [$symbol, $at])
            ->fetchAll(PDO::FETCH_ASSOC);
        if (!$seasons) return ['status' => 'NO_SEASON', 'run_id' => $runId];
        $candles = [QuantMarket::candles($this->db, $symbol, '1m', $now), QuantMarket::candles($this->db, $symbol, '15m', $now)];
        $results = [];
        foreach ($seasons as $season) {
            $policy = $this->readPolicy($season);
            $ids = $this->query('SELECT id FROM quant_tournament_accounts WHERE season_id=? ORDER BY id', [$season['id']])
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                if (microtime(true) - $started > 20) {
                    return ['status' => 'RUNTIME_LIMIT', 'run_id' => $runId,
                        'elapsed_ms' => (int)round(1000 * (microtime(true) - $started)), 'accounts' => $results];
                }
                try {
                    $results[$id] = $this->transaction(function () use ($id, $season, $now, $candles, $policy): string {
                        return $this->accountTick((int)$id, $season, $now, $candles, $policy);
                    });
                } catch (\Throwable $e) {
                    $results[$id] = 'ERROR';
                    Logger::warning('quant_account_tick_failed', [
                        'run_id' => $runId, 'season_id' => (int)$season['id'], 'account_id' => (int)$id,
                        'elapsed_ms' => (int)round(1000 * (microtime(true) - $started)), 'error_class' => get_class($e),
                    ]);
                }
            }
        }
        return ['status' => 'COMPLETED', 'run_id' => $runId,
            'elapsed_ms' => (int)round(1000 * (microtime(true) - $started)), 'accounts' => $results];
    }

    private function accountTick(int $id, array $season, DateTimeImmutable $now, array $candles, array $policy): string
    {
        $at = QuantClock::sql($now);
        $account = $this->query('SELECT * FROM quant_tournament_accounts WHERE id=? FOR UPDATE', [$id])->fetch(PDO::FETCH_ASSOC);
        if ($account['last_tick_at_utc'] !== null && $account['last_tick_at_utc'] >= $at) return 'ALREADY_PROCESSED';
        $intent = $this->query('SELECT * FROM quant_trade_intents WHERE account_id=? AND status=? ORDER BY decision_id LIMIT 1',
            [$id, 'PENDING'])->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($intent && $intent['expires_at_utc'] !== null && $intent['expires_at_utc'] <= $at) {
            $this->finishIntent($intent, 'EXPIRED');
            $intent = null;
        }

        $quotes = $this->query('SELECT * FROM quant_spot_quotes WHERE symbol=? AND id>? AND requested_at_utc>=?
            AND ingested_at_utc<=? ORDER BY id LIMIT 121', [$season['symbol'], $account['last_quote_id'] ?? 0,
                $account['last_quote_id'] ? $season['started_at_utc'] : max($season['started_at_utc'], QuantClock::sql($now->modify('-120 seconds'))), $at])
            ->fetchAll(PDO::FETCH_ASSOC);
        $backlog = count($quotes) > 120;
        if ($backlog) array_pop($quotes);
        $freshSince = QuantClock::sql($now->modify('-120 seconds'));
        $quote = null;
        foreach ($quotes as $quote) {
            $previous = $account['last_quote_at_utc'];
            if ($previous !== null && bccomp($account['quantity'], '0', 12) > 0) {
                $gap = QuantClock::fromSql($quote['ingested_at_utc'])->getTimestamp()
                    - QuantClock::fromSql($previous)->getTimestamp();
                if ($gap > 120) {
                    $this->decide($account, $quote, $now, $policy, 'gap:' . $quote['id'], 'NO_TRADE',
                        'QUOTE_GAP_WITH_EXPOSURE', ['gap_seconds' => $gap], null);
                }
            }
            if ($intent && $quote['requested_at_utc'] > $intent['eligible_after_utc']
                && ($intent['expires_at_utc'] === null || $quote['ingested_at_utc'] < $intent['expires_at_utc'])
                && ($intent['cash_budget'] === null || (!$backlog && $quote['requested_at_utc'] >= $freshSince))) {
                $intent = $this->applyFill($account, $intent, $quote, $policy);
            }
            $risk = $this->mark($account, $quote, $policy);
            if ($risk !== null && (!$intent || $intent['cash_budget'] !== null)) {
                if ($intent) $this->finishIntent($intent, 'CANCELLED_RISK');
                $intent = $this->decide($account, $quote, $now, $policy, 'risk:' . $quote['id'], 'CLOSE_LONG', $risk, [], null);
            }
            $account['last_quote_id'] = $quote['id'];
            $account['last_quote_at_utc'] = $quote['ingested_at_utc'];
        }

        $status = $account['status'];
        if ($status !== 'PAUSED_RISK') {
            $status = $backlog ? 'RECONCILING' : ($quote && $quote['requested_at_utc'] >= $freshSince ? 'ACTIVE' : 'WAITING_DATA');
        }
        $next = $account['next_decision_at_utc'];
        if (!$backlog && $next <= $at) {
            [$cadence, $horizon] = $policy['strategies'][$account['strategy_key']];
            if ($status !== 'ACTIVE' || $intent !== null) {
                $action = 'NO_TRADE';
                $reason = $intent !== null ? 'INTENT_PENDING' : $status;
                $detail = ['horizon' => $horizon];
            } else {
                [$action, $reason, $detail] = (new QuantStrategy())->evaluate($account, $candles, $policy);
            }
            $budget = null;
            if ($action === 'OPEN_LONG') {
                [$reason, $budget] = $this->entryBudget($account, $quote, $now, $policy, $detail);
                if ($budget === null) $action = 'NO_TRADE';
            }
            $this->decide($account, $quote, $now, $policy, 'decision:' . intdiv($now->getTimestamp(), $cadence),
                $action, $reason, $detail, $budget);
            $next = QuantClock::sql($now->setTimestamp((intdiv($now->getTimestamp(), $cadence) + 1) * $cadence));
        }
        $this->query('UPDATE quant_tournament_accounts SET cash=?,quantity=?,cost_basis=?,opened_at_utc=?,entries=?,
            realized_pnl=?,peak_equity=?,max_drawdown_bps=?,last_quote_id=?,last_quote_at_utc=?,last_fill_at_utc=?,
            last_tick_at_utc=?,next_decision_at_utc=?,status=? WHERE id=?',
            [$account['cash'], $account['quantity'], $account['cost_basis'], $account['opened_at_utc'], $account['entries'],
                $account['realized_pnl'], $account['peak_equity'], $account['max_drawdown_bps'], $account['last_quote_id'],
                $account['last_quote_at_utc'],
                $account['last_fill_at_utc'], $at, $next, $status, $id]);
        return $status;
    }

    private function entryBudget(array $account, array $quote, DateTimeImmutable $now, array $policy, array &$detail): array
    {
        if ($account['last_fill_at_utc'] !== null
            && $account['last_fill_at_utc'] > QuantClock::sql($now->modify('-' . $policy['cooldown_seconds'] . ' seconds'))) {
            return ['COOLDOWN', null];
        }
        $spread = QuantSpotExecution::spreadBps($quote);
        $cost = $spread + 20000 * ((float)$policy['fee_rate'] + (float)$policy['impact_rate']);
        $detail['cost_bps'] = $cost;
        if ($spread > $policy['max_spread_bps']) return ['SPREAD_TOO_WIDE', null];
        if ($detail['heuristic_pct'] * 100 <= $cost + $policy['entry_buffer_bps']) return ['EDGE_TOO_LOW', null];
        // The technical movement estimate is an entry hypothesis. The season policy
        // must carry independently validated lower bounds before it can authorize buys.
        $evidence = $policy['validated_lower_bound_bps'][$account['strategy_key']] ?? null;
        if ($evidence === null) return ['INSUFFICIENT_EDGE_EVIDENCE', null];
        $detail['evidence'] = $evidence;
        if ($evidence['gross_lcb_bps'] <= $cost + $policy['entry_buffer_bps']) return ['EDGE_TOO_LOW', null];
        $allocation = min($policy['max_allocation'], bcdiv($policy['risk_allocation'],
            number_format($policy['stop_pct'] / 100 + $cost / 10000, 12, '.', ''), 8));
        $budget = bcmul($account['cash'], $allocation, 8);
        return bccomp($budget, $policy['min_notional'], 8) < 0 ? ['MIN_NOTIONAL', null] : ['TECHNICAL_SIGNAL', $budget];
    }

    private function applyFill(array &$account, array $intent, array $quote, array $policy): ?array
    {
        $buy = $intent['cash_budget'] !== null;
        if ($buy) {
            $spread = QuantSpotExecution::spreadBps($quote);
            $cost = $spread + 20000 * ((float)$policy['fee_rate'] + (float)$policy['impact_rate']);
            $evidence = $policy['validated_lower_bound_bps'][$account['strategy_key']]['gross_lcb_bps'] ?? 0;
            if ($spread > $policy['max_spread_bps'] || $evidence <= $cost + $policy['entry_buffer_bps']) {
                $this->finishIntent($intent, $spread > $policy['max_spread_bps'] ? 'SPREAD_TOO_WIDE' : 'EDGE_TOO_LOW');
                return null;
            }
        }
        $fill = QuantSpotExecution::fill($account, $intent, $quote, $policy);
        if (isset($fill['reason'])) {
            if (!$buy || $fill['reason'] === 'INSUFFICIENT_TOP_OF_BOOK') return $intent;
            $this->finishIntent($intent, $fill['reason']);
            return null;
        }
        $at = $quote['ingested_at_utc'];
        $this->query('INSERT INTO quant_spot_fills (decision_id,quote_id,quantity_delta,cash_delta,fill_price,fee,realized_pnl,filled_at_utc)
            VALUES (?,?,?,?,?,?,?,?)', [$intent['decision_id'], $quote['id'], $fill['qty_delta'], $fill['cash_delta'],
                $fill['price'], $fill['fee'], $fill['realized_pnl'], $at]);
        $this->finishIntent($intent, 'FILLED');
        $account['cash'] = bcadd($account['cash'], $fill['cash_delta'], 8);
        $account['quantity'] = bcadd($account['quantity'], $fill['qty_delta'], 12);
        $account['cost_basis'] = bcadd($account['cost_basis'], $fill['basis_delta'], 8);
        $account['realized_pnl'] = bcadd($account['realized_pnl'], $fill['realized_pnl'], 8);
        $account['last_fill_at_utc'] = $at;
        if ($buy) {
            $account['opened_at_utc'] = $account['opened_at_utc'] ?? $at;
            $account['entries']++;
        } elseif (bccomp($account['quantity'], '0', 12) === 0) {
            $account['opened_at_utc'] = null;
            $account['entries'] = 0;
        }
        return null;
    }

    private function mark(array &$account, array $quote, array $policy): ?string
    {
        $liquidationValue = QuantSpotExecution::mark($account, $quote, $policy);
        $equity = bcadd($account['cash'], $liquidationValue, 8);
        $pnl = bcsub($liquidationValue, $account['cost_basis'], 8);
        if (bccomp($equity, $account['peak_equity'], 8) > 0) $account['peak_equity'] = $equity;
        $drawdown = bcmul(bcsub('1', bcdiv($equity, $account['peak_equity'], 12), 12), '10000', 4);
        if (bccomp($drawdown, $account['max_drawdown_bps'], 4) > 0) $account['max_drawdown_bps'] = $drawdown;
        $this->query('INSERT INTO quant_equity_snapshots (account_id,quote_id,as_of_utc,cash,quantity,equity,unrealized_pnl)
            VALUES (?,?,?,?,?,?,?)', [$account['id'], $quote['id'], $quote['ingested_at_utc'], $account['cash'],
                $account['quantity'], $equity, $pnl]);
        if ((float)$drawdown >= $policy['drawdown_bps']) $account['status'] = 'PAUSED_RISK';
        if (bccomp($account['quantity'], '0', 12) === 0) return null;
        if ($account['status'] === 'PAUSED_RISK') return 'DRAWDOWN_LIMIT';
        $move = (float)$pnl / (float)$account['cost_basis'] * 100;
        if ($move <= -$policy['stop_pct']) return 'STOP_LOSS';
        if ($move >= $policy['take_pct']) return 'TAKE_PROFIT';
        [, $horizon] = $policy['strategies'][$account['strategy_key']];
        if (QuantClock::fromSql($quote['ingested_at_utc'])->getTimestamp()
            - QuantClock::fromSql($account['opened_at_utc'])->getTimestamp() >= QuantStrategy::HORIZONS[$horizon]) return 'TIME_BARRIER';
        return null;
    }

    private function decide(array $account, ?array $quote, DateTimeImmutable $now, array $policy, string $key,
        string $action, string $reason, array $detail, ?string $budget): ?array
    {
        $this->query('INSERT INTO quant_decisions (account_id,event_key,as_of_utc,quote_id,action,reason_code,detail_json)
            VALUES (?,?,?,?,?,?,?)', [$account['id'], $key, QuantClock::sql($now), $quote['id'] ?? null, $action, $reason,
                json_encode($detail, JSON_THROW_ON_ERROR)]);
        if ($action === 'NO_TRADE') return null;
        $id = (int)$this->db->lastInsertId();
        $eligible = QuantClock::sql($now->modify('+' . $policy['latency_seconds'] . ' seconds'));
        $expires = $budget === null ? null : QuantClock::sql($now->modify('+' . $policy['entry_expiry_seconds'] . ' seconds'));
        $qty = $budget === null ? $account['quantity'] : null;
        $this->query('INSERT INTO quant_trade_intents (decision_id,account_id,eligible_after_utc,expires_at_utc,cash_budget,quantity,status)
            VALUES (?,?,?,?,?,?,?)', [$id, $account['id'], $eligible, $expires, $budget, $qty, 'PENDING']);
        return ['decision_id' => $id, 'eligible_after_utc' => $eligible, 'expires_at_utc' => $expires, 'cash_budget' => $budget, 'quantity' => $qty];
    }

    private function finishIntent(array $intent, string $status): void
    {
        $this->query('UPDATE quant_trade_intents SET status=? WHERE decision_id=? AND status=?', [$status, $intent['decision_id'], 'PENDING']);
    }

    private function readPolicy(array $season): array
    {
        $stored = $season['policy_json'];
        if (!hash_equals($season['policy_sha256'], hash('sha256', $stored))) {
            throw new \RuntimeException('UNSUPPORTED_SEASON_POLICY');
        }
        $policy = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        $base = $policy;
        $base['validated_lower_bound_bps'] = [];
        if ($base !== QuantPolicy::definition()) throw new \RuntimeException('UNSUPPORTED_SEASON_POLICY');
        return $policy;
    }

    private function validateEvidence(array $policy, array $evidence, DateTimeImmutable $now): array
    {
        $approved = [];
        foreach ($evidence as $key => $report) {
            if (($policy['strategies'][$key][2] ?? null) !== 'TECHNICAL' || !is_array($report)
                || ($report['policy_version'] ?? null) !== QuantPolicy::VERSION
                || ($report['horizon'] ?? null) !== $policy['strategies'][$key][1]
                || !is_int($report['independent_trades'] ?? null) || $report['independent_trades'] < 50
                || !is_numeric($report['gross_lcb_bps'] ?? null) || !is_finite((float)$report['gross_lcb_bps'])
                || (float)$report['gross_lcb_bps'] <= 0
                || !is_string($report['report_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $report['report_sha256'])) {
                throw new \InvalidArgumentException('INVALID_STRATEGY_EVIDENCE');
            }
            self::verifyPromotionGates($report, $policy);
            foreach (['started_at_utc', 'ended_at_utc'] as $field) {
                if (!is_string($report[$field] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $report[$field])) {
                    throw new \InvalidArgumentException('INVALID_STRATEGY_EVIDENCE_TIME');
                }
            }
            $start = new DateTimeImmutable($report['started_at_utc']);
            $end = new DateTimeImmutable($report['ended_at_utc']);
            if ($start->format('Y-m-d\TH:i:s\Z') !== $report['started_at_utc']
                || $end->format('Y-m-d\TH:i:s\Z') !== $report['ended_at_utc']
                || $end >= $now || $end->getTimestamp() - $start->getTimestamp() < 60 * 86400) {
                throw new \InvalidArgumentException('INVALID_STRATEGY_EVIDENCE_TIME');
            }
            $approved[$key] = $report;
        }
        ksort($approved);
        return $approved;
    }

    private static function verifyPromotionGates(array $report, array $policy): void
    {
        $metrics = $report['metrics'] ?? [];
        if (!is_array($metrics)) throw new \InvalidArgumentException('INCOMPLETE_PROMOTION_METRICS');
        foreach (['net_bps', 'legacy_net_bps', 'buy_hold_net_bps', 'incremental_lcb_bps',
            'stress_1_5_net_bps', 'stress_2_net_bps', 'max_drawdown_bps', 'dsr_probability'] as $key) {
            $value = $metrics[$key] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
                throw new \InvalidArgumentException('INCOMPLETE_PROMOTION_METRICS');
            }
        }
        if (($report['prospective'] ?? false) !== true || ($report['ledger_verified'] ?? false) !== true
            || ($report['as_of_verified'] ?? false) !== true || $metrics['net_bps'] <= 0 || $metrics['incremental_lcb_bps'] <= 0
            || $metrics['max_drawdown_bps'] < 0 || $metrics['max_drawdown_bps'] > $policy['drawdown_bps']
            || $metrics['dsr_probability'] < 0 || $metrics['dsr_probability'] > 1) {
            throw new \InvalidArgumentException('PROMOTION_GATES_NOT_MET');
        }
    }

    private function query(string $sql, array $params): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    private function transaction(callable $work)
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
