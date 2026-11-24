<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__, 2) . '/bootstrap.php';

use Prometheus\prediction\QuantFeatures;
use Prometheus\prediction\QuantModelInference;

$fixture = json_decode(stream_get_contents(STDIN, 65536), true, 16, JSON_THROW_ON_ERROR);
$model = QuantModelInference::parse(json_encode($fixture['model'], JSON_THROW_ON_ERROR));
$features = isset($fixture['candles']) ? QuantFeatures::fromCandles($fixture['candles']) : $fixture['features'];
$probability = QuantModelInference::probability($features, $model);
echo json_encode(['features' => $features, 'probability' => $probability,
    'magnitude' => QuantModelInference::magnitude($probability, $model)], JSON_THROW_ON_ERROR);
