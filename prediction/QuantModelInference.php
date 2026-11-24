<?php
declare(strict_types=1);

namespace Prometheus\prediction;

use DateTimeImmutable;
use Prometheus\core\QuantStrategy;

final class QuantModelInference
{
    public static function parse(string $json): array
    {
        if (strlen($json) > 65536) throw new \InvalidArgumentException('ARTIFACT_TOO_LARGE');
        $model = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($model) || ($model['feature_schema_version'] ?? null) !== QuantFeatures::VERSION
            || ($model['market_type'] ?? null) !== 'SPOT' || ($model['symbol'] ?? null) !== 'BTCUSDT'
            || !is_string($model['horizon'] ?? null) || !isset(QuantStrategy::HORIZONS[$model['horizon']])
            || ($model['features'] ?? null) !== QuantFeatures::NAMES
            || !in_array($model['dataset_kind'] ?? null, ['RECORDED_AS_OF', 'RECONSTRUCTED_RESEARCH'], true)
            || !is_string($model['dataset_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $model['dataset_sha256'])) {
            throw new \InvalidArgumentException('INVALID_ARTIFACT_SCHEMA');
        }
        foreach (['training_cutoff_utc', 'calibration_cutoff_utc'] as $key) {
            $date = $model[$key] ?? null;
            if (!is_string($date) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $date)
                || (new DateTimeImmutable($date))->format('Y-m-d\TH:i:s\Z') !== $date) {
                throw new \InvalidArgumentException('INVALID_ARTIFACT_TIME');
            }
        }
        if ($model['training_cutoff_utc'] >= $model['calibration_cutoff_utc']) {
            throw new \InvalidArgumentException('INVALID_ARTIFACT_CUTOFF');
        }
        $keys = array_keys($model['features']);
        foreach (['impute', 'mean', 'scale', 'weights', 'missing_weights'] as $key) {
            if (!is_array($model[$key] ?? null) || array_keys($model[$key]) !== $keys) {
                throw new \InvalidArgumentException('INVALID_ARTIFACT_VECTOR');
            }
            foreach ($model[$key] as $value) {
                self::number($value);
                if ($key === 'scale' && $value <= 0) throw new \InvalidArgumentException('INVALID_ARTIFACT_SCALE');
            }
        }
        foreach (['intercept', 'platt_a', 'platt_b'] as $key) self::number($model[$key] ?? null);
        if (array_key_exists('magnitude_baseline', $model)) {
            foreach (['up' => 1, 'down' => -1] as $direction => $sign) {
                $summary = $model['magnitude_baseline'][$direction] ?? [];
                if (!is_array($summary) || self::number($summary['mean_bps'] ?? null) * $sign <= 0
                    || !is_array($summary['deciles_bps'] ?? null) || array_keys($summary['deciles_bps']) !== range(0, 10)) {
                    throw new \InvalidArgumentException('INVALID_MAGNITUDE_SCHEMA');
                }
                $lower = -INF;
                foreach ($summary['deciles_bps'] as $value) {
                    $value = self::number($value);
                    if ($value * $sign <= 0 || $value < $lower) throw new \InvalidArgumentException('INVALID_MAGNITUDE_QUANTILE');
                    $lower = $value;
                }
            }
        }
        return $model;
    }

    public static function probability(array $features, array $model): float
    {
        $logit = $model['intercept'];
        foreach ($model['features'] as $i => $name) {
            $value = $features[$name] ?? null;
            $missing = $value === null;
            $value = $missing ? $model['impute'][$i] : self::number($value);
            $logit += ($value - $model['mean'][$i]) / $model['scale'][$i] * $model['weights'][$i]
                + ($missing ? $model['missing_weights'][$i] : 0.0);
        }
        $calibrated = self::number($model['platt_a'] * $logit + $model['platt_b']);
        if ($calibrated >= 0) return 1.0 / (1.0 + exp(-$calibrated));
        $exponent = exp($calibrated);
        return $exponent / (1.0 + $exponent);
    }

    public static function magnitude(float $probabilityUp, array $model): array
    {
        if (!isset($model['magnitude_baseline'])) return [null, null];
        $probabilityDown = 1.0 - $probabilityUp;
        $distribution = $model['magnitude_baseline'];
        $expected = $probabilityUp * $distribution['up']['mean_bps'] + $probabilityDown * $distribution['down']['mean_bps'];
        $quantiles = [];
        foreach (['q10' => 0.10, 'q50' => 0.50, 'q90' => 0.90] as $name => $q) {
            $down = $q <= $probabilityDown;
            $conditional = $down ? $q / $probabilityDown : ($q - $probabilityDown) / $probabilityUp;
            $index = min(10.0, max(0.0, 10.0 * $conditional));
            $lower = (int)floor($index);
            $upper = (int)ceil($index);
            $values = $distribution[$down ? 'down' : 'up']['deciles_bps'];
            $quantiles[$name] = $values[$lower] + ($index - $lower) * ($values[$upper] - $values[$lower]);
        }
        return [$expected, $quantiles];
    }

    private static function number($value): float
    {
        if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value)) {
            throw new \InvalidArgumentException('INVALID_ARTIFACT_NUMBER');
        }
        return (float)$value;
    }
}
