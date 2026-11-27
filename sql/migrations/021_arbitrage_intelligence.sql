CREATE TABLE IF NOT EXISTS arbitrage_strategies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, strategy_key VARCHAR(32) NOT NULL UNIQUE,
 display_name VARCHAR(96) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0,
 execution_mode VARCHAR(24) NOT NULL DEFAULT 'OBSERVATION', configuration_json LONGTEXT NOT NULL,
 created_at_utc DATETIME(6) NOT NULL, updated_at_utc DATETIME(6) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_market_regimes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, regime VARCHAR(32) NOT NULL,
 confidence DECIMAL(8,6) NULL, observed_at_utc DATETIME(6) NOT NULL, details_json LONGTEXT NOT NULL,
 KEY ix_arb_regime_time(observed_at_utc)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_opportunities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, strategy_key VARCHAR(32) NOT NULL,
 symbol VARCHAR(48) NOT NULL, action VARCHAR(96) NOT NULL, status VARCHAR(24) NOT NULL,
 observed_at_utc DATETIME(6) NOT NULL, gross_profit DECIMAL(24,8) NOT NULL DEFAULT 0,
 costs DECIMAL(24,8) NOT NULL DEFAULT 0, net_profit DECIMAL(24,8) NOT NULL DEFAULT 0,
 score DECIMAL(10,6) NULL, capital_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
 exchange_exposure_pct DECIMAL(8,4) NOT NULL DEFAULT 0, data_quality TINYINT(1) NOT NULL DEFAULT 0,
 rejection_reasons_json LONGTEXT NOT NULL, details_json LONGTEXT NOT NULL, regime_id BIGINT UNSIGNED NULL,
 KEY ix_arb_opportunity_time(observed_at_utc), KEY ix_arb_strategy_time(strategy_key,observed_at_utc),
 KEY ix_arb_status_score(status,score), FOREIGN KEY (regime_id) REFERENCES arbitrage_market_regimes(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_funding_rate_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, exchange_name VARCHAR(32) NOT NULL,
 symbol VARCHAR(32) NOT NULL, funding_rate DECIMAL(20,12) NOT NULL,
 interval_hours DECIMAL(8,4) NOT NULL, mark_price DECIMAL(24,8) NULL,
 next_funding_at_utc DATETIME(6) NULL, observed_at_utc DATETIME(6) NOT NULL,
 source VARCHAR(32) NOT NULL, raw_hash CHAR(64) NULL,
 UNIQUE KEY uq_arb_funding(exchange_name,symbol,observed_at_utc),
 KEY ix_arb_funding_history(symbol,observed_at_utc), KEY ix_arb_funding_venue(exchange_name,observed_at_utc)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_basis_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, symbol VARCHAR(32) NOT NULL,
 spot_venue VARCHAR(32) NOT NULL, future_venue VARCHAR(32) NOT NULL,
 spot_bid DECIMAL(24,8) NOT NULL, spot_ask DECIMAL(24,8) NOT NULL,
 future_bid DECIMAL(24,8) NOT NULL, future_ask DECIMAL(24,8) NOT NULL,
 expiry_at_utc DATETIME(6) NULL, observed_at_utc DATETIME(6) NOT NULL,
 KEY ix_arb_basis_symbol_time(symbol,observed_at_utc)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_orderbook_snapshots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, exchange_name VARCHAR(32) NOT NULL,
 symbol VARCHAR(32) NOT NULL, market_type VARCHAR(16) NOT NULL, bid DECIMAL(24,8) NOT NULL,
 ask DECIMAL(24,8) NOT NULL, bid_qty DECIMAL(24,12) NOT NULL, ask_qty DECIMAL(24,12) NOT NULL,
 observed_at_utc DATETIME(6) NOT NULL, latency_ms INT UNSIGNED NULL, depth_json LONGTEXT NOT NULL,
 KEY ix_arb_book_symbol_time(symbol,observed_at_utc), KEY ix_arb_book_venue(exchange_name,observed_at_utc)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_strategy_portfolios (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, strategy_key VARCHAR(32) NOT NULL,
 portfolio_key VARCHAR(64) NOT NULL, initial_equity DECIMAL(24,8) NOT NULL,
 cash DECIMAL(24,8) NOT NULL, equity DECIMAL(24,8) NOT NULL,
 realized_pnl DECIMAL(24,8) NOT NULL DEFAULT 0, peak_equity DECIMAL(24,8) NOT NULL,
 max_drawdown_pct DECIMAL(10,6) NOT NULL DEFAULT 0, status VARCHAR(24) NOT NULL DEFAULT 'OBSERVATION',
 started_at_utc DATETIME(6) NOT NULL, UNIQUE KEY uq_arb_portfolio(strategy_key,portfolio_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_strategy_positions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, portfolio_id BIGINT UNSIGNED NOT NULL,
 opportunity_id BIGINT UNSIGNED NOT NULL, symbol VARCHAR(48) NOT NULL, leg VARCHAR(16) NOT NULL,
 venue VARCHAR(32) NOT NULL, side VARCHAR(8) NOT NULL, quantity DECIMAL(24,12) NOT NULL,
 entry_price DECIMAL(24,8) NOT NULL, mark_price DECIMAL(24,8) NULL,
 margin_reserved DECIMAL(24,8) NOT NULL DEFAULT 0, status VARCHAR(24) NOT NULL,
 opened_at_utc DATETIME(6) NOT NULL, closed_at_utc DATETIME(6) NULL,
 KEY ix_arb_position_status(portfolio_id,status),
 FOREIGN KEY (portfolio_id) REFERENCES arbitrage_strategy_portfolios(id),
 FOREIGN KEY (opportunity_id) REFERENCES arbitrage_opportunities(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_strategy_trades (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, portfolio_id BIGINT UNSIGNED NOT NULL,
 opportunity_id BIGINT UNSIGNED NULL, trade_key VARCHAR(96) NOT NULL UNIQUE,
 symbol VARCHAR(48) NOT NULL, classification VARCHAR(24) NOT NULL,
 gross_pnl DECIMAL(24,8) NOT NULL DEFAULT 0, fees DECIMAL(24,8) NOT NULL DEFAULT 0,
 funding_received DECIMAL(24,8) NOT NULL DEFAULT 0, funding_paid DECIMAL(24,8) NOT NULL DEFAULT 0,
 slippage DECIMAL(24,8) NOT NULL DEFAULT 0, net_pnl DECIMAL(24,8) NOT NULL DEFAULT 0,
 opened_at_utc DATETIME(6) NOT NULL, closed_at_utc DATETIME(6) NULL,
 FOREIGN KEY (portfolio_id) REFERENCES arbitrage_strategy_portfolios(id),
 FOREIGN KEY (opportunity_id) REFERENCES arbitrage_opportunities(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_strategy_performance (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, strategy_key VARCHAR(32) NOT NULL,
 as_of_utc DATETIME(6) NOT NULL, classification VARCHAR(24) NOT NULL,
 net_pnl DECIMAL(24,8) NOT NULL, equity DECIMAL(24,8) NOT NULL,
 drawdown_pct DECIMAL(10,6) NOT NULL, sharpe DECIMAL(12,6) NULL, sortino DECIMAL(12,6) NULL,
 profit_factor DECIMAL(12,6) NULL, calmar DECIMAL(12,6) NULL, win_rate DECIMAL(8,6) NULL,
 capital_used DECIMAL(24,8) NOT NULL DEFAULT 0,
 KEY ix_arb_perf_strategy_time(strategy_key,classification,as_of_utc)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_strategy_backtests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, strategy_key VARCHAR(32) NOT NULL,
 dataset_sha256 CHAR(64) NOT NULL, started_at_utc DATETIME(6) NOT NULL, ended_at_utc DATETIME(6) NOT NULL,
 train_end_utc DATETIME(6) NULL, validation_end_utc DATETIME(6) NULL,
 test_start_utc DATETIME(6) NULL, status VARCHAR(24) NOT NULL,
 metrics_json LONGTEXT NOT NULL, limitations_json LONGTEXT NOT NULL,
 KEY ix_arb_backtest_strategy(strategy_key,ended_at_utc)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_risk_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, strategy_key VARCHAR(32) NOT NULL,
 opportunity_id BIGINT UNSIGNED NULL, event_type VARCHAR(32) NOT NULL, reason_code VARCHAR(48) NOT NULL,
 details_json LONGTEXT NOT NULL, created_at_utc DATETIME(6) NOT NULL,
 KEY ix_arb_risk_time(created_at_utc), FOREIGN KEY (opportunity_id) REFERENCES arbitrage_opportunities(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS arbitrage_execution_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, strategy_key VARCHAR(32) NOT NULL,
 opportunity_id BIGINT UNSIGNED NOT NULL, event_key VARCHAR(96) NOT NULL UNIQUE,
 event_type VARCHAR(32) NOT NULL, execution_mode VARCHAR(24) NOT NULL DEFAULT 'SIMULATION',
 details_json LONGTEXT NOT NULL, occurred_at_utc DATETIME(6) NOT NULL,
 FOREIGN KEY (opportunity_id) REFERENCES arbitrage_opportunities(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO arbitrage_strategies(strategy_key,display_name,enabled,execution_mode,configuration_json,created_at_utc,updated_at_utc) VALUES
 ('FUNDING_RATE','Dynamic Funding Rate',0,'OBSERVATION','{"paper_trading_only":true,"live_trading_enabled":false,"leverage_enabled":false}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)),
 ('CASH_AND_CARRY','Cash and Carry',0,'OBSERVATION','{"paper_trading_only":true,"live_trading_enabled":false}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)),
 ('CROSS_EXCHANGE_MM','Cross-Exchange Market Making',0,'OBSERVATION','{"paper_trading_only":true,"live_trading_enabled":false}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)),
 ('STATISTICAL','Statistical Arbitrage',0,'OBSERVATION','{"paper_trading_only":true,"live_trading_enabled":false}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)),
 ('SPATIAL','Spatial Arbitrage',0,'OBSERVATION','{"paper_trading_only":true,"live_trading_enabled":false}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)),
 ('TRIANGULAR','Triangular Arbitrage',0,'OBSERVATION','{"paper_trading_only":true,"live_trading_enabled":false}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)),
 ('MEV_OBSERVATION','MEV (observation only)',0,'OBSERVATION','{"paper_trading_only":true,"live_trading_enabled":false,"live_execution":false}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6));
