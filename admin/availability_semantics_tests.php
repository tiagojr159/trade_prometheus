<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Signal;
use Prometheus\intelligence\EnsembleEngine;
use Prometheus\intelligence\SignalNormalizer;

$normalizer = new SignalNormalizer();
$signals = [];
foreach (SignalNormalizer::MODULES as $module) {
    $signals[] = $normalizer->normalize(new Signal($module, 0.0, 0.7, ['horizon' => '1h', 'status' => 'AVAILABLE']));
}
$signals[1] = $normalizer->normalize(new Signal('HERMES', 0.0, 0.1, ['horizon' => '1h', 'reason' => 'news_available_but_no_llm_output', 'status' => 'UNAVAILABLE']));
$unavailable = (new EnsembleEngine())->combine($signals, '1h', 'SIDEWAYS');
if (isset($unavailable['contributions']['HERMES']) || !in_array('HERMES', $unavailable['missing_modules'], true)) {
    throw new RuntimeException('UNAVAILABLE Hermes must be excluded from the ensemble denominator.');
}
$signals[1] = $normalizer->normalize(new Signal('HERMES', 0.0, 0.7, ['horizon' => '1h', 'status' => 'AVAILABLE']));
$neutral = (new EnsembleEngine())->combine($signals, '1h', 'SIDEWAYS');
if (!isset($neutral['contributions']['HERMES']) || count($neutral['contributions']) !== 6) {
    throw new RuntimeException('An AVAILABLE neutral signal must remain a valid ensemble observation.');
}
$bad = $normalizer->normalize(new Signal('HERMES', NAN, 0.5, ['horizon' => '1h']));
if (($bad->metadata['status'] ?? null) !== 'ERROR') {
    throw new RuntimeException('Non-finite values must be marked ERROR instead of treated as neutral.');
}
echo "PASS: unavailable excluded; available neutral included; invalid values marked ERROR" . PHP_EOL;
