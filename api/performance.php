<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Prometheus\api\ApiHandler;
use Prometheus\core\Database;
use Prometheus\evaluation\OutOfSampleEvaluator;
use Prometheus\prediction\ProbabilityCalculator;

ApiHandler::handle(function (array $params) {
    $symbol = ApiHandler::sanitizeSymbol($_GET['symbol'] ?? null);
    return [
        'modules' => Database::fetchAll('SELECT module, horizon, regime, sample_size, accuracy, brier_score, avg_confidence, updated_at FROM module_performance ORDER BY horizon, regime, module'),
        'horizons' => Database::fetchAll(
            'SELECT p.horizon, COUNT(*) AS total, AVG(r.directional_hit) AS accuracy
             FROM predictions p JOIN prediction_results r ON r.prediction_id = p.id
             WHERE p.symbol = ? AND r.evaluation_version = 2 AND r.directional_hit IS NOT NULL GROUP BY p.horizon',
            [$symbol]
        ),
        'weights' => Database::fetchAll('SELECT module, horizon, regime, weight, updated_at FROM module_weights ORDER BY horizon, regime, module'),
        'calibration' => (new ProbabilityCalculator())->calibration(),
        'out_of_sample' => (new OutOfSampleEvaluator())->run($symbol),
    ];
});
