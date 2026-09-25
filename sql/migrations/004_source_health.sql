-- ETAPA 05 — saúde central das fontes
CREATE TABLE IF NOT EXISTS source_health (
    source VARCHAR(60) PRIMARY KEY,
    status ENUM('OK','STALE','UNAVAILABLE','ERROR','NO_DATA') NOT NULL DEFAULT 'NO_DATA',
    last_attempt DATETIME NULL,
    last_success DATETIME NULL,
    last_data_timestamp DATETIME NULL,
    latency_ms INT NULL,
    error_message VARCHAR(500) NULL,
    consecutive_failures INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS idx_source_health_status ON source_health (status);
