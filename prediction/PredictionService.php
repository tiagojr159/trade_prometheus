<?php
declare(strict_types=1);

namespace Prometheus\prediction;

use Prometheus\collectors\MarketCollector;
use Prometheus\core\Database;
use Prometheus\core\Logger;
use Prometheus\intelligence\PrometheusEngine;
use Prometheus\prediction\Athena50Shadow;

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
        $serializedSignals = array_map(fn($s) => $s->toArray(), $analysis['signals']);
        $id = Database::insert(
            'INSERT INTO predictions (symbol, horizon, target_time, initial_price, predicted_direction, probability_up, probability_down, confidence, ensemble_signal, regime, signals_json, weights_json, edge)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$symbol, $horizon, $seconds, $price, $analysis['direction'], $analysis['probability_up'], $analysis['probability_down'], $analysis['confidence'], $analysis['ensemble_signal'], $analysis['regime']['regime'], json_encode($serializedSignals), json_encode($analysis['weights']), $analysis['edge']]
        );
        try {
            $createdAt = Database::fetch('SELECT created_at FROM predictions WHERE id=?', [$id]);
            (new Athena50Shadow())->record($id, $symbol, $horizon, (string)($analysis['regime']['regime'] ?? 'UNKNOWN'), (string)($createdAt['created_at'] ?? date('Y-m-d H:i:s')), $serializedSignals);
        } catch (\Throwable $e) {
            // Shadow is strictly observational; its schema or computation cannot block production predictions.
            Logger::warning('athena50_shadow_failed', ['prediction_id' => $id, 'horizon' => $horizon, 'message' => $e->getMessage()]);
        }
        return ['id' => $id] + $analysis;
    }
}
