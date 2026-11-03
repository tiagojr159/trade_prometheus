<?php
declare(strict_types=1);

namespace Prometheus\collectors;

use Prometheus\core\Database;
use Prometheus\core\HttpClient;
use Prometheus\core\Logger;
use Prometheus\core\SourceHealth;

final class MarketCollector
{
    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?: new HttpClient();
    }

    public function collect(string $symbol = 'BTCUSDT', string $interval = '1m', int $limit = 500): int
    {
        $t0 = SourceHealth::begin('binance_spot');
        try {
            $count = $this->collectInner($symbol, $interval, $limit);
            $last = Database::fetch(
                'SELECT open_time FROM market_data WHERE symbol = ? AND interval_name = ? ORDER BY open_time DESC LIMIT 1',
                [$symbol, $interval]
            );
            // Saúde da fonte usa o timestamp REAL da última candle, não o momento da coleta.
            SourceHealth::success('binance_spot', $last['open_time'] ?? null, $t0);
            return $count;
        } catch (\Throwable $e) {
            SourceHealth::failure('binance_spot', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Coleta TODOS os timeframes configurados. Falha em um timeframe
     * não impede os demais (isolamento por intervalo, logs explícitos).
     */
    public function collectAllTimeframes(string $symbol = 'BTCUSDT'): array
    {
        $intervals = prometheus_config('collector.market_intervals', ['1m']);
        $limits = prometheus_config('collector.market_limits_by_interval', []);
        $out = [];
        foreach ($intervals as $interval) {
            $limit = (int)($limits[$interval] ?? 500);
            try {
                $out[$interval] = $this->collect($symbol, $interval, $limit);
            } catch (\Throwable $e) {
                // Falha isolada: registra e continua nos outros intervalos.
                Logger::warning('market_interval_collection_failed', ['interval' => $interval, 'message' => $e->getMessage()]);
                $out[$interval] = -1;
            }
        }
        return $out;
    }

    private function collectInner(string $symbol, string $interval, int $limit): int
    {
        $url = 'https://api.binance.com/api/v3/klines?symbol=' . urlencode($symbol) . '&interval=' . urlencode($interval) . '&limit=' . $limit;
        $rows = $this->http->getJson($url, [], 30);
        $count = 0;
        foreach ($rows as $r) {
            if ($this->saveClosedCandle($symbol,$interval,$r)) $count++;
        }
        Logger::info('market_collected', ['symbol' => $symbol, 'interval' => $interval, 'count' => $count]);

        // Séries externas para MarketRelations (Item 12): coleta best-effort —
        // falha de um par não quebra a coleta principal.
        foreach (['ETHUSDT', 'PAXGUSDT'] as $pair) {
            try {
                $pairRows = $this->http->getJson('https://api.binance.com/api/v3/klines?symbol=' . urlencode($pair) . '&interval=' . urlencode($interval) . '&limit=' . min($limit, 240), [], 30);
                $pairCount = 0;
                foreach ($pairRows as $r) {
                    if ($this->saveClosedCandle($pair,$interval,$r)) $pairCount++;
                }
                Logger::info('market_collected_pair', ['symbol' => $pair, 'count' => $pairCount]);
            } catch (\Throwable $e) {
                Logger::warning('market_pair_unavailable', ['symbol' => $pair, 'message' => $e->getMessage()]);
            }
        }

        return $count;
    }

    /** Closed observations are immutable; any later exchange correction is retained separately. */
    private function saveClosedCandle(string $symbol,string $interval,array $r): bool
    {
        $closeUnix=(int)($r[6]/1000);
        if ($closeUnix>time()) return false;
        $open=date('Y-m-d H:i:s',(int)($r[0]/1000));
        $close=date('Y-m-d H:i:s',$closeUnix);
        $available=gmdate('Y-m-d H:i:s',$closeUnix);
        Database::execute('INSERT INTO market_data (symbol,source,interval_name,open_time,close_time,open_price,high_price,low_price,close_price,volume,available_at,ingested_at,temporal_quality,availability_source,last_seen_at)
            VALUES (?,"binance",?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),"INGESTION_ONLY","BINANCE_CLOSE_TIME_LOWER_BOUND",UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE last_seen_at=UTC_TIMESTAMP()',
            [$symbol,$interval,$open,$close,$r[1],$r[2],$r[3],$r[4],$r[5],$available]);
        $existing=Database::fetch('SELECT id,close_time,open_price,high_price,low_price,close_price,volume FROM market_data WHERE symbol=? AND source="binance" AND interval_name=? AND open_time=?',[$symbol,$interval,$open]);
        if ($existing && ($existing['close_time']!==$close || (float)$existing['open_price']!=(float)$r[1] || (float)$existing['high_price']!=(float)$r[2] || (float)$existing['low_price']!=(float)$r[3] || (float)$existing['close_price']!=(float)$r[4] || (float)$existing['volume']!=(float)$r[5])) {
            $observation=['open_time'=>$open,'close_time'=>$close,'open_price'=>$r[1],'high_price'=>$r[2],'low_price'=>$r[3],'close_price'=>$r[4],'volume'=>$r[5]];
            $json=json_encode($observation,JSON_THROW_ON_ERROR);
            Database::execute('INSERT IGNORE INTO market_data_revisions (market_data_id,content_hash,observation_json,observed_at) VALUES (?,?,?,UTC_TIMESTAMP())',[$existing['id'],hash('sha256',$json),$json]);
        }
        return true;
    }

    public function latestPrice(string $symbol = 'BTCUSDT'): ?float
    {
        // Com múltiplos timeframes no banco, SEMPRE filtra o intervalo base —
        // sem o filtro o preço mais recente poderia vir de um candle 4h/1d.
        $interval = prometheus_config('collector.market_interval', '1m');
        $row = Database::fetch(
            'SELECT close_price FROM market_data WHERE symbol=? AND interval_name=? ORDER BY open_time DESC LIMIT 1',
            [$symbol, $interval]
        );
        return $row ? (float)$row['close_price'] : null;
    }
}
