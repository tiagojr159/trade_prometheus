-- This tournament is for prospective paper measurement. Let each strategy
-- sample its own entry rules in every regime, and charge costs to trade PnL.
UPDATE paper_trading_config
SET avoid_volatile = 0
WHERE mode IN (
  'PROMETHEUS', 'MULTIHORIZON', 'INVERSE', 'MOMENTUM',
  'MEAN_REVERSION', 'BREAKOUT', 'ADAPTIVE'
);
