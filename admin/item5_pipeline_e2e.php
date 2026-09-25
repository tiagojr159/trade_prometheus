<?php
declare(strict_types=1);

/**
 * Item 5 — Ciclo real com evidência de valores intermediários.
 * Uso: php admin/item5_pipeline_e2e.php [symbol]
 * Não usa mocks: coletores reais, módulos reais, persistência real.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\collectors\DerivativesCollector;
use Prometheus\collectors\MarketCollector;
use Prometheus\collectors\OnChainCollector;
use Prometheus\core\Database;
use Prometheus\intelligence\PrometheusEngine;
use Prometheus\prediction\PredictionService;

$symbol = $argv[1] ?? prometheus_config('default_symbol', 'BTCUSDT');
$out = ['symbol' => $symbol, 'started_at' => date('c')];

try {
    // 1) Coleta real (APIs públicas; funciona sem keys; falhas isoladas por coletor).
    $collected = [];
    try {
        $market = new MarketCollector();
        $collected['market'] = $market->collect($symbol, '1m', 60) . ' candles (1m)';
        $collected['price'] = $market->latestPrice($symbol);
    } catch (Throwable $e) {
        $collected['market_error'] = $e->getMessage();
    }
    try {
        $collected['derivatives'] = (new DerivativesCollector())->collect($symbol) . ' registros';
    } catch (Throwable $e) {
        $collected['derivatives_error'] = $e->getMessage();
    }
    try {
        $collected['onchain'] = (new OnChainCollector())->collect() . ' métricas';
    } catch (Throwable $e) {
        $collected['onchain_error'] = $e->getMessage();
    }

    $out['coleta'] = $collected;

    // 2) Regime (antes dos módulos — é como o engine roda).
    $engine = new PrometheusEngine();
    $regime = (new \Prometheus\intelligence\RegimeDetector())->detect($symbol);
    $out['regime'] = $regime;

    // 3) Sinais dos 6 módulos (com valores intermediários reais no metadata).
    $reflection = new ReflectionProperty(PrometheusEngine::class, 'modules');
    $reflection->setAccessible(true);
    $modules = $reflection->getValue($engine);

    $out['modulos'] = [];
    foreach ($modules as $module) {
        $name = (new ReflectionClass($module))->getShortName();
        try {
            $signal = $module->signal('1h', $symbol);
            $out['modulos'][$name] = [
                'module' => $signal->module,
                'signal' => round($signal->value, 4),
                'confidence' => round($signal->confidence, 4),
                'metadata' => $signal->metadata,
            ];
        } catch (Throwable $e) {
            $out['modulos'][$name] = ['error' => $e->getMessage()];
        }
    }

    // 4) Análise completa (regime→sinais→ensemble→probabilidade→direção).
    $analysis = $engine->analyze('1h', $symbol);
    $out['ensemble'] = [
        'signal' => round($analysis['ensemble_signal'], 5),
        'confidence' => round($analysis['confidence'], 5),
        'weights_usados' => $analysis['weights'],
        'contributions' => $analysis['contributions'] ?? null,
        'missing_modules' => $analysis['missing_modules'] ?? null,
        'coverage' => $analysis['coverage'] ?? null,
        'probabilidade_up' => round($analysis['probability_up'], 5),
        'probabilidade_down' => round($analysis['probability_down'], 5),
        'direcao' => $analysis['direction'],
    ];

    // 5) Persistência real da previsão (PredictionService respeita dedupe por minuto).
    $svc = new PredictionService($engine);
    $result = $svc->generate('1h', 3600, $symbol);
    $out['persistencia'] = [
        'prediction_id' => $result['id'] ?? null,
        'status' => $result['status'] ?? 'created',
    ];

    // 6) Confirmação no banco (leitura da linha persistida).
    if (!empty($result['id'])) {
        $row = Database::fetch('SELECT id, symbol, horizon, target_time, initial_price, predicted_direction, probability_up, probability_down, confidence, ensemble_signal, regime FROM predictions WHERE id = ?', [$result['id']]);
        $out['persistencia']['linha_persistida'] = $row;
    }
} catch (Throwable $e) {
    $out['fatal'] = $e->getMessage();
    $out['trace'] = $e->getTraceAsString();
}

$out['finished_at'] = date('c');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
