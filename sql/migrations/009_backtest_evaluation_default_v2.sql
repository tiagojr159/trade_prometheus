-- New rows use current DirectionPolicy semantics; existing evaluation_version values are preserved.
USE prometheus;
ALTER TABLE backtest_results
  MODIFY evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 2;
