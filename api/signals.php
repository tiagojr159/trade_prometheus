<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use Prometheus\api\ApiHandler;
use Prometheus\core\Database;

ApiHandler::handle(function (array $params) {
    $limit = ApiHandler::sanitizeLimit($_GET['limit'] ?? 36, 36, 200);
    return Database::fetchAll(
        'SELECT id, module, horizon, signal_value, confidence, metadata, created_at FROM signals ORDER BY created_at DESC LIMIT ' . (int)$limit
    );
});
