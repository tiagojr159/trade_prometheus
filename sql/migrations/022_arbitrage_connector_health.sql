CREATE TABLE IF NOT EXISTS arbitrage_connector_status (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 exchange_name VARCHAR(32) NOT NULL, connector_type VARCHAR(24) NOT NULL,
 status VARCHAR(24) NOT NULL, last_attempt_at_utc DATETIME(6) NOT NULL,
 last_success_at_utc DATETIME(6) NULL, latency_ms INT UNSIGNED NULL,
 last_error_code VARCHAR(64) NULL, details_json LONGTEXT NOT NULL,
 UNIQUE KEY uq_arb_connector(exchange_name,connector_type),
 KEY ix_arb_connector_status(status,last_attempt_at_utc)
) ENGINE=InnoDB;
