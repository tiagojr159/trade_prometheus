CREATE DATABASE IF NOT EXISTS prometheus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE prometheus;

CREATE TABLE IF NOT EXISTS market_data (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(24) NOT NULL,
  source VARCHAR(64) NOT NULL,
  interval_name VARCHAR(16) NOT NULL,
  open_time DATETIME NOT NULL,
  close_time DATETIME NOT NULL,
  open_price DECIMAL(24,8) NOT NULL,
  high_price DECIMAL(24,8) NOT NULL,
  low_price DECIMAL(24,8) NOT NULL,
  close_price DECIMAL(24,8) NOT NULL,
  volume DECIMAL(30,8) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  available_at DATETIME NULL,
  ingested_at DATETIME NULL,
  temporal_quality ENUM('EXACT','INGESTION_ONLY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  availability_source VARCHAR(64) NULL,
  UNIQUE KEY uniq_market_candle (symbol, source, interval_name, open_time),
  KEY idx_market_symbol_time (symbol, open_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS technical_indicators (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(24) NOT NULL,
  candle_time DATETIME NOT NULL,
  rsi DECIMAL(12,6) NULL,
  macd DECIMAL(18,8) NULL,
  macd_signal DECIMAL(18,8) NULL,
  ema_20 DECIMAL(24,8) NULL,
  ema_50 DECIMAL(24,8) NULL,
  bb_upper DECIMAL(24,8) NULL,
  bb_middle DECIMAL(24,8) NULL,
  bb_lower DECIMAL(24,8) NULL,
  atr DECIMAL(24,8) NULL,
  volume_score DECIMAL(12,6) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_indicator (symbol, candle_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS news (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(128) NOT NULL,
  title VARCHAR(512) NOT NULL,
  url VARCHAR(1024) NULL,
  published_at DATETIME NULL,
  content_hash CHAR(64) NOT NULL,
  sentiment DECIMAL(8,5) NULL,
  relevance DECIMAL(8,5) NULL,
  impact DECIMAL(8,5) NULL,
  direction DECIMAL(8,5) NULL,
  summary TEXT NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  available_at DATETIME NULL,
  ingested_at DATETIME NULL,
  temporal_quality ENUM('EXACT','INGESTION_ONLY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  availability_source VARCHAR(64) NULL,
  analysis_created_at DATETIME NULL,
  analysis_model VARCHAR(64) NULL,
  analysis_status VARCHAR(32) NULL,
  UNIQUE KEY uniq_news_hash (content_hash),
  KEY idx_news_time (published_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS onchain_data (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  metric VARCHAR(96) NOT NULL,
  source VARCHAR(64) NOT NULL,
  observed_at DATETIME NOT NULL,
  value DECIMAL(30,8) NOT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  available_at DATETIME NULL,
  ingested_at DATETIME NULL,
  temporal_quality ENUM('EXACT','INGESTION_ONLY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  availability_source VARCHAR(64) NULL,
  UNIQUE KEY uniq_onchain (metric, source, observed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS derivatives_data (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  metric VARCHAR(96) NOT NULL,
  source VARCHAR(64) NOT NULL,
  observed_at DATETIME NOT NULL,
  value DECIMAL(30,8) NOT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  available_at DATETIME NULL,
  ingested_at DATETIME NULL,
  temporal_quality ENUM('EXACT','INGESTION_ONLY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  availability_source VARCHAR(64) NULL,
  UNIQUE KEY uniq_derivatives (metric, source, observed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS macro_data (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  metric VARCHAR(96) NOT NULL,
  source VARCHAR(64) NOT NULL,
  observed_at DATETIME NOT NULL,
  value DECIMAL(30,8) NOT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  available_at DATETIME NULL,
  ingested_at DATETIME NULL,
  temporal_quality ENUM('EXACT','INGESTION_ONLY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  availability_source VARCHAR(64) NULL,
  UNIQUE KEY uniq_macro (metric, source, observed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS signals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module VARCHAR(64) NOT NULL,
  horizon VARCHAR(16) NOT NULL,
  signal_value DECIMAL(8,5) NOT NULL,
  confidence DECIMAL(8,5) NOT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_signal_module_time (module, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS market_regimes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  regime VARCHAR(32) NOT NULL,
  confidence DECIMAL(8,5) NOT NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_regime_time (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS predictions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(24) NOT NULL,
  horizon VARCHAR(16) NOT NULL,
  target_time DATETIME NOT NULL,
  initial_price DECIMAL(24,8) NOT NULL,
  predicted_direction ENUM('UP','DOWN','INDETERMINATE') NOT NULL,
  probability_up DECIMAL(8,5) NOT NULL,
  probability_down DECIMAL(8,5) NOT NULL,
  edge DECIMAL(8,5) NOT NULL DEFAULT 0,
  confidence DECIMAL(8,5) NOT NULL,
  ensemble_signal DECIMAL(8,5) NOT NULL,
  regime VARCHAR(32) NOT NULL,
  signals_json JSON NOT NULL,
  weights_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_prediction_eval (target_time, horizon),
  KEY idx_prediction_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prediction_results (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  prediction_id BIGINT UNSIGNED NOT NULL,
  final_price DECIMAL(24,8) NOT NULL,
  actual_direction ENUM('UP','DOWN','FLAT') NOT NULL,
  correct TINYINT(1) NULL COMMENT 'NULL when FLAT/INDETERMINATE',
  return_pct DECIMAL(12,6) NOT NULL,
  error_score DECIMAL(12,6) NULL,
  directional_hit TINYINT(1) NULL COMMENT '1/0 for comparable UP/DOWN; otherwise NULL',
  realized_return DECIMAL(14,10) NULL,
  evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 2,
  evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_prediction_result (prediction_id),
  CONSTRAINT fk_result_prediction FOREIGN KEY (prediction_id) REFERENCES predictions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

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
CREATE TABLE IF NOT EXISTS module_performance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module VARCHAR(64) NOT NULL,
  horizon VARCHAR(16) NOT NULL,
  regime VARCHAR(32) NOT NULL,
  sample_size INT NOT NULL DEFAULT 0,
  accuracy DECIMAL(8,5) NOT NULL DEFAULT 0.5,
  brier_score DECIMAL(8,5) NOT NULL DEFAULT 0.25,
  avg_confidence DECIMAL(8,5) NOT NULL DEFAULT 0.5,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_module_perf (module, horizon, regime)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS module_weights (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module VARCHAR(64) NOT NULL,
  horizon VARCHAR(16) NOT NULL,
  regime VARCHAR(32) NOT NULL,
  weight DECIMAL(10,6) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_module_weight (module, horizon, regime)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(128) PRIMARY KEY,
  setting_value TEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level VARCHAR(16) NOT NULL,
  event VARCHAR(128) NOT NULL,
  context JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_log_time (created_at),
  KEY idx_log_level (level)
) ENGINE=InnoDB;
