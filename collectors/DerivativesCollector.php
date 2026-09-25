<?php
declare(strict_types=1);

namespace Prometheus\collectors;

use Prometheus\core\Database;
use Prometheus\core\HttpClient;
use Prometheus\core\Logger;
use Prometheus\core\SourceHealth;

final class DerivativesCollector
{
    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?: new HttpClient();
    }

    public function collect(string $symbol = 'BTCUSDT'): int
    {
        $t0 = SourceHealth::begin('binance_futures');
        try {
            $count = $this->collectInner($symbol);
            $last = Database::fetch(
                "SELECT observed_at FROM derivatives_data WHERE source = 'binance_futures' ORDER BY observed_at DESC LIMIT 1"
            );
            SourceHealth::success('binance_futures', $last['observed_at'] ?? null, $t0);
            return $count;
        } catch (\Throwable $e) {
            SourceHealth::failure('binance_futures', $e->getMessage());
            throw $e;
        }
    }

    private function collectInner(string $symbol): int
    {
        $count = 0;
        $funding = $this->http->getJson('https://fapi.binance.com/fapi/v1/fundingRate?symbol=' . $symbol . '&limit=10', [], 300);
        foreach ($funding as $row) {
            Database::execute('INSERT INTO derivatives_data (metric, source, observed_at, value, metadata, available_at, ingested_at, temporal_quality, availability_source) VALUES ("funding_rate", "binance_futures", ?, ?, ?, NULL, UTC_TIMESTAMP(), "INGESTION_ONLY", "LOCAL_INGESTION") ON DUPLICATE KEY UPDATE value=VALUES(value), metadata=VALUES(metadata), ingested_at=UTC_TIMESTAMP(), temporal_quality="INGESTION_ONLY", availability_source="LOCAL_INGESTION"', [date('Y-m-d H:i:s', (int)($row['fundingTime'] / 1000)), $row['fundingRate'], json_encode($row)]);
            $count++;
        }
        $oi = $this->http->getJson('https://fapi.binance.com/fapi/v1/openInterest?symbol=' . $symbol, [], 120);
        $oiTime = isset($oi['time']) ? date('Y-m-d H:i:s', (int)($oi['time'] / 1000)) : date('Y-m-d H:i:s');
        Database::execute('INSERT INTO derivatives_data (metric, source, observed_at, value, metadata, available_at, ingested_at, temporal_quality, availability_source) VALUES ("open_interest", "binance_futures", ?, ?, ?, NULL, UTC_TIMESTAMP(), "INGESTION_ONLY", "LOCAL_INGESTION") ON DUPLICATE KEY UPDATE value=VALUES(value), metadata=VALUES(metadata), ingested_at=UTC_TIMESTAMP(), temporal_quality="INGESTION_ONLY", availability_source="LOCAL_INGESTION"', [$oiTime, $oi['openInterest'] ?? 0, json_encode($oi)]);

        // Long/short ratio global de contas (contrarian signal para o Hephaestus).
        try {
            $ls = $this->http->getJson('https://fapi.binance.com/futures/data/globalLongShortAccountRatio?symbol=' . $symbol . '&period=5m&limit=1', [], 300);
            if (isset($ls[0]['longShortRatio']) && is_numeric($ls[0]['longShortRatio'])) {
                Database::execute('INSERT INTO derivatives_data (metric, source, observed_at, value, metadata, available_at, ingested_at, temporal_quality, availability_source) VALUES ("long_short_ratio", "binance_futures", ?, ?, ?, NULL, UTC_TIMESTAMP(), "INGESTION_ONLY", "LOCAL_INGESTION") ON DUPLICATE KEY UPDATE value=VALUES(value), metadata=VALUES(metadata), ingested_at=UTC_TIMESTAMP(), temporal_quality="INGESTION_ONLY", availability_source="LOCAL_INGESTION"', [date('Y-m-d H:i:s', (int)(($ls[0]['timestamp'] ?? (int)(microtime(true) * 1000)) / 1000)), $ls[0]['longShortRatio'], json_encode($ls[0])]);
            }
        } catch (\Throwable $e) {
            // Métrica adicional indisponível: registrar, não abortar o coletor.
            Logger::warning('derivatives_long_short_unavailable', ['message' => $e->getMessage()]);
        }

        return $count + 1;
    }
}
