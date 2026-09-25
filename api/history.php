<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Prometheus\api\ApiHandler;
use Prometheus\core\Database;

ApiHandler::handle(function (array $params) {
    $symbol = ApiHandler::sanitizeSymbol($_GET['symbol'] ?? null);
    $limit = ApiHandler::sanitizeLimit($_GET['limit'] ?? 100, 100, 500);
    return Database::fetchAll(
        'SELECT p.id, p.symbol, p.horizon, p.target_time, p.initial_price, p.predicted_direction, p.probability_up, p.edge, p.confidence, p.regime, p.created_at,
                r.final_price, r.actual_direction, r.correct, r.directional_hit, r.evaluation_version, r.return_pct, r.evaluated_at
         FROM predictions p
         LEFT JOIN prediction_results r ON r.prediction_id = p.id
         WHERE p.symbol = ?
         ORDER BY p.created_at DESC LIMIT ' . (int)$limit,
        [$symbol]
    );
});
