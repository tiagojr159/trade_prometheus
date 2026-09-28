CREATE TABLE IF NOT EXISTS paper_trading_accounts (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  initial_cash DECIMAL(18,8) NOT NULL DEFAULT 100,
  cash_balance DECIMAL(18,8) NOT NULL DEFAULT 100,
  btc_balance DECIMAL(24,12) NOT NULL DEFAULT 0,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_trade_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS paper_trading_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  prediction_id BIGINT UNSIGNED NULL,
  side ENUM('BUY','SELL') NOT NULL,
  symbol VARCHAR(24) NOT NULL,
  quantity_btc DECIMAL(24,12) NOT NULL,
  price DECIMAL(24,8) NOT NULL,
  gross_usd DECIMAL(18,8) NOT NULL,
  cash_after DECIMAL(18,8) NOT NULL,
  btc_after DECIMAL(24,12) NOT NULL,
  probability_up DECIMAL(8,5) NOT NULL,
  confidence DECIMAL(8,5) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_paper_orders_created (created_at),
  KEY idx_paper_orders_prediction (prediction_id),
  CONSTRAINT fk_paper_order_prediction FOREIGN KEY (prediction_id) REFERENCES predictions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO paper_trading_accounts (id, initial_cash, cash_balance, btc_balance) VALUES (1, 100, 100, 0);
