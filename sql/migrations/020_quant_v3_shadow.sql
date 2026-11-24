CREATE TABLE IF NOT EXISTS quant_model_versions (
    model_id CHAR(64) PRIMARY KEY,
    symbol VARCHAR(24) NOT NULL,
    horizon VARCHAR(8) NOT NULL,
    training_cutoff_utc DATETIME(6) NOT NULL,
    calibration_cutoff_utc DATETIME(6) NOT NULL,
    registered_at_utc DATETIME(6) NOT NULL,
    dataset_sha256 CHAR(64) NOT NULL,
    artifact_json LONGTEXT NOT NULL,
    KEY ix_quant_models(symbol,horizon,registered_at_utc)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quant_model_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_id CHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL,
    effective_at_utc DATETIME(6) NOT NULL,
    UNIQUE KEY uq_quant_model_event(model_id,effective_at_utc,status),
    FOREIGN KEY (model_id) REFERENCES quant_model_versions(model_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quant_feature_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    symbol VARCHAR(24) NOT NULL,
    as_of_utc DATETIME(6) NOT NULL,
    feature_hash CHAR(64) NOT NULL,
    feature_schema_version SMALLINT UNSIGNED NOT NULL,
    dataset_kind VARCHAR(32) NOT NULL,
    features_json LONGTEXT NOT NULL,
    lineage_json LONGTEXT NOT NULL,
    initial_price DECIMAL(24,8) NOT NULL,
    UNIQUE KEY uq_quant_snapshot(symbol,as_of_utc,feature_schema_version,dataset_kind,feature_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quant_shadow_predictions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_id CHAR(64) NOT NULL,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    slot_utc DATETIME NOT NULL,
    target_utc DATETIME(6) NOT NULL,
    probability_up DOUBLE NOT NULL,
    expected_return_bps DOUBLE NULL,
    quantiles_json LONGTEXT NULL,
    UNIQUE KEY uq_quant_shadow_snapshot(model_id,snapshot_id),
    UNIQUE KEY uq_quant_shadow_slot(model_id,slot_utc),
    KEY ix_quant_shadow_target(target_utc,id),
    FOREIGN KEY (model_id) REFERENCES quant_model_versions(model_id),
    FOREIGN KEY (snapshot_id) REFERENCES quant_feature_snapshots(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quant_shadow_labels (
    prediction_id BIGINT UNSIGNED NOT NULL,
    label_version SMALLINT UNSIGNED NOT NULL,
    evaluated_at_utc DATETIME(6) NOT NULL,
    market_data_id BIGINT UNSIGNED NULL,
    return_bps DOUBLE NULL,
    direction VARCHAR(8) NULL,
    status VARCHAR(24) NOT NULL,
    PRIMARY KEY (prediction_id,label_version),
    FOREIGN KEY (prediction_id) REFERENCES quant_shadow_predictions(id)
) ENGINE=InnoDB;
