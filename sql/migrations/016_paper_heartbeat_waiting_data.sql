-- Allow the paper trader to report a healthy wait when no eligible market
-- candle is available. Existing heartbeat rows and their history are kept.
-- Preserve heartbeat states used by installations that ran migration 016 first.
ALTER TABLE paper_trading_heartbeat
  MODIFY status ENUM('ACTIVE','STOPPED','ERROR','WAITING_DATA') NOT NULL DEFAULT 'STOPPED';
