USE prometheus;

CREATE TABLE IF NOT EXISTS backtest_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(24) NOT NULL,
  at_time DATETIME NOT NULL,
  horizon VARCHAR(16) NOT NULL,
  predicted_direction ENUM('UP','DOWN','INDETERMINATE') NOT NULL,
  probability_up DECIMAL(8,5) NOT NULL,
  initial_price DECIMAL(24,8) NOT NULL,
  final_price DECIMAL(24,8) NOT NULL,
  actual_direction ENUM('UP','DOWN','FLAT') NOT NULL,
  correct TINYINT(1) NULL,
  return_pct DECIMAL(12,6) NOT NULL,
  evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 2,
  KEY idx_backtest_symbol_time (symbol, at_time)
) ENGINE=InnoDB;
