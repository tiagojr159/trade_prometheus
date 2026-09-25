<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\indicators\ATR;
use Prometheus\indicators\BollingerBands;
use Prometheus\indicators\EMA;
use Prometheus\indicators\MACD;
use Prometheus\indicators\RSI;
use Prometheus\indicators\Volume;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

function assert_close(string $name, $actual, float $expected, float $tolerance = 0.000001): array
{
    $ok = $actual !== null && abs((float)$actual - $expected) <= $tolerance;
    return ['name' => $name, 'ok' => $ok, 'actual' => $actual, 'expected' => $expected];
}

$values = range(1, 30);
$ema = EMA::calculate($values, 3);
$bb = BollingerBands::latest(range(1, 20), 20, 2.0);
$rsiUp = RSI::latest(range(1, 20), 14);
$macd = MACD::latest(array_merge(array_fill(0, 40, 100.0), range(101.0, 140.0))); // platô + rampa: histogram > 0 (rampa pura converge para 0)
$candles = [];
for ($i = 1; $i <= 20; $i++) {
    $candles[] = ['high_price' => $i + 2, 'low_price' => $i, 'close_price' => $i + 1];
}

$tests = [
    assert_close('EMA period 3 latest ascending 1..30', end($ema), 29.0),
    assert_close('RSI ascending should be 100', $rsiUp, 100.0),
    assert_close('Bollinger middle 1..20', $bb['middle'], 10.5),
    assert_close('ATR synthetic constant range', ATR::latest($candles, 14), 2.0),
    assert_close('Volume neutral constant', Volume::score(array_fill(0, 40, 10.0)), 0.0),
    ['name' => 'MACD ascending has positive histogram', 'ok' => ($macd['histogram'] ?? 0) > 0, 'actual' => $macd['histogram'], 'expected' => '> 0'],
];

$failed = array_values(array_filter($tests, static function (array $test): bool {
    return !$test['ok'];
}));

echo json_encode(['tests' => $tests, 'failed' => $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($failed ? 1 : 0);
