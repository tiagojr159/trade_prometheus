<?php
declare(strict_types=1);

namespace Prometheus\evaluation;

final class Backtester
{
    public function run(): array
    {
        $evaluated = (new PredictionEvaluator())->evaluateDue();
        $weights = (new WeightOptimizer())->optimize();
        return ['evaluated' => $evaluated, 'weights_updated' => $weights];
    }
}
