<?php
declare(strict_types=1);

namespace Prometheus\collectors;

use Prometheus\core\Database;
use Prometheus\core\HttpClient;
use Prometheus\core\Logger;
use Prometheus\core\SourceHealth;

final class OnChainCollector
{
    private const SOURCE = 'coin_metrics_community';
    private const BASE_URL = 'https://community-api.coinmetrics.io/v4';
    private const ASSET = 'btc';
    private const FREQUENCY = '1d';

    /** Coin Metrics Community metrics verified for BTC without an API key. */
    private const METRICS = [
        'AdrActCnt' => 'active_addresses',
        'TxCnt' => 'tx_count',
        'TxTfrCnt' => 'tx_transfer_count',
        'FeeTotNtv' => 'fees_total_native',
        'HashRate' => 'hash_rate',
        'SplyCur' => 'supply_current',
        'IssTotNtv' => 'issuance_total_native',
        'BlkCnt' => 'block_count',
    ];

    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?: new HttpClient();
    }

    public function collect(): int
    {
        $url = self::BASE_URL . '/timeseries/asset-metrics?assets=' . self::ASSET
            . '&metrics=' . implode(',', array_keys(self::METRICS))
            . '&frequency=' . self::FREQUENCY
            . '&page_size=30';

        $t0 = SourceHealth::begin('onchain');
        try {
            $payload = $this->http->getJson($url, [], 300);
        } catch (\Throwable $e) {
            SourceHealth::failure('onchain', $e->getMessage());
            Logger::error('onchain_source_unavailable', [
                'source' => self::SOURCE,
                'message' => $this->classifyError($e->getMessage()),
                'raw_message' => $e->getMessage(),
            ]);
            return 0;
        }

        $rows = $payload['data'] ?? null;
        if (!is_array($rows) || !$rows) {
            SourceHealth::failure('onchain', 'empty_api_response');
            Logger::error('onchain_source_unavailable', ['source' => self::SOURCE, 'message' => 'empty_api_response']);
            return 0;
        }

        $count = 0;
        $missing = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['time'])) {
                continue;
            }

            $observedAt = $this->toMysqlDateTime((string)$row['time']);
            if ($observedAt === null) {
                Logger::warning('onchain_metric_invalid_time', ['source' => self::SOURCE, 'time' => $row['time']]);
                continue;
            }

            foreach (self::METRICS as $apiMetric => $metric) {
                $value = $row[$apiMetric] ?? null;
                if ($value === null || !is_numeric($value)) {
                    $missing[$apiMetric] = true;
                    continue;
                }

                Database::execute(
                    'INSERT INTO onchain_data (metric, source, observed_at, value, metadata, available_at, ingested_at, temporal_quality, availability_source)
                     VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP(), "INGESTION_ONLY", "LOCAL_INGESTION")
                     ON DUPLICATE KEY UPDATE value=VALUES(value), metadata=VALUES(metadata), ingested_at=UTC_TIMESTAMP(), temporal_quality="INGESTION_ONLY", availability_source="LOCAL_INGESTION"',
                    [
                        $metric,
                        self::SOURCE,
                        $observedAt,
                        (float)$value,
                        json_encode([
                            'provider' => 'coin_metrics',
                            'api_metric' => $apiMetric,
                            'asset' => self::ASSET,
                            'frequency' => self::FREQUENCY,
                            'source_time' => $row['time'],
                        ], JSON_UNESCAPED_UNICODE),
                    ]
                );
                $count++;
            }
        }

        if ($missing) {
            Logger::warning('onchain_metrics_missing', ['source' => self::SOURCE, 'metrics' => array_keys($missing)]);
        }

        $last = Database::fetch(
            'SELECT MAX(observed_at) AS ts FROM onchain_data WHERE source = ?',
            [self::SOURCE]
        );
        SourceHealth::success('onchain', $last['ts'] ?? null, $t0);

        return $count;
    }

    private function toMysqlDateTime(string $time): ?string
    {
        $time = preg_replace('/\.(\d{6})\d+Z$/', '.$1Z', $time) ?? $time;
        $timestamp = strtotime($time);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function classifyError(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($message, 'status=429')) {
            return 'rate_limited';
        }
        if (str_contains($message, 'status=403')) {
            return 'metric_forbidden_or_api_rejected';
        }
        if (str_contains($message, 'status=') || str_contains($message, 'HTTP request failed')) {
            return 'http_error';
        }
        if (str_contains($message, 'Invalid JSON')) {
            return 'invalid_json';
        }
        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            return 'timeout';
        }
        return 'api_error';
    }
}
