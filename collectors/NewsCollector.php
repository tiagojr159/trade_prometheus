<?php
declare(strict_types=1);

namespace Prometheus\collectors;

use Prometheus\core\Database;
use Prometheus\core\HttpClient;
use Prometheus\core\Logger;
use Prometheus\core\SourceHealth;
use Prometheus\intelligence\OpenAIService;

final class NewsCollector
{
    private HttpClient $http;
    private OpenAIService $ai;

    public function __construct(?HttpClient $http = null, ?OpenAIService $ai = null)
    {
        $this->http = $http ?: new HttpClient();
        $this->ai = $ai ?: new OpenAIService();
    }

    public function collect(string $horizon = '1h'): int
    {
        $keys = require PROMETHEUS_ROOT . '/config/api_keys.php';
        $items = [];

        if (empty($keys['news_api_key'])) {
            // Fonte configurada indisponível: registrar claramente, NÃO inventar dados.
            SourceHealth::unavailable('news_api', 'NEWS_API_KEY not configured');
            Logger::warning('news_source_unavailable', ['reason' => 'NEWS_API_KEY not configured']);
        } else {
            $url = 'https://newsapi.org/v2/everything?q=bitcoin%20OR%20BTC%20OR%20crypto%20OR%20Federal%20Reserve&language=en&sortBy=publishedAt&pageSize=20&apiKey=' . urlencode($keys['news_api_key']);
            $t0 = SourceHealth::begin('news_api');
            try {
                $payload = $this->http->getJson($url, [], (int)prometheus_config('collector.news_cache_ttl', 1800));
                $items = $payload['articles'] ?? [];
                if (($payload['status'] ?? '') !== 'ok') {
                    SourceHealth::failure('news_api', 'api status: ' . ($payload['code'] ?? 'unknown'));
                    Logger::warning('news_source_error', ['code' => $payload['code'] ?? 'unknown', 'message' => $payload['message'] ?? '']);
                }
            } catch (\Throwable $e) {
                SourceHealth::failure('news_api', $e->getMessage());
                Logger::error('news_fetch_failed', ['message' => $e->getMessage()]);
            }
            if ($items) {
                $newest = null;
                foreach ($items as $it) {
                    if (!empty($it['publishedAt'])) {
                        $ts = strtotime($it['publishedAt']);
                        if ($ts && ($newest === null || $ts > $newest)) { $newest = $ts; }
                    }
                }
                SourceHealth::success('news_api', $newest ? date('Y-m-d H:i:s', $newest) : null, $t0);
            }
        }

        $count = 0;
        $llmStatuses = [];
        foreach ($items as $item) {
            $title = (string)($item['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $hash = hash('sha256', $title . ($item['url'] ?? ''));
            $analysis = $this->ai->analyzeNews($title, (string)($item['description'] ?? ''), $horizon);
            $llmStatuses[$analysis['status']] = ($llmStatuses[$analysis['status']] ?? 0) + 1;

            Database::execute(
                'INSERT INTO news (source, title, url, published_at, content_hash, sentiment, relevance, impact, direction, summary, raw_json, available_at, ingested_at, temporal_quality, availability_source, analysis_created_at, analysis_model, analysis_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), "INGESTION_ONLY", ?, UTC_TIMESTAMP(), ?, ?)
                 ON DUPLICATE KEY UPDATE sentiment=VALUES(sentiment), relevance=VALUES(relevance), impact=VALUES(impact), direction=VALUES(direction), summary=VALUES(summary), available_at=VALUES(available_at), ingested_at=UTC_TIMESTAMP(), temporal_quality="INGESTION_ONLY", availability_source=VALUES(availability_source), analysis_created_at=UTC_TIMESTAMP(), analysis_model=VALUES(analysis_model), analysis_status=VALUES(analysis_status)',
                [
                    $item['source']['name'] ?? 'unknown',
                    mb_substr($title, 0, 500),
                    $item['url'] ?? null,
                    isset($item['publishedAt']) && strtotime($item['publishedAt']) !== false ? date('Y-m-d H:i:s', strtotime($item['publishedAt'])) : null,
                    $hash,
                    $analysis['sentiment'],
                    $analysis['relevance'],
                    $analysis['impact'],
                    $analysis['direction'],
                    $analysis['summary'],
                    json_encode(['article' => $item, 'llm_status' => $analysis['status'], 'llm_horizon' => $horizon], JSON_UNESCAPED_UNICODE),
                    isset($item['publishedAt']) && strtotime($item['publishedAt']) !== false ? gmdate('Y-m-d H:i:s', strtotime($item['publishedAt'])) : null,
                    isset($item['publishedAt']) ? 'PUBLISHED_AT_PROXY' : 'UNKNOWN',
                    $analysis['model'] ?? null,
                    (string)$analysis['status'],
                ]
            );
            $count++;
        }

        if ($llmStatuses) {
            Logger::info('news_llm_status_summary', ['statuses' => $llmStatuses, 'items' => $count]);
        }
        return $count;
    }
}
