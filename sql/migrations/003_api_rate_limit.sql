USE prometheus;

CREATE TABLE IF NOT EXISTS api_rate_limit (
  ip VARCHAR(45) PRIMARY KEY,
  hits INT NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  KEY idx_rl_window (window_start)
) ENGINE=InnoDB;
