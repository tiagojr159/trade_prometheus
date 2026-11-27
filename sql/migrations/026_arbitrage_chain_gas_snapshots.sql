CREATE TABLE IF NOT EXISTS arbitrage_chain_gas_snapshots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 chain_name VARCHAR(48) NOT NULL,
 gas_price_wei DECIMAL(38,0) NOT NULL,
 observed_at_utc DATETIME(6) NOT NULL,
 source VARCHAR(32) NOT NULL,
 response_hash CHAR(64) NOT NULL,
 UNIQUE KEY uq_arb_chain_gas(chain_name,observed_at_utc),
 KEY ix_arb_chain_gas_time(chain_name,observed_at_utc)
) ENGINE=InnoDB;
