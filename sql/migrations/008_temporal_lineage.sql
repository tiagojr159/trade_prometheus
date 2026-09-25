-- Temporal lineage for external observations. Historical rows deliberately remain NULL/UNKNOWN.
USE prometheus;

ALTER TABLE macro_data
  ADD COLUMN IF NOT EXISTS available_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS ingested_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS temporal_quality ENUM('EXACT','INGESTION_ONLY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  ADD COLUMN IF NOT EXISTS availability_source VARCHAR(64) NULL,
  ADD KEY IF NOT EXISTS idx_macro_ingested (ingested_at);

ALTER TABLE news
  ADD COLUMN IF NOT EXISTS available_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS ingested_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS temporal_quality ENUM('EXACT','INGESTION_ONLY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  ADD COLUMN IF NOT EXISTS availability_source VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS analysis_created_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS analysis_model VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS analysis_status VARCHAR(32) NULL,
  ADD KEY IF NOT EXISTS idx_news_ingested (ingested_at);

ALTER TABLE onchain_data
  ADD COLUMN IF NOT EXISTS available_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS ingested_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS temporal_quality ENUM('EXACT','INGESTION_ONLY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  ADD COLUMN IF NOT EXISTS availability_source VARCHAR(64) NULL,
  ADD KEY IF NOT EXISTS idx_onchain_ingested (source, ingested_at);

ALTER TABLE derivatives_data
  ADD COLUMN IF NOT EXISTS available_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS ingested_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS temporal_quality ENUM('EXACT','INGESTION_ONLY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  ADD COLUMN IF NOT EXISTS availability_source VARCHAR(64) NULL,
  ADD KEY IF NOT EXISTS idx_derivatives_ingested (source, ingested_at);

ALTER TABLE market_data
  ADD COLUMN IF NOT EXISTS available_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS ingested_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS temporal_quality ENUM('EXACT','INGESTION_ONLY','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  ADD COLUMN IF NOT EXISTS availability_source VARCHAR(64) NULL,
  ADD KEY IF NOT EXISTS idx_market_ingested (symbol, interval_name, ingested_at);

-- No historical timestamp backfill: created_at remains distinct and is not uniform proof of ingestion.
