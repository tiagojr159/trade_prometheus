<?php
declare(strict_types=1);

namespace Prometheus\prediction;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Prometheus\core\DirectionPolicy;
use Prometheus\core\QuantClock;
use Prometheus\core\QuantMarket;
use Prometheus\core\QuantStrategy;

final class QuantShadowService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function register(string $json, DateTimeImmutable $now): string
    {
        $model = QuantModelInference::parse($json);
        $calibration = new DateTimeImmutable($model['calibration_cutoff_utc']);
        if ($calibration >= $now) throw new \InvalidArgumentException('CALIBRATION_CUTOFF_IN_FUTURE');
        $id = hash('sha256', $json);
        $this->db->beginTransaction();
        try {
            $at = QuantClock::sql($now);
            $this->query('INSERT INTO quant_model_versions
                (model_id,symbol,horizon,training_cutoff_utc,calibration_cutoff_utc,registered_at_utc,dataset_sha256,artifact_json)
                VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE model_id=model_id',
                [$id, $model['symbol'], $model['horizon'], QuantClock::sql(new DateTimeImmutable($model['training_cutoff_utc'])),
                    QuantClock::sql($calibration), $at, $model['dataset_sha256'], $json]);
            if (!$this->query('SELECT 1 FROM quant_model_events WHERE model_id=? LIMIT 1', [$id])->fetchColumn()) {
                $this->query('INSERT INTO quant_model_events (model_id,status,effective_at_utc) VALUES (?,?,?)',
                    [$id, 'SHADOW', $at]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return $id;
    }

    public function retire(string $id, DateTimeImmutable $now): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new \InvalidArgumentException('INVALID_MODEL_ID');
        $at = QuantClock::sql($now);
        $this->db->beginTransaction();
        try {
            if (!$this->query('SELECT 1 FROM quant_model_versions WHERE model_id=? AND registered_at_utc<=? FOR UPDATE',
                [$id, $at])->fetchColumn()) {
                throw new \InvalidArgumentException('MODEL_NOT_FOUND');
            }
            $status = $this->query('SELECT status FROM quant_model_events WHERE model_id=? AND effective_at_utc<=?
                ORDER BY effective_at_utc DESC,id DESC LIMIT 1', [$id, $at])->fetchColumn();
            if ($status === 'SHADOW') {
                $this->query('INSERT INTO quant_model_events (model_id,status,effective_at_utc) VALUES (?,?,?)',
                    [$id, 'RETIRED', $at]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function predict(string $symbol, DateTimeImmutable $now): array
    {
        $at = QuantClock::sql($now);
        $models = [];
        foreach (QuantStrategy::HORIZONS as $horizon => $seconds) {
            $rows = $this->query('SELECT m.model_id,m.horizon,m.artifact_json FROM quant_model_versions m
                WHERE m.symbol=? AND m.horizon=? AND m.registered_at_utc<=? AND m.calibration_cutoff_utc<?
                AND (SELECT e.status FROM quant_model_events e
                     WHERE e.model_id=m.model_id AND e.effective_at_utc<=?
                     ORDER BY e.effective_at_utc DESC,e.id DESC LIMIT 1)=?
                ORDER BY m.registered_at_utc DESC, m.model_id DESC LIMIT 2', [$symbol, $horizon, $at, $at, $at, 'SHADOW'])
                ->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) array_push($models, ...$rows);
        }
        if (!$models) return ['status' => 'NO_REGISTERED_MODEL'];
        $candles = QuantMarket::candles($this->db, $symbol, '1m', $now);
        if (count($candles) < 16) return ['status' => 'INSUFFICIENT_CANDLE_COVERAGE'];

        $features = QuantFeatures::fromCandles($candles);
        $featureJson = json_encode($features, JSON_THROW_ON_ERROR);
        $lineageJson = json_encode(array_column($candles, 'id'), JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $featureJson . "\n" . $lineageJson);
        $slot = gmdate('Y-m-d H:i:s', intdiv($now->getTimestamp(), 300) * 300);
        $predictions = [];
        $this->db->beginTransaction();
        try {
            $snapshot = null;
            foreach ($models as $row) {
                $id = $row['model_id'];
                $horizon = $row['horizon'];
                if (!hash_equals($id, hash('sha256', $row['artifact_json']))) throw new \RuntimeException('ARTIFACT_HASH_MISMATCH');
                if ($this->query('SELECT id FROM quant_shadow_predictions WHERE model_id=? AND slot_utc=?', [$id, $slot])->fetchColumn()) {
                    continue;
                }
                if ($snapshot === null) {
                    $this->query('INSERT INTO quant_feature_snapshots
                        (symbol,as_of_utc,feature_hash,feature_schema_version,dataset_kind,features_json,lineage_json,initial_price)
                        VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',
                        [$symbol, $at, $hash, QuantFeatures::VERSION, 'RECORDED_AS_OF', $featureJson, $lineageJson, end($candles)['close_price']]);
                    $snapshot = (int)$this->db->lastInsertId();
                }
                $model = QuantModelInference::parse($row['artifact_json']);
                $probability = QuantModelInference::probability($features, $model);
                [$expected, $quantiles] = QuantModelInference::magnitude($probability, $model);
                $target = QuantClock::sql($now->modify('+' . QuantStrategy::HORIZONS[$horizon] . ' seconds'));
                $this->query('INSERT INTO quant_shadow_predictions
                    (model_id,snapshot_id,slot_utc,target_utc,probability_up,expected_return_bps,quantiles_json)
                    VALUES (?,?,?,?,?,?,?)', [$id, $snapshot, $slot, $target, $probability, $expected,
                        $quantiles === null ? null : json_encode($quantiles, JSON_THROW_ON_ERROR)]);
                $predictions[$horizon][$id] = [
                    'schema_version' => 3, 'model_id' => $id, 'market_type' => 'SPOT', 'symbol' => $symbol, 'horizon' => $horizon,
                    'as_of_utc' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                    'feature_snapshot_id' => 'sha256:' . $hash, 'training_cutoff_utc' => $model['training_cutoff_utc'],
                    'probability_up' => $probability, 'probability_down' => 1 - $probability,
                    'direction' => DirectionPolicy::predictFromUp($probability)['direction'], 'expected_return_bps' => $expected,
                    'return_quantiles_bps' => $quantiles, 'data_coverage' => count(array_filter($features, static fn($v): bool => $v !== null)) / count($features),
                    'quality_status' => 'AVAILABLE', 'regime' => null, 'meta_probability' => null,
                    'decision' => 'NO_TRADE', 'reason_codes' => ['SHADOW_ONLY'],
                ];
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['status' => 'SHADOW', 'predictions' => $predictions];
    }

    public function evaluate(DateTimeImmutable $now): array
    {
        $at = QuantClock::sql($now);
        $pending = $this->query('SELECT p.id,p.target_utc,s.initial_price,s.symbol FROM quant_shadow_predictions p
            JOIN quant_feature_snapshots s ON s.id=p.snapshot_id
            LEFT JOIN quant_shadow_labels l ON l.prediction_id=p.id AND l.label_version=1
            WHERE p.target_utc<=? AND l.prediction_id IS NULL ORDER BY p.target_utc,p.id LIMIT 250',
            [QuantClock::sql($now->modify('-10 minutes'))]);
        $counts = ['LABELLED' => 0, 'TARGET_GAP' => 0];
        $timezone = new DateTimeZone(PROMETHEUS_TIMEZONE);
        foreach ($pending as $row) {
            $target = QuantClock::fromSql($row['target_utc']);
            $local = $target->setTimezone($timezone);
            $availabilityDeadline = QuantClock::sql($target->modify('+10 minutes'));
            $candle = $this->query('SELECT id,open_time,close_time,close_price,ingested_at FROM market_data
                WHERE symbol=? AND source=? AND interval_name=? AND open_time>=? AND open_time<=?
                AND ingested_at<=? AND temporal_quality IN (\'EXACT\',\'INGESTION_ONLY\')
                AND (available_at IS NOT NULL OR temporal_quality=\'INGESTION_ONLY\')
                AND (available_at IS NULL OR available_at<=ingested_at) AND close_price>0 ORDER BY open_time LIMIT 1',
                [$row['symbol'], 'binance', '1m', $local->format('Y-m-d H:i:s.u'),
                    $local->modify('+120 seconds')->format('Y-m-d H:i:s.u'), $availabilityDeadline])->fetch(PDO::FETCH_ASSOC);
            if ($candle && (strtotime($candle['close_time']) !== strtotime($candle['open_time']) + 59
                || strtotime($candle['close_time']) > QuantClock::fromSql($candle['ingested_at'])->getTimestamp())) {
                $candle = false;
            }
            $return = $candle ? (float)$candle['close_price'] / (float)$row['initial_price'] - 1 : null;
            $status = $return === null ? 'TARGET_GAP' : 'LABELLED';
            $direction = $return === null ? null : DirectionPolicy::actual($return);
            $result = $this->query('INSERT INTO quant_shadow_labels
                (prediction_id,label_version,evaluated_at_utc,market_data_id,return_bps,direction,status)
                VALUES (?,1,?,?,?,?,?) ON DUPLICATE KEY UPDATE prediction_id=prediction_id',
                [$row['id'], $at, $candle['id'] ?? null, $return === null ? null : $return * 10000, $direction, $status]);
            $counts[$status] += $result->rowCount();
        }
        return $counts;
    }

    private function query(string $sql, array $params): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
}
