<?php
declare(strict_types=1);

namespace Prometheus\core\Arbitrage;

abstract class AbstractStrategy implements TradingStrategy
{
    abstract public function key(): string;
    abstract public function generateSignals(array $snapshot): array;

    public function estimateProfit(array $opportunity): array
    {
        $gross = (float)($opportunity['gross_profit'] ?? 0);
        $costs = (float)($opportunity['costs'] ?? 0);
        return ['gross_profit' => $gross, 'costs' => $costs, 'net_profit' => $gross - $costs];
    }

    public function evaluateRisk(array $opportunity, array $limits = []): array
    {
        $reasons = [];
        $maxAllocation = (float)($limits['max_allocation_pct'] ?? 5.0);
        $maxExchange = (float)($limits['max_exchange_exposure_pct'] ?? 25.0);
        if (!empty($opportunity['stale'])) $reasons[] = 'STALE_MARKET_DATA';
        if (($opportunity['capital_pct'] ?? 0) > $maxAllocation) $reasons[] = 'ALLOCATION_LIMIT';
        if (($opportunity['exchange_exposure_pct'] ?? 0) > $maxExchange) $reasons[] = 'EXCHANGE_EXPOSURE_LIMIT';
        if (($opportunity['net_profit'] ?? 0) <= 0) $reasons[] = 'NON_POSITIVE_NET_EDGE';
        if (empty($opportunity['data_quality'])) $reasons[] = 'INSUFFICIENT_DATA_QUALITY';
        return ['approved' => $reasons === [], 'reasons' => $reasons];
    }

    public function simulateExecution(array $opportunity, array $snapshot): array
    {
        // Research-only fill estimate. This interface has no order-transmission capability.
        return ['mode' => 'SIMULATION', 'fillable' => false, 'reason' => 'NO_EXECUTABLE_FILL_MODEL',
            'estimated_net_profit' => (float)($opportunity['net_profit'] ?? 0)];
    }

    public function calculatePerformance(array $trades): array
    {
        $pnl = array_map(static fn(array $t): float => (float)($t['net_profit'] ?? 0), $trades);
        $wins = count(array_filter($pnl, static fn(float $v): bool => $v > 0));
        $sum = array_sum($pnl);
        $loss = abs(array_sum(array_filter($pnl, static fn(float $v): bool => $v < 0)));
        return ['trades' => count($pnl), 'net_profit' => $sum,
            'win_rate' => $pnl ? $wins / count($pnl) : null,
            'profit_factor' => $loss > 0 ? array_sum(array_filter($pnl, static fn(float $v): bool => $v > 0)) / $loss : null,
            'classification' => 'SIMULATED_OR_RESEARCH_ONLY'];
    }

    protected function number($v): ?float { return is_numeric($v) && is_finite((float)$v) ? (float)$v : null; }
    protected function validBook(array $b): bool
    {
        return $this->number($b['bid'] ?? null) > 0 && $this->number($b['ask'] ?? null) >= $this->number($b['bid'] ?? null)
            && $this->number($b['bid_qty'] ?? null) >= 0 && $this->number($b['ask_qty'] ?? null) >= 0;
    }
}
