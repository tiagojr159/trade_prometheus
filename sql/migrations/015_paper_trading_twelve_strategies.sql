-- Add five bot-inspired paper strategies to the existing seven-mode tournament.
-- Run after migrations 013 and 014. Existing accounts and ledgers are untouched.

INSERT IGNORE INTO paper_trading_config
  (mode,enabled,initial_balance,allocation_pct,allow_short,avoid_volatile,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,reversal_policy,stop_loss_pct,take_profit_pct,max_position_minutes,stress_multiplier)
SELECT modes.mode,1,100,cfg.allocation_pct,
       CASE WHEN modes.mode IN ('SPOT_GRID','SPOT_DCA','REBALANCE') THEN 0 ELSE cfg.allow_short END,
       0,cfg.fee_pct,cfg.slippage_pct,cfg.min_edge_pct,cfg.min_confidence,cfg.cooldown_minutes,
       cfg.reversal_policy,
       CASE WHEN modes.mode='SPOT_DCA' THEN 5.0 WHEN modes.mode='REBALANCE' THEN 99.0 ELSE cfg.stop_loss_pct END,
       CASE WHEN modes.mode='SPOT_DCA' THEN 1.5 WHEN modes.mode='REBALANCE' THEN 99.0 ELSE cfg.take_profit_pct END,
       CASE WHEN modes.mode='SPOT_DCA' THEN 1440 WHEN modes.mode='REBALANCE' THEN 525600 ELSE cfg.max_position_minutes END,
       cfg.stress_multiplier
FROM paper_trading_config cfg
CROSS JOIN (
  SELECT 'SPOT_GRID' AS mode UNION ALL
  SELECT 'SPOT_DCA' UNION ALL
  SELECT 'FUNDING_BIAS' UNION ALL
  SELECT 'REBALANCE' UNION ALL
  SELECT 'VWAP_TREND'
) modes
WHERE cfg.mode='PROMETHEUS';

INSERT IGNORE INTO paper_trading_v2_accounts (mode,initial_balance,cash_balance,peak_equity)
VALUES ('SPOT_GRID',100,100,100),('SPOT_DCA',100,100,100),('FUNDING_BIAS',100,100,100),
       ('REBALANCE',100,100,100),('VWAP_TREND',100,100,100);

INSERT IGNORE INTO paper_trading_heartbeat (mode)
VALUES ('SPOT_GRID'),('SPOT_DCA'),('FUNDING_BIAS'),('REBALANCE'),('VWAP_TREND');
