CREATE TABLE IF NOT EXISTS quant_tournament_seasons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_key VARCHAR(64) NOT NULL UNIQUE,
    symbol VARCHAR(24) NOT NULL,
    started_at_utc DATETIME(6) NOT NULL,
    policy_sha256 CHAR(64) NOT NULL,
    policy_json LONGTEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quant_tournament_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    season_id BIGINT UNSIGNED NOT NULL,
    strategy_key VARCHAR(32) NOT NULL,
    strategy_version VARCHAR(32) NOT NULL,
    initial_equity DECIMAL(20,8) NOT NULL,
    cash DECIMAL(20,8) NOT NULL,
    quantity DECIMAL(24,12) NOT NULL DEFAULT 0,
    cost_basis DECIMAL(20,8) NOT NULL DEFAULT 0,
    opened_at_utc DATETIME(6) NULL,
    entries SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    realized_pnl DECIMAL(20,8) NOT NULL DEFAULT 0,
    peak_equity DECIMAL(20,8) NOT NULL,
    max_drawdown_bps DECIMAL(12,4) NOT NULL DEFAULT 0,
    last_quote_id BIGINT UNSIGNED NULL,
    last_quote_at_utc DATETIME(6) NULL,
    last_fill_at_utc DATETIME(6) NULL,
    last_tick_at_utc DATETIME(6) NULL,
    next_decision_at_utc DATETIME(6) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'WAITING_DATA',
    UNIQUE KEY uq_quant_account(season_id,strategy_key),
    FOREIGN KEY (season_id) REFERENCES quant_tournament_seasons(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quant_spot_quotes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol VARCHAR(24) NOT NULL,
    requested_at_utc DATETIME(6) NOT NULL,
    ingested_at_utc DATETIME(6) NOT NULL,
    bid DECIMAL(24,8) NOT NULL,
    ask DECIMAL(24,8) NOT NULL,
    bid_qty DECIMAL(24,12) NOT NULL,
    ask_qty DECIMAL(24,12) NOT NULL,
    UNIQUE KEY uq_quant_quote(symbol,requested_at_utc)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quant_decisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(48) NOT NULL,
    as_of_utc DATETIME(6) NOT NULL,
    quote_id BIGINT UNSIGNED NULL,
    action VARCHAR(24) NOT NULL,
    reason_code VARCHAR(40) NOT NULL,
    detail_json LONGTEXT NOT NULL,
    UNIQUE KEY uq_quant_decision(account_id,event_key),
    FOREIGN KEY (account_id) REFERENCES quant_tournament_accounts(id),
    FOREIGN KEY (quote_id) REFERENCES quant_spot_quotes(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quant_trade_intents (
    decision_id BIGINT UNSIGNED PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    eligible_after_utc DATETIME(6) NOT NULL,
    expires_at_utc DATETIME(6) NULL,
    cash_budget DECIMAL(20,8) NULL,
    quantity DECIMAL(24,12) NULL,
    status VARCHAR(24) NOT NULL,
    KEY ix_quant_pending(account_id,status),
    FOREIGN KEY (decision_id) REFERENCES quant_decisions(id),
    FOREIGN KEY (account_id) REFERENCES quant_tournament_accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quant_spot_fills (
    decision_id BIGINT UNSIGNED PRIMARY KEY,
    quote_id BIGINT UNSIGNED NOT NULL,
    quantity_delta DECIMAL(24,12) NOT NULL,
    cash_delta DECIMAL(20,8) NOT NULL,
    fill_price DECIMAL(24,8) NOT NULL,
    fee DECIMAL(20,8) NOT NULL,
    realized_pnl DECIMAL(20,8) NOT NULL,
    filled_at_utc DATETIME(6) NOT NULL,
    FOREIGN KEY (decision_id) REFERENCES quant_trade_intents(decision_id),
    FOREIGN KEY (quote_id) REFERENCES quant_spot_quotes(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quant_equity_snapshots (
    account_id BIGINT UNSIGNED NOT NULL,
    quote_id BIGINT UNSIGNED NOT NULL,
    as_of_utc DATETIME(6) NOT NULL,
    cash DECIMAL(20,8) NOT NULL,
    quantity DECIMAL(24,12) NOT NULL,
    equity DECIMAL(20,8) NOT NULL,
    unrealized_pnl DECIMAL(20,8) NOT NULL,
    PRIMARY KEY (account_id,quote_id),
    FOREIGN KEY (account_id) REFERENCES quant_tournament_accounts(id),
    FOREIGN KEY (quote_id) REFERENCES quant_spot_quotes(id)
) ENGINE=InnoDB;
