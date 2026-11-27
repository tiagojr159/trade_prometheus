CREATE TABLE IF NOT EXISTS arbitrage_backtest_equity (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 backtest_id BIGINT UNSIGNED NOT NULL,
 strategy_key VARCHAR(32) NOT NULL,
 event_date DATE NOT NULL,
 equity DECIMAL(24,8) NOT NULL,
 daily_return DECIMAL(20,12) NOT NULL,
 UNIQUE KEY uq_arb_backtest_equity_date(backtest_id,event_date),
 KEY ix_arb_backtest_equity_strategy(strategy_key,event_date),
 FOREIGN KEY (backtest_id) REFERENCES arbitrage_strategy_backtests(id)
) ENGINE=InnoDB;
