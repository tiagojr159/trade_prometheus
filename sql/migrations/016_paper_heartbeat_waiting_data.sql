-- Allow the paper trader to report a healthy wait when no eligible market
-- candle is available. Existing heartbeat rows and their history are kept.
ALTER TABLE paper_trading_heartbeat
  MODIFY status ENUM('ACTIVE','STOPPED','ERROR','WAITING_DATA') NOT NULL DEFAULT 'STOPPED';
