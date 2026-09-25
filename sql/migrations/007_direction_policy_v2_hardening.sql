-- DirectionPolicy v2 hardening. Rerunnable on supported MySQL/MariaDB versions.
USE prometheus;

ALTER TABLE predictions
  MODIFY predicted_direction ENUM('UP','DOWN','SIDEWAYS','INDETERMINATE') NOT NULL;
ALTER TABLE predictions ADD COLUMN IF NOT EXISTS edge DECIMAL(8,5) NULL;
UPDATE predictions SET edge = ABS(probability_up - 0.5) WHERE edge IS NULL;
ALTER TABLE predictions MODIFY edge DECIMAL(8,5) NOT NULL DEFAULT 0;
ALTER TABLE prediction_results
  MODIFY actual_direction ENUM('UP','DOWN','FLAT','SIDEWAYS') NOT NULL,
  MODIFY correct TINYINT(1) NULL,
  MODIFY error_score DECIMAL(12,6) NULL;
ALTER TABLE prediction_results ADD COLUMN IF NOT EXISTS directional_hit TINYINT(1) NULL COMMENT '1/0 for comparable UP/DOWN; NULL for FLAT/INDETERMINATE';
ALTER TABLE prediction_results ADD COLUMN IF NOT EXISTS realized_return DECIMAL(14,10) NULL;
ALTER TABLE prediction_results ADD COLUMN IF NOT EXISTS evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'v1 legacy; v2 DirectionPolicy';
ALTER TABLE prediction_results MODIFY evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT 'v1 legacy; v2 DirectionPolicy';

ALTER TABLE backtest_results
  MODIFY predicted_direction ENUM('UP','DOWN','SIDEWAYS','INDETERMINATE') NOT NULL,
  MODIFY actual_direction ENUM('UP','DOWN','FLAT','SIDEWAYS') NOT NULL,
  MODIFY correct TINYINT(1) NULL;
-- Rows in a legacy table without the column start as v1; only future inserts use v2.
ALTER TABLE backtest_results ADD COLUMN IF NOT EXISTS evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE backtest_results MODIFY evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 2;
UPDATE backtest_results SET evaluation_version=1 WHERE predicted_direction='SIDEWAYS' OR actual_direction='SIDEWAYS';
