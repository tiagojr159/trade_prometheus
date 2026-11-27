CREATE TABLE IF NOT EXISTS arbitrage_strategy_position_state (
 position_id BIGINT UNSIGNED PRIMARY KEY,
 idempotency_key VARCHAR(96) NOT NULL UNIQUE,
 instrument_type VARCHAR(16) NOT NULL,
 entry_fees DECIMAL(24,8) NOT NULL DEFAULT 0,
 entry_slippage DECIMAL(24,8) NOT NULL DEFAULT 0,
 funding_received DECIMAL(24,8) NOT NULL DEFAULT 0,
 funding_paid DECIMAL(24,8) NOT NULL DEFAULT 0,
 classification VARCHAR(24) NOT NULL DEFAULT 'PAPER',
 FOREIGN KEY (position_id) REFERENCES arbitrage_strategy_positions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_position_funding_settlements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 position_id BIGINT UNSIGNED NOT NULL,
 settlement_key VARCHAR(96) NOT NULL,
 funding_rate DECIMAL(20,12) NOT NULL,
 mark_price DECIMAL(24,8) NOT NULL,
 amount DECIMAL(24,8) NOT NULL,
 cash_delta DECIMAL(24,8) NOT NULL,
 settled_at_utc DATETIME(6) NOT NULL,
 available_at_utc DATETIME(6) NOT NULL,
 UNIQUE KEY uq_arb_position_settlement(position_id,settlement_key),
 FOREIGN KEY (position_id) REFERENCES arbitrage_strategy_positions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_strategy_trade_metrics (
 trade_id BIGINT UNSIGNED PRIMARY KEY,
 capital_used DECIMAL(24,8) NOT NULL DEFAULT 0,
 FOREIGN KEY (trade_id) REFERENCES arbitrage_strategy_trades(id)
) ENGINE=InnoDB;
