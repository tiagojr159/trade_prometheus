SET @has_leverage = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paper_trading_config' AND COLUMN_NAME = 'leverage'
);
SET @add_leverage_sql = IF(
  @has_leverage = 0,
  'ALTER TABLE paper_trading_config ADD COLUMN leverage DECIMAL(5,2) NOT NULL DEFAULT 1.00 AFTER allocation_pct',
  'DO 0'
);
PREPARE add_leverage_stmt FROM @add_leverage_sql;
EXECUTE add_leverage_stmt;
DEALLOCATE PREPARE add_leverage_stmt;

INSERT IGNORE INTO paper_trading_config
  (mode,enabled,initial_balance,allocation_pct,leverage,allow_short,avoid_volatile,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,reversal_policy,stop_loss_pct,take_profit_pct,max_position_minutes,stress_multiplier)
SELECT 'STRATEGY_10_10X',1,100,25,10,1,0,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,'CLOSE_REVERSE',1.5,3.0,240,stress_multiplier
FROM paper_trading_config WHERE mode='PROMETHEUS';

INSERT IGNORE INTO paper_trading_config
  (mode,enabled,initial_balance,allocation_pct,leverage,allow_short,avoid_volatile,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,reversal_policy,stop_loss_pct,take_profit_pct,max_position_minutes,stress_multiplier)
SELECT 'STRATEGY_27_10X',1,100,25,10,1,0,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,'CLOSE_REVERSE',1.0,2.0,120,stress_multiplier
FROM paper_trading_config WHERE mode='PROMETHEUS';

INSERT IGNORE INTO paper_trading_v2_accounts (mode,initial_balance,cash_balance,peak_equity)
VALUES ('STRATEGY_10_10X',100,100,100),('STRATEGY_27_10X',100,100,100);

INSERT IGNORE INTO paper_trading_heartbeat (mode)
VALUES ('STRATEGY_10_10X'),('STRATEGY_27_10X');
