<?php
declare(strict_types=1);

namespace Prometheus\core\Arbitrage;

interface TradingStrategy
{
    public function key(): string;
    public function generateSignals(array $snapshot): array;
    public function estimateProfit(array $opportunity): array;
    public function evaluateRisk(array $opportunity, array $limits = []): array;
    public function simulateExecution(array $opportunity, array $snapshot): array;
    public function calculatePerformance(array $trades): array;
}
