<?php
declare(strict_types=1);

namespace Prometheus\prediction;

use Prometheus\collectors\MarketCollector;
use Prometheus\core\Database;
use Prometheus\intelligence\PrometheusEngine;

final class PredictionService
{
    private PrometheusEngine $engine;
    private MarketCollector $market;

    public function __construct(?PrometheusEngine $engine = null, ?MarketCollector $market = null)
    {
        $this->engine = $engine ?: new PrometheusEngine();
        $this->market = $market ?: new MarketCollector();
    }

    public function generateAll(string $symbol = 'BTCUSDT'): array
    {
        $out = [];
        foreach ((new HorizonManager())->all() as $horizon => $seconds) {
            $out[] = $this->generate($horizon, $seconds, $symbol);
        }
        return $out;
    }

    public function generate(string $horizon, int $seconds, string $symbol = 'BTCUSDT'): array
    {
        $dedupeWindow = (int)prometheus_config('prediction.dedupe_window_seconds', 60);
        $existing = Database::fetch(
            'SELECT id, created_at FROM predictions WHERE symbol=? AND horizon=? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND) ORDER BY id DESC LIMIT 1',
            [$symbol, $horizon, $dedupeWindow]
        );
        if ($existing) {
            return [
                'id' => (int)$existing['id'],
                'horizon' => $horizon,
                'status' => 'skipped_duplicate',
                'created_at' => $existing['created_at'],
            ];
        }

        $price = $this->market->latestPrice($symbol);
        if ($price === null) {
            throw new \RuntimeException('No market price available. Run collect_market first.');
        }
        $analysis = $this->engine->analyze($horizon, $symbol);
        foreach ($analysis['signals'] as $signal) {
            Database::execute('INSERT INTO signals (module, horizon, signal_value, confidence, metadata) VALUES (?, ?, ?, ?, ?)', [$signal->module, $horizon, $signal->value, $signal->confidence, json_encode($signal->metadata)]);
        }
        $id = Database::insert(
            'INSERT INTO predictions (symbol, horizon, target_time, initial_price, predicted_direction, probability_up, probability_down, confidence, ensemble_signal, regime, signals_json, weights_json, edge)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$symbol, $horizon, $seconds, $price, $analysis['direction'], $analysis['probability_up'], $analysis['probability_down'], $analysis['confidence'], $analysis['ensemble_signal'], $analysis['regime']['regime'], json_encode(array_map(fn($s) => $s->toArray(), $analysis['signals'])), json_encode($analysis['weights']), $analysis['edge']]
        );
        return ['id' => $id] + $analysis;
    }
}
