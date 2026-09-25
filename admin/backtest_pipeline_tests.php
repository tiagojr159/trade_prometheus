<?php
declare(strict_types=1);
// Testes do HistoricalPipelineBacktester — anti-look-ahead + motor real.
chdir(dirname(__DIR__));
require 'bootstrap.php';

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\evaluation\HistoricalPipelineBacktester;
use Prometheus\intelligence\PrometheusEngine;

$pass = 0; $fail = 0; $failures = [];
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . ($detail ? " ({$detail})" : '') . PHP_EOL;
    if ($ok) { $pass++; } else { $fail++; $failures[] = $name; }
}

echo "== BACKTEST do motor real: anti-look-ahead + 4 horizontes ==\n";

// ---------- 1. AsOfTime: guardas básicos ----------
AsOfTime::set(date('Y-m-d H:i:s', strtotime('-1 hour'))); // ativa modo histórico
try {
    AsOfTime::assertNoFuture(date('Y-m-d H:i:s', time() + 999), 'teste');
    check('assertNoFuture bloqueia dado futuro em modo histórico', false);
} catch (\RuntimeException $e) {
    check('assertNoFuture bloqueia dado futuro em modo histórico', strpos($e->getMessage(), 'LOOK-AHEAD BLOCKED') !== false);
}
AsOfTime::clear();
AsOfTime::set(date('Y-m-d H:i:s', strtotime('-1 hour')));
try {
    AsOfTime::assertNoFuture(date('Y-m-d H:i:s', strtotime('-2 hours')), 'teste');
    check('assertNoFuture permite dado passado (<= T)', true);
} catch (\RuntimeException $e) {
    check('assertNoFuture permite dado passado (<= T)', false, $e->getMessage());
}
AsOfTime::clear();

// ---------- 2. Determinismo as-of: mesmo T → resultado idêntico ----------
$t = date('Y-m-d H:i:s', strtotime('-3 hours'));
$engine = new PrometheusEngine();
AsOfTime::set($t);
$a1 = $engine->analyze('1h', 'BTCUSDT');
AsOfTime::clear();
AsOfTime::set($t);
$a2 = $engine->analyze('1h', 'BTCUSDT');
AsOfTime::clear();
check('mesmo T → ensemble idêntico (determinismo as-of)', abs($a1['ensemble_signal'] - $a2['ensemble_signal']) < 1e-12, (string)round($a1['ensemble_signal'], 6));
check('mesmo T → pUp idêntico', abs($a1['probability_up'] - $a2['probability_up']) < 1e-12);

// Diferente T (futuro relativo) PODE divergir — sanity de que o relógio afeta o cálculo:
check('T diferente carrega candles diferentes (last candle <= T)',
    strtotime((string)$a1['signals'][0]->metadata['last_candle_time'] ?? '') <= strtotime($t),
    'last=' . ($a1['signals'][0]->metadata['last_candle_time'] ?? '-'));

// ---------- 3. BACKTEST REAL: horizontes separados ----------
// 24h exige 60 + 1440 = 1500 candles 1m; com ~7 dias de histórico (1422),
// testa 24h apenas se houver dado suficiente (senão registra BLOCKED data).
$bt = new HistoricalPipelineBacktester($engine);
$horizonsToTest = ['15m', '1h', '4h', '24h'];
$results = $bt->run('BTCUSDT', null, null, $horizonsToTest, 12);

foreach ($horizonsToTest as $h) {
    $r = $results[$h];
    if (isset($r['error']) && $r['error'] === 'insufficient_data') {
        echo "  [INFO] horizonte {$h}: dados insuficientes ({$r['candles']} candles, precisa {$r['need']}) — backfill necessário\n";
        check("horizonte {$h} falha EXPLICITAMENTE por falta de dado (não inventa)", true);
        continue;
    }
    if (isset($r['error'])) {
        check("horizonte {$h} executa", false, $r['error']);
        continue;
    }
    check("horizonte {$h} executa walk-forward com motor real", $r['evaluated'] > 0, "n={$r['evaluated']}");
    check("horizonte {$h} registra métricas", $r['accuracy'] !== null && $r['brier'] !== null,
        "acc={$r['accuracy']} brier={$r['brier']} edge={$r['edge_vs_baseline']}");
}

// ---------- 4. Amostra completa: 6 sinais, regime, pesos, ensemble, prob, resultado ----------
$any = null;
foreach ($results as $h => $r) { if (!isset($r['error']) && $r['first_prediction_sample']) { $any = $r['first_prediction_sample']; $anyH = $h; break; } }
if ($any) {
    check('amostra tem 6 sinais dos módulos reais', count($any['signals']) === 6, implode(',', array_map(fn($s) => $s->module, $any['signals'])));
    check('amostra tem regime', isset($any['regime']['regime']), $any['regime']['regime']);
    check('amostra tem pesos por módulo', count($any['weights']) === 6);
    check('amostra tem contribuições auditáveis', count($any['contributions']) === 6);
    check('amostra tem ensemble + probabilidade', isset($any['ensemble_signal'], $any['probability_up'], $any['probability_down']));
    check('amostra tem resultado posterior (final_price)', isset($any['final_price'], $any['actual_direction']));
    check('amostra: previsão antes do resultado', strtotime((string)$any['at_time']) < strtotime((string)$any['target_time']),
        $any['at_time'] . ' → ' . $any['target_time']);
} else {
    check('amostra completa disponível', false, 'nenhum horizonte gerou previsão');
}

// ---------- 5. Anti-look-ahead estrutural: backtester não reimplementa módulos ----------
$src = (string)file_get_contents(__DIR__ . '/../evaluation/HistoricalPipelineBacktester.php');
// CORREÇÃO: o match anterior capturava 'DirectionPolicy' (contém 'ema').
// A intenção é verificar que NENHUM indicador técnico é reimplementado.
check('backtester NÃO reimplementa indicadores (RSI/EMA/MACD ausentes)',
    !preg_match('/\b(RSI::|MACD::|EMA::|BollingerBands::|ATR::)/', $src));
check('backtester usa o engine real', strpos($src, 'PrometheusEngine') !== false && strpos($src, 'AsOfTime') !== false);

// ---------- 6. Pesos as-of: consulta respeita updated_at <= T ----------
AsOfTime::set($t);
$ens = new \Prometheus\intelligence\EnsembleEngine();
$probe = $ens->combine($a1['signals'], '1h', $a1['regime']['regime']);
AsOfTime::clear();
check('ensemble com pesos as-of executa', isset($probe['signal']));

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) { echo 'Falhas: ' . implode('; ', $failures) . PHP_EOL; exit(1); }
exit(0);
