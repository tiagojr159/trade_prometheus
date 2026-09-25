USE prometheus;

CREATE TABLE IF NOT EXISTS llm_usage (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purpose VARCHAR(64) NOT NULL,
  model VARCHAR(64) NOT NULL,
  prompt_tokens INT NOT NULL DEFAULT 0,
  completion_tokens INT NOT NULL DEFAULT 0,
  total_tokens INT NOT NULL DEFAULT 0,
  cost_usd DECIMAL(12,8) NOT NULL DEFAULT 0,
  status ENUM('ok','invalid_json','http_error','rate_limited','no_api_key') NOT NULL,
  content_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_llm_purpose_time (purpose, created_at)
) ENGINE=InnoDB;
