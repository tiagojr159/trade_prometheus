-- Independent paper account. Copy execution costs and exposure from PROMETHEUS.
START TRANSACTION;
INSERT IGNORE INTO paper_trading_config
    (mode,enabled,initial_balance,allocation_pct,allow_short,avoid_volatile,fee_pct,slippage_pct,
     min_edge_pct,min_confidence,cooldown_minutes,reversal_policy,stop_loss_pct,take_profit_pct,max_position_minutes)
SELECT 'SWING_4H',enabled,100,allocation_pct,allow_short,avoid_volatile,fee_pct,slippage_pct,
       min_edge_pct,min_confidence,cooldown_minutes,'CLOSE_ONLY',1,2,240
FROM paper_trading_config WHERE mode='PROMETHEUS';
INSERT IGNORE INTO paper_trading_v2_accounts (mode,initial_balance,cash_balance,peak_equity)
SELECT mode,100,100,100 FROM paper_trading_config WHERE mode='SWING_4H';
INSERT IGNORE INTO paper_trading_heartbeat (mode)
SELECT mode FROM paper_trading_config WHERE mode='SWING_4H';
COMMIT;
