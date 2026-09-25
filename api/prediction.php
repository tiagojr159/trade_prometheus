<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Prometheus\api\ApiHandler;
use Prometheus\core\Database;

ApiHandler::handle(function (array $params) {
    $symbol = ApiHandler::sanitizeSymbol($_GET['symbol'] ?? null);
    $limit = ApiHandler::sanitizeLimit($_GET['limit'] ?? 4, 4, 50);
    return Database::fetchAll(
        'SELECT id, symbol, horizon, target_time, initial_price, predicted_direction, probability_up, probability_down, edge, confidence, ensemble_signal, regime, created_at
         FROM predictions WHERE symbol = ? ORDER BY created_at DESC LIMIT ' . (int)$limit,
        [$symbol]
    );
});
