<?php
declare(strict_types=1);

namespace Prometheus\core;

final class QuantPolicy
{
    public const VERSION = '3.0.0';

    public static function definition(): array
    {
        // Research assumptions. Exchange filters must be verified before approving an entry policy.
        return [
            'version' => self::VERSION,
            'fee_rate' => '0.0010', 'impact_rate' => '0.0002',
            'step_size' => '0.00001', 'min_notional' => '10.00',
            'entry_buffer_bps' => 3, 'max_spread_bps' => 20,
            'max_allocation' => '0.25', 'risk_allocation' => '0.005',
            'stop_pct' => 2, 'take_pct' => 4, 'drawdown_bps' => 1000,
            'latency_seconds' => 1, 'entry_expiry_seconds' => 180,
            'cooldown_seconds' => 300,
            'validated_lower_bound_bps' => [],
            'validated_policies' => [],
            'strategies' => [
                'PROMETHEUS' => [300, '1h', 'MODEL'],
                'MULTIHORIZON' => [300, '1h', 'MODEL_STACK'],
                'SWING_4H' => [900, '4h', 'MODEL_STACK'],
                'INVERSE' => [300, '1h', 'MODEL_INVERSE'],
                'MOMENTUM' => [300, '1h', 'TECHNICAL'],
                'MEAN_REVERSION' => [300, '15m', 'TECHNICAL'],
                'BREAKOUT' => [300, '1h', 'TECHNICAL'],
                'ADAPTIVE' => [300, '1h', 'MODEL_STACK'],
                'SPOT_GRID' => [60, '1h', 'TECHNICAL'],
                'SPOT_DCA' => [900, '4h', 'MODEL'],
                'FUNDING_BIAS' => [900, '4h', 'PERPETUAL'],
                'REBALANCE' => [900, '4h', 'MODEL_STACK'],
                'VWAP_TREND' => [300, '1h', 'TECHNICAL'],
                'STRATEGY_10_10X' => [900, '4h', 'TECHNICAL'],
                'STRATEGY_27_10X' => [300, '1h', 'TECHNICAL'],
            ],
        ];
    }

}
