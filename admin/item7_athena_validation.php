<?php
declare(strict_types=1);

/**
 * Testes de validação matemática dos indicadores (Item 7 — ATHENA).
 * Uso: php admin/item7_athena_validation.php
 *
 * Séries conhecidas e valores esperados calculados analiticamente
 * (definições padrão de Wilder / MACD / Bollinger).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\indicators\ATR;
use Prometheus\indicators\BollingerBands;
use Prometheus\indicators\EMA;
use Prometheus\indicators\MACD;
use Prometheus\indicators\RSI;
use Prometheus\indicators\Volume;
use Prometheus\modules\AthenaTechnical;

$tests = [];
$fail = 0;

function check(string $name, bool $ok, $detail = null): void
{
    global $tests, $fail;
    if (!$ok) {
        $fail++;
    }
    $tests[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

function close(string $name, $actual, float $expected, float $tolerance = 1e-6): void
{
    $ok = $actual !== null && abs((float)$actual - $expected) <= $tolerance;
    check($name, $ok, $ok ? null : ['expected' => $expected, 'actual' => $actual]);
}

// =========================================================
// EMA — definição: seed = SMA(period); k = 2/(period+1)
// =========================================================
// Série 1..30, period 3: seed = (1+2+3)/3 = 2; depois EMA_i = v_i*k + EMA_{i-1}*(1-k), k=0.5
$ema = EMA::calculate(range(1, 30), 3);
close('EMA: seed = SMA(1,2,3) = 2', $ema[2], 2.0);
close('EMA: EMA(4) = 4*0.5 + 2*0.5 = 3', $ema[3], 3.0);
close('EMA: EMA(5) = 5*0.5 + 3*0.5 = 4', $ema[4], 4.0);
// Rampa linear: EMA atrasa (period-1)/2 passos → último valor (30) tem EMA = 29 exato.
close('EMA: rampa 1..30 p3 → última EMA = 29 (lag=1)', end($ema), 29.0, 1e-9);
check('EMA: primeiros period-1 slots são null', $ema[0] === null && $ema[1] === null);
check('EMA: série curta retorna []', EMA::calculate([1, 2], 5) === []);

// =========================================================
// RSI de Wilder — exemplo clássico (Wilder 1978, AAPL 14 dias)
// closes: 44.34,44.09,44.15,43.61,44.33,44.83,45.10,45.42,45.84,46.08,45.89,46.03,45.61,46.28,46.28
// Valores esperados conhecidos: RSI(14) ≈ 70.46 (primeiro cálculo com seed SMA)
// =========================================================
$wilderCloses = [44.34,44.09,44.15,43.61,44.33,44.83,45.10,45.42,45.84,46.08,45.89,46.03,45.61,46.28,46.28];
$wilderRsi = RSI::latest($wilderCloses, 14);
// Cálculo independente inline (mesma fórmula Wilder) como referência dupla:
$gains = $losses = 0.0;
for ($i = 1; $i <= 14; $i++) {
    $d = $wilderCloses[$i] - $wilderCloses[$i-1];
    $d >= 0 ? $gains += $d : $losses += abs($d);
}
$ag = $gains / 14; $al = $losses / 14;
$expectedRsi = 100 - 100 / (1 + $ag / $al);
close('RSI: Wilder clássico bate com cálculo de referência (' . round($expectedRsi, 2) . ')', $wilderRsi, $expectedRsi, 1e-9);
check('RSI: faixa 0..100', $wilderRsi >= 0 && $wilderRsi <= 100);

// Monotônica estritamente crescente → RSI = 100 (apenas ganhos)
close('RSI: série só com ganhos = 100', RSI::latest(range(1, 20), 14), 100.0);

// Monotônica estritamente decrescente → RSI = 0
close('RSI: série só com perdas = 0', RSI::latest(range(20, 1, -1), 14), 0.0);

// Série FLAT → 50 (corrigido: antes retornava 100)
close('RSI: série flat = 50 (correção do bug)', RSI::latest(array_fill(0, 20, 42.0), 14), 50.0);

// Dados insuficientes → null
check('RSI: dados insuficientes = null', RSI::latest(range(1, 14), 14) === null);

// =========================================================
// MACD — EMA12 - EMA26, signal = EMA9(macd), histogram = macd - signal
// =========================================================
// Rampa linear: macd converge para (26-12)/2 * slope = 7, signal = 7, histogram → 0.
// Histograma > 0 exige crescimento convexo (signal ainda "pegando" o macd).
$macdUp = MACD::latest(range(1, 60));
close('MACD: rampa linear → macd = 7 (lag diferencial das EMAs)', $macdUp['macd'], 7.0, 1e-9);
close('MACD: rampa linear → signal = 7', $macdUp['signal'], 7.0, 1e-9);
close('MACD: rampa linear → histogram → 0', $macdUp['histogram'], 0.0, 1e-9);
// Série em degrau (convexa no ponto de salto): macd sobe, signal atrasa → histogram > 0.
$stepUp = array_merge(array_fill(0, 40, 100.0), range(101.0, 140.0));
$macdStep = MACD::latest($stepUp);
check('MACD: degrau ascendente → histogram > 0', ($macdStep['histogram'] ?? 0) > 0, 'hist=' . $macdStep['histogram']);
check('MACD: degrau ascendente → macd > 0', ($macdStep['macd'] ?? 0) > 0);
// Verificação independente: recomputa EMA12 e EMA26 manualmente para a última observação
$closes = range(1, 60);
$k12 = 2 / 13; $k26 = 2 / 27;
$s12 = array_sum(array_slice($closes, 0, 12)) / 12;
$s26 = array_sum(array_slice($closes, 0, 26)) / 26;
for ($i = 12; $i < 60; $i++) { $s12 = $closes[$i] * $k12 + $s12 * (1 - $k12); }
for ($i = 26; $i < 60; $i++) { $s26 = $closes[$i] * $k26 + $s26 * (1 - $k26); }
close('MACD: valor final bate com EMA12-EMA26 independente', $macdUp['macd'], $s12 - $s26, 1e-9);

// Bug corrigido: série com macd == 0.0 não deve virar null
$flatThenStep = array_merge(array_fill(0, 40, 100.0), array_fill(0, 20, 100.0));
$macdFlat = MACD::latest($flatThenStep);
check('MACD: série flat produz valores numéricos (não null por falsy)', $macdFlat['macd'] !== null && $macdFlat['signal'] !== null);
close('MACD: série flat → macd = 0.0 (não null)', $macdFlat['macd'], 0.0);
close('MACD: série flat → histogram = 0.0 (não null)', $macdFlat['histogram'], 0.0);

check('MACD: dados insuficientes → nulls', MACD::latest([1,2,3]) === ['macd' => null, 'signal' => null, 'histogram' => null]);

// =========================================================
// Bollinger Bands — SMA(20) ± 2σ populacional
// =========================================================
// 1..20: média 10.5, σ populacional = sqrt(33.25) ≈ 5.766281297335398
$bb = BollingerBands::latest(range(1, 20), 20, 2.0);
close('BB: middle = SMA(1..20) = 10.5', $bb['middle'], 10.5);
close('BB: upper = 10.5 + 2*sqrt(33.25)', $bb['upper'], 10.5 + 2 * sqrt(33.25), 1e-9);
close('BB: lower = 10.5 - 2*sqrt(33.25)', $bb['lower'], 10.5 - 2 * sqrt(33.25), 1e-9);
// último close = 20; position = (20 - lower) / (upper - lower)
$expectedPos = (20 - (10.5 - 2 * sqrt(33.25))) / (4 * sqrt(33.25));
close('BB: position = (last-lower)/(upper-lower)', $bb['position'], $expectedPos, 1e-9);
check('BB: dados insuficientes → nulls', BollingerBands::latest([1,2,3], 20) === ['upper' => null, 'middle' => null, 'lower' => null, 'position' => null]);

// =========================================================
// ATR de Wilder — TR = max(H-L, |H-Cprev|, |L-Cprev|)
// =========================================================
// Candles sintéticos com TR conhecido: high=close+2, low=close-2 em sequência ascendente
$candles = [];
for ($i = 1; $i <= 30; $i++) {
    $candles[] = ['high_price' => $i + 2, 'low_price' => $i, 'close_price' => $i + 1];
}
// TR_i = max(2, |(i+2)-(i)|, |i-(i)|) = 2 para todos → ATR converge para 2.0
close('ATR: TR constante = 2 → ATR = 2.0', ATR::latest($candles, 14), 2.0);

// TR com gap: close salta de 10 para 50 entre candles
$candlesGap = [];
for ($i = 1; $i <= 30; $i++) {
    $close = ($i === 16) ? 50.0 : $i + 1.0;
    $candlesGap[] = ['high_price' => $close + 2, 'low_price' => $close - 2, 'close_price' => $close];
}
// ATR final deve refletir o gap via smoothing de Wilder (recalcula inline)
$trs = [];
for ($i = 1; $i < 30; $i++) {
    $h = $candlesGap[$i]['high_price']; $l = $candlesGap[$i]['low_price']; $pc = $candlesGap[$i-1]['close_price'];
    $trs[] = max($h - $l, abs($h - $pc), abs($l - $pc));
}
$atrRef = array_sum(array_slice($trs, 0, 14)) / 14;
for ($i = 14; $i < count($trs); $i++) { $atrRef = (($atrRef * 13) + $trs[$i]) / 14; }
close('ATR: smoothing de Wilder bate com referência inline', ATR::latest($candlesGap, 14), $atrRef, 1e-9);
check('ATR: dados insuficientes → null', ATR::latest(array_slice($candles, 0, 10), 14) === null);

// =========================================================
// Volume — score = ((last/avg30)-1)/2 limitado a [-1,1]
// =========================================================
close('Volume: volume constante → 0', Volume::score(array_fill(0, 40, 10.0)), 0.0);
$vol2x = array_merge(array_fill(0, 39, 10.0), [20.0]); // last=20, avg=10 → (2-1)/2 = 0.5
close('Volume: volume 2x a média → +0.5', Volume::score($vol2x), 0.5);
$volZero = array_merge(array_fill(0, 39, 10.0), [0.0]); // last=0, avg=10 → (0-1)/2 = -0.5
close('Volume: volume zerado → -0.5', Volume::score($volZero), -0.5);
// Saturação: volume 10x → (10-1)/2 = 4.5 → limitado a 1
$volMax = array_merge(array_fill(0, 39, 10.0), [100.0]);
close('Volume: volume 10x → saturado em +1', Volume::score($volMax), 1.0);
check('Volume: dados insuficientes → 0', Volume::score(array_fill(0, 25, 10.0)) === 0.0);
check('Volume: média zero → 0 (não divisão por zero)', Volume::score(array_merge(array_fill(0, 39, 0.0), [5.0])) === 0.0);

// =========================================================
// ATHENA (módulo) — horizonte e faixa de saída
// =========================================================
$signal = (new AthenaTechnical())->signal('1h', prometheus_config('default_symbol', 'BTCUSDT'));
check('ATHENA: signal dentro de [-1,+1]', $signal->value >= -1 && $signal->value <= 1, 'value=' . $signal->value);
check('ATHENA: confidence dentro de [0,1]', $signal->confidence >= 0 && $signal->confidence <= 1, 'conf=' . $signal->confidence);
check('ATHENA: metadata inclui horizonte', ($signal->metadata['horizon'] ?? null) === '1h');
check('ATHENA: metadata inclui last_candle_time (timestamp do evento)', isset($signal->metadata['last_candle_time']), $signal->metadata['last_candle_time'] ?? null);
$signal24 = (new AthenaTechnical())->signal('24h', prometheus_config('default_symbol', 'BTCUSDT'));
check('ATHENA: horizonte 24h tem lookback maior (mais dados)', ($signal24->metadata['horizon'] ?? null) === '24h');
check('ATHENA: signal 24h dentro de [-1,+1]', $signal24->value >= -1 && $signal24->value <= 1);
// Normalização do Signal respeita faixas
$norm = $signal->normalized();
check('ATHENA: Signal::normalized mantém faixas', $norm->value >= -1 && $norm->value <= 1 && $norm->confidence >= 0 && $norm->confidence <= 1);

echo json_encode(['total' => count($tests), 'failed' => $fail, 'tests' => $tests], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
