<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\DirectionPolicy;
use Prometheus\evaluation\TargetPriceResolver;

$failures = [];
$assert = static function (bool $ok, string $name) use (&$failures): void {
    if (!$ok) $failures[] = $name;
};

foreach ([
    [0.50, 0.00], [0.51, 0.01], [0.47, 0.03], [0.70, 0.20], [0.30, 0.20],
] as [$p, $expected]) {
    $edge = DirectionPolicy::predictFromUp($p)['edge'];
    $assert(abs($edge - $expected) < 1e-12, "edge for {$p}");
}
$assert(abs(DirectionPolicy::predictFromUp(0.70)['edge'] - DirectionPolicy::predictFromUp(0.30)['edge']) < 1e-12, 'edge symmetry');

$seconds = ['15m' => 900, '1h' => 3600, '4h' => 14400, '24h' => 86400];
foreach ($seconds as $horizon => $duration) {
    $t = strtotime('2026-01-01 00:00:00');
    $target = date('Y-m-d H:i:s', $t + $duration);
    $aStart = date('Y-m-d H:i:s', $t + $duration - 120);
    $aClose = date('Y-m-d H:i:s', $t + $duration - 61);
    $bStart = $target;
    $bClose = date('Y-m-d H:i:s', $t + $duration + 59);
    $cStart = date('Y-m-d H:i:s', $t + $duration + 60);
    $cClose = date('Y-m-d H:i:s', $t + $duration + 119);
    $candles = [
        ['open_time' => $aStart, 'close_time' => $aClose, 'close_price' => 1],
        ['open_time' => $bStart, 'close_time' => $bClose, 'close_price' => 2],
        ['open_time' => $cStart, 'close_time' => $cClose, 'close_price' => 3],
    ];
    $beforeCClosed = TargetPriceResolver::fromCandles($candles, $target, $bClose);
    $afterCClosed = TargetPriceResolver::fromCandles($candles, $target, $cClose);
    $assert($beforeCClosed !== null && $beforeCClosed['close_price'] === 2, "{$horizon}: select candle B and skip open candle C");
    $assert($afterCClosed !== null && $afterCClosed['close_price'] === 2, "{$horizon}: earliest eligible target candle remains B after C closes");
}

if ($failures) {
    fwrite(STDERR, "FAIL: " . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo "PASS: edge cases and closed-candle policy across 15m/1h/4h/24h" . PHP_EOL;
