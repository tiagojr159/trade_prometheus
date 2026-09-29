-- Preserve the original simulator histories under legacy names, then create
-- seven independent $100 accounts for prospective strategy comparison.
ALTER TABLE paper_trading_config MODIFY mode VARCHAR(32) NOT NULL;
ALTER TABLE paper_trading_v2_accounts MODIFY mode VARCHAR(32) NOT NULL;
ALTER TABLE paper_trading_decisions MODIFY mode VARCHAR(32) NOT NULL;
ALTER TABLE paper_trading_v2_orders MODIFY mode VARCHAR(32) NOT NULL;
ALTER TABLE paper_trading_v2_trades MODIFY mode VARCHAR(32) NOT NULL;
ALTER TABLE paper_trading_equity MODIFY mode VARCHAR(32) NOT NULL;
ALTER TABLE paper_trading_heartbeat MODIFY mode VARCHAR(32) NOT NULL;
-- MySQL versions used by some shared hosts do not support
-- `ADD COLUMN IF NOT EXISTS`. Check metadata and run the DDL only if needed.
SET @has_avoid_volatile = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'paper_trading_config'
    AND COLUMN_NAME = 'avoid_volatile'
);
SET @add_avoid_volatile_sql = IF(
  @has_avoid_volatile = 0,
  'ALTER TABLE paper_trading_config ADD COLUMN avoid_volatile TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_short',
  'SELECT 1'
);
PREPARE add_avoid_volatile_stmt FROM @add_avoid_volatile_sql;
EXECUTE add_avoid_volatile_stmt;
DEALLOCATE PREPARE add_avoid_volatile_stmt;

UPDATE paper_trading_config SET mode=CONCAT('LEGACY_',mode) WHERE mode IN ('DIRECTIONAL','STRATEGY');
UPDATE paper_trading_v2_accounts SET mode=CONCAT('LEGACY_',mode) WHERE mode IN ('DIRECTIONAL','STRATEGY');
UPDATE paper_trading_decisions SET mode=CONCAT('LEGACY_',mode) WHERE mode IN ('DIRECTIONAL','STRATEGY');
UPDATE paper_trading_v2_orders SET mode=CONCAT('LEGACY_',mode) WHERE mode IN ('DIRECTIONAL','STRATEGY');
UPDATE paper_trading_v2_trades SET mode=CONCAT('LEGACY_',mode) WHERE mode IN ('DIRECTIONAL','STRATEGY');
UPDATE paper_trading_equity SET mode=CONCAT('LEGACY_',mode) WHERE mode IN ('DIRECTIONAL','STRATEGY');
UPDATE paper_trading_heartbeat SET mode=CONCAT('LEGACY_',mode) WHERE mode IN ('DIRECTIONAL','STRATEGY');

INSERT IGNORE INTO paper_trading_config
  (mode,enabled,initial_balance,allocation_pct,allow_short,avoid_volatile,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,reversal_policy,stop_loss_pct,take_profit_pct,max_position_minutes,stress_multiplier)
SELECT 'PROMETHEUS',1,100,allocation_pct,1,avoid_volatile,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,reversal_policy,stop_loss_pct,take_profit_pct,max_position_minutes,stress_multiplier
FROM paper_trading_config WHERE mode='LEGACY_DIRECTIONAL';

INSERT IGNORE INTO paper_trading_config
  (mode,enabled,initial_balance,allocation_pct,allow_short,avoid_volatile,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,reversal_policy,stop_loss_pct,take_profit_pct,max_position_minutes,stress_multiplier)
SELECT 'MULTIHORIZON',1,100,allocation_pct,1,avoid_volatile,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,reversal_policy,stop_loss_pct,take_profit_pct,max_position_minutes,stress_multiplier
FROM paper_trading_config WHERE mode='LEGACY_DIRECTIONAL';

INSERT IGNORE INTO paper_trading_config
  (mode,enabled,initial_balance,allocation_pct,allow_short,avoid_volatile,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,reversal_policy,stop_loss_pct,take_profit_pct,max_position_minutes,stress_multiplier)
SELECT modes.mode,1,100,allocation_pct,1,avoid_volatile,fee_pct,slippage_pct,min_edge_pct,min_confidence,cooldown_minutes,reversal_policy,stop_loss_pct,take_profit_pct,max_position_minutes,stress_multiplier
FROM paper_trading_config base
CROSS JOIN (
  SELECT 'INVERSE' AS mode UNION ALL
  SELECT 'MOMENTUM' UNION ALL
  SELECT 'MEAN_REVERSION' UNION ALL
  SELECT 'BREAKOUT' UNION ALL
  SELECT 'ADAPTIVE'
) modes
WHERE base.mode='LEGACY_DIRECTIONAL';

INSERT IGNORE INTO paper_trading_v2_accounts (mode,initial_balance,cash_balance,peak_equity)
VALUES ('PROMETHEUS',100,100,100),('MULTIHORIZON',100,100,100),('INVERSE',100,100,100),
       ('MOMENTUM',100,100,100),('MEAN_REVERSION',100,100,100),('BREAKOUT',100,100,100),('ADAPTIVE',100,100,100);

INSERT IGNORE INTO paper_trading_heartbeat (mode)
VALUES ('PROMETHEUS'),('MULTIHORIZON'),('INVERSE'),('MOMENTUM'),('MEAN_REVERSION'),('BREAKOUT'),('ADAPTIVE');
