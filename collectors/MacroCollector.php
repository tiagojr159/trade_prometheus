<?php
declare(strict_types=1);

namespace Prometheus\collectors;

use Prometheus\core\Database;
use Prometheus\core\HttpClient;
use Prometheus\core\SourceHealth;
use Prometheus\core\Logger;

final class MacroCollector
{
    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?: new HttpClient();
    }

    public function collect(): int
    {
        $keys = require PROMETHEUS_ROOT . '/config/api_keys.php';
        if (empty($keys['fred_api_key'])) {
            // Fonte configurada mas sem credencial: UNAVAILABLE explícito, nunca dado fictício.
            SourceHealth::unavailable('fred', 'FRED_API_KEY not configured');
            Logger::warning('macro_source_unavailable', ['reason' => 'FRED_API_KEY not configured']);
            return 0;
        }
        $t0 = SourceHealth::begin('fred');
        $series = ['DGS10' => 'us_10y_yield', 'DTWEXBGS' => 'dollar_index_proxy', 'FEDFUNDS' => 'fed_funds'];
        $count = 0;
        $lastTs = null;
        foreach ($series as $fred => $metric) {
            try {
                // Backfill: 400 observações (≈ 16 meses de série diária) para
                // permitir normalização temporal real (z-score da própria série),
                // não apenas a última observação. INSERT IGNORE preserva o
                // histórico já coletado (dedupe por uniq_macro).
                $url = 'https://api.stlouisfed.org/fred/series/observations?series_id=' . $fred . '&api_key=' . urlencode($keys['fred_api_key']) . '&file_type=json&sort_order=desc&limit=400';
                $obsList = $this->http->getJson($url, [], 3600)['observations'] ?? [];
                $inserted = 0;
                foreach ($obsList as $obs) {
                    if ($obs && isset($obs['value']) && is_numeric($obs['value'])) {
                        // FRED retorna '.' para dado ausente — NÃO é zero real.
                        $obsTs = $obs['date'] . ' 00:00:00';
                        $lastTs = $lastTs ?: $obsTs;
                        Database::execute('INSERT INTO macro_data (metric, source, observed_at, value, metadata, available_at, ingested_at, temporal_quality, availability_source) VALUES (?, "fred", ?, ?, ?, NULL, UTC_TIMESTAMP(), "INGESTION_ONLY", "LOCAL_INGESTION") ON DUPLICATE KEY UPDATE value=VALUES(value), metadata=VALUES(metadata), ingested_at=UTC_TIMESTAMP(), temporal_quality="INGESTION_ONLY", availability_source="LOCAL_INGESTION"', [$metric, $obsTs, $obs['value'], json_encode($obs)]);
                        $inserted++;
                        $count++;
                    }
                }
                Logger::info('macro_series_collected', ['series' => $fred, 'metric' => $metric, 'observations' => $inserted]);
            } catch (\Throwable $e) {
                // Falha em uma série não aborta as demais.
                Logger::warning('macro_series_failed', ['series' => $fred, 'message' => $e->getMessage()]);
            }
        }
        if ($count > 0) {
            SourceHealth::success('fred', $lastTs, $t0);
        } else {
            SourceHealth::failure('fred', 'no valid observations (check API key/limits)');
        }
        return $count;
    }
}
