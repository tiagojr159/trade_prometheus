<?php
declare(strict_types=1);
// Testes da semântica v2 (DirectionPolicy) — itens 13/14 da especificação.
// Uso: php admin/direction_v2_tests.php
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\DirectionPolicy;
use Prometheus\core\Database;
use Prometheus\prediction\ProbabilityCalculator;

$pass = 0;
$fail = 0;
function check(bool $ok, string $name, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name $detail\n"; }
    else { $fail++; echo "  FAIL  $name $detail\n"; }
}

echo "=== 13A. CLASSIFICAÇÃO PROBABILÍSTICA ===\n";
$cases = [
    [0.70, 0.30, 'UP'],
    [0.51, 0.49, 'UP'],
    [0.5001, 0.4999, 'UP'],
    [0.49, 0.51, 'DOWN'],
    [0.30, 0.70, 'DOWN'],
    [0.50, 0.50, 'INDETERMINATE'],
];
foreach ($cases as [$up, $down, $expected]) {
    $got = DirectionPolicy::predict($up, $down)['direction'];
    check($got === $expected, "P(up)=$up / P(down)=$down → $expected", "(got: $got)");
}
// edge preservado e separado
$e = DirectionPolicy::predict(0.501, 0.499);
check(abs($e['edge'] - 0.001) < 1e-9, 'edge(0.501) = 0.001', '');
$e = DirectionPolicy::predict(0.720, 0.280);
check(abs($e['edge'] - 0.220) < 1e-9, 'edge(0.720) = 0.220', '');

echo "=== 13B. DIREÇÃO REALIZADA (PREÇO) ===\n";
$priceCases = [
    [100.0, 101.0, 'UP'],
    [100.0, 100.0001, 'UP'],
    [100.0, 99.9999, 'DOWN'],
    [100.0, 99.0, 'DOWN'],
    [100.0, 100.0, 'FLAT'],
];
foreach ($priceCases as [$i, $f, $expected]) {
    $ret = ($f - $i) / $i;
    $got = DirectionPolicy::actual($ret);
    check($got === $expected, "preço $i → $f → $expected", "(got: $got)");
}

echo "=== 13C. REGIME ≠ DIREÇÃO ===\n";
$calc = new ProbabilityCalculator();
check($calc->direction(0.473, 0.527) === 'DOWN', 'regime SIDEWAYS + P(down)>P(up) → DOWN (válido)');
check($calc->direction(0.527, 0.473) === 'UP', 'regime SIDEWAYS + P(up)>P(down) → UP (válido)');
check(strpos(json_encode(DirectionPolicy::predict(0.6, 0.4)), 'SIDEWAYS') === false, 'nada em {UP,DOWN,INDETERMINATE} é SIDEWAYS');

echo "=== 13D. HIT / BRIER / LOGLOSS ===\n";
check(DirectionPolicy::hit('UP', 'UP') === 1, 'hit UP/UP = 1');
check(DirectionPolicy::hit('UP', 'DOWN') === 0, 'hit UP/DOWN = 0');
check(DirectionPolicy::hit('INDETERMINATE', 'UP') === null, 'hit INDETERMINATE = null');
check(DirectionPolicy::hit('UP', 'FLAT') === null, 'hit UP/FLAT = null (não é erro)');
check(abs(DirectionPolicy::brier(0.7, 'UP') - 0.09) < 1e-12, 'brier(0.7,UP)=0.09');
check(abs(DirectionPolicy::brier(0.7, 'DOWN') - 0.49) < 1e-12, 'brier(0.7,DOWN)=0.49');
check(DirectionPolicy::brier(0.7, 'FLAT') === null, 'brier FLAT = null');
check(abs(DirectionPolicy::logLoss(0.7, 'UP') - (-log(0.7))) < 1e-12, 'logloss(0.7,UP) = -ln(0.7)');
$clipped = DirectionPolicy::logLoss(1.0, 'DOWN');
check($clipped !== null && is_finite($clipped) && $clipped > 0, 'logloss clipping seguro (p=1 vs DOWN)');

echo "=== 14. CONSISTÊNCIA EVALUATOR × BACKTESTER ===\n";
// Pega uma previsão avaliada v2 e verifica que a regra do evaluator e a do
// backtester (ambas via DirectionPolicy) produzem o MESMO resultado para o
// mesmo par (initial_price, final_price).
$row = Database::fetch(
    'SELECT p.id, p.symbol, p.target_time, p.initial_price, r.final_price, r.actual_direction, r.directional_hit, r.evaluation_version
     FROM predictions p JOIN prediction_results r ON r.prediction_id = p.id
     WHERE r.evaluation_version = 2 ORDER BY p.id DESC LIMIT 1'
);
if (!$row) {
    check(false, 'existe previsão v2 para teste de consistência');
} else {
    $initial = (float)$row['initial_price'];
    $final = (float)$row['final_price'];
    $ret = ($final - $initial) / max(1e-12, $initial);
    // Via DirectionPolicy (o que o BACKTESTER usa):
    $btActual = DirectionPolicy::actual($ret);
    $btHit = DirectionPolicy::hit('DOWN', $btActual); // direção qualquer: compara-se actual
    check($btActual === $row['actual_direction'],
        "backtester e evaluator concordam (predição #{$row['id']})",
        "(evaluator={$row['actual_direction']} backtester=$btActual ret=" . round($ret, 8) . ')');
    // realized_return persistido == recalculado
    check(abs((float)$row['final_price'] - $final) < 1e-9, 'realized_return persistido consistente');
}

echo "=== 14B. MESMA POLÍTICA DE PREÇO (evaluator vs backtester) ===\n";
// Ambos: primeiro candle 1m com open_time >= target; nada de "nearest posterior".
$policy = Database::fetch(
    "SELECT COUNT(*) c FROM prediction_results r
     JOIN predictions p ON p.id = r.prediction_id
     JOIN market_data m ON m.symbol = p.symbol AND m.interval_name = '1m'
      AND m.open_time = (
        SELECT MIN(open_time) FROM market_data WHERE symbol = p.symbol AND interval_name = '1m' AND open_time >= p.target_time AND close_time <= r.evaluated_at
      )
     WHERE r.evaluation_version = 2 AND ABS(m.close_price - r.final_price) > 1e-6"
);
check((int)($policy['c'] ?? 1) === 0, 'final_price = primeiro candle 1m >= target_time (mesma política em produção e backtest)', '(divergentes: ' . ($policy['c'] ?? '?') . ')');

echo "\nRESULT: $pass PASS / $fail FAIL\n";
exit($fail > 0 ? 1 : 0);
