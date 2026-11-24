<?php
declare(strict_types=1);

namespace Prometheus\core;

final class QuantStrategy
{
    public const HORIZONS = ['15m' => 900, '1h' => 3600, '4h' => 14400, '24h' => 86400];

    public function evaluate(array $account, array $market, array $policy): array
    {
        $key = $account['strategy_key'];
        [, $horizon, $family] = $policy['strategies'][$key];
        if ($family === 'PERPETUAL') return ['NO_TRADE', 'PERPETUAL_EXECUTION_UNAVAILABLE', []];
        [$one, $fifteen] = $market;
        if (!$one) return ['NO_TRADE', 'CANDLE_DATA_STALE', []];
        if ($family !== 'TECHNICAL') {
            $configuration = $policy['validated_policies'][$key] ?? null;
            if (!is_array($configuration) || !isset($configuration['model_ids'], $configuration['entry_probability'])
                || !is_array($configuration['model_ids']) || count($configuration['model_ids']) === 0) {
                return ['NO_TRADE', 'NO_VALIDATED_MODEL', ['horizon' => $horizon]];
            }
            $asOf = QuantClock::sql(QuantClock::now());
            $probabilities = [];
            foreach ($configuration['model_ids'] as $modelId) {
                $row = Database::fetch('SELECT p.probability_up,p.expected_return_bps,s.features_json
                    FROM quant_shadow_predictions p JOIN quant_feature_snapshots s ON s.id=p.snapshot_id
                    JOIN quant_model_versions m ON m.model_id=p.model_id
                    WHERE p.model_id=? AND m.horizon=? AND s.as_of_utc<=? AND m.training_cutoff_utc<s.as_of_utc
                    AND s.dataset_kind=? ORDER BY s.as_of_utc DESC,p.id DESC LIMIT 1',
                    [$modelId, $horizon, $asOf, 'RECORDED_AS_OF']);
                if (!$row || $row['expected_return_bps'] === null) return ['NO_TRADE', 'MODEL_OUTPUT_UNAVAILABLE', ['horizon' => $horizon]];
                $probabilities[] = ['p' => (float)$row['probability_up'],
                    'edge' => (float)$row['expected_return_bps'],
                    'regime' => json_decode($row['features_json'], true)['regime'] ?? 'UNKNOWN'];
            }
            $up = array_sum(array_column($probabilities, 'p')) / count($probabilities);
            $edge = array_sum(array_column($probabilities, 'edge')) / count($probabilities);
            if ($key === 'INVERSE') $up = 1 - $up;
            $threshold = (float)$configuration['entry_probability'];
            $detail = ['horizon' => $horizon, 'probability_up' => $up, 'expected_edge_pct' => $edge / 100,
                'model_ids' => $configuration['model_ids']];
            if ($key === 'ADAPTIVE' && count(array_unique(array_column($probabilities, 'regime'))) > 1) {
                return ['NO_TRADE', 'MODEL_REGIME_DISAGREEMENT', $detail];
            }
            if ($up >= $threshold && $edge > 0) return ['OPEN_LONG', 'VALIDATED_MODEL_SIGNAL', $detail];
            if ($up <= 1 - $threshold && bccomp($account['quantity'], '0', 12) > 0) {
                return ['CLOSE_LONG', 'MODEL_EXIT', $detail];
            }
            return ['NO_TRADE', 'MODEL_EDGE_TOO_LOW', $detail];
        }
        $last = end($one);
        $regime = 'UNKNOWN';
        if (count($fifteen) >= 5) {
            $start = (float)$fifteen[count($fifteen) - 5]['close_price'];
            $move = (float)end($fifteen)['close_price'] / $start - 1.0;
            $regime = abs($move) < 0.005 ? 'SIDEWAYS' : ($move > 0 ? 'BULLISH' : 'BEARISH');
        }
        $proposal = (new PaperStrategyEngine())->decide(
            $key, ['regime' => $regime, 'predicted_direction' => null, 'confidence' => 0.0],
            ['position_side' => bccomp($account['quantity'], '0', 12) > 0 ? 'LONG' : 'FLAT'],
            ['fee_pct' => (float)$policy['fee_rate'] * 100, 'slippage_pct' => (float)$policy['impact_rate'] * 100,
                'min_edge_pct' => $policy['entry_buffer_bps'] / 100, 'allow_short' => false],
            $one, $fifteen, [], false
        );
        $detail = [
            'horizon' => $horizon, 'proposal' => $proposal['action'], 'heuristic_pct' => $proposal['expected_edge_pct'],
            'last_candle_id' => (int)$last['id'], 'candle_ids' => array_map('intval', array_column(array_merge($one, $fifteen), 'id')),
        ];
        if ($proposal['action'] === 'OPEN_LONG') return ['OPEN_LONG', 'TECHNICAL_SIGNAL', $detail];
        if ($proposal['action'] === 'CLOSE_LONG') return ['CLOSE_LONG', 'TECHNICAL_EXIT', $detail];
        return ['NO_TRADE', $proposal['reason_codes'][0], $detail];
    }
}
