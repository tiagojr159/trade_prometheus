<?php
declare(strict_types=1);

namespace Prometheus\intelligence;

use Prometheus\core\Cache;
use Prometheus\core\Database;
use Prometheus\core\HttpClient;
use Prometheus\core\Logger;
use Prometheus\core\SourceHealth;

/**
 * Integração OpenAI robusta (Item 8).
 *
 * Garantias:
 * - Nunca retorna fallback neutro silenciosamente: todo resultado carrega
 *   'status' explícito (ok | invalid_json | http_error | rate_limited | no_api_key)
 *   e 'error' descritivo quando aplicável.
 * - Resposta sempre validada (JSON estruturado, campos numéricos limitados).
 * - Cache por hash do conteúdo (evita chamar a API 2x para a mesma notícia).
 * - Rate limit por janela deslizante (config: llm.max_calls_per_hour).
 * - Registro de uso/custo em llm_usage (tokens + custo estimado quando conhecido).
 * - Horizonte explicitado no prompt e no metadata.
 * - LLM NUNCA decide a previsão final: retorna apenas features
 *   (sentiment/relevance/impact/direction) que o módulo HERMES agrega.
 */
final class OpenAIService
{
    /** Modelos com preço conhecido por 1M tokens (input, output) em USD. */
    private const MODEL_PRICES = [
        'gpt-4.1-mini'    => [0.40, 1.60],
        'gpt-4.1-nano'    => [0.10, 0.40],
        'gpt-4o-mini'     => [0.15, 0.60],
        'gpt-4.1'         => [2.00, 8.00],
        'gpt-4o'          => [2.50, 10.00],
    ];

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?: new HttpClient();
    }

    /**
     * Analisa uma notícia e retorna sempre o contrato completo:
     * status, sentiment, relevance, impact, direction, horizon, summary, metadata.
     */
    public function analyzeNews(string $title, string $description, string $horizon = '1h'): array
    {
        $purpose = 'news_analysis';
        $keys = require PROMETHEUS_ROOT . '/config/api_keys.php';

        if (empty($keys['openai_api_key'])) {
            // Explicito e auditável — NÃO é um neutro silencioso.
            SourceHealth::unavailable('openai', 'OPENAI_API_KEY not configured');
            $this->logUsage($purpose, $keys['openai_model'] ?? 'unknown', 0, 0, 0, 0.0, 'no_api_key', null);
            return $this->result('no_api_key', $horizon, null, ['reason' => 'OPENAI_API_KEY not configured']);
        }

        $contentHash = hash('sha256', $title . '|' . $description . '|' . $horizon);

        // 1. Cache por hash de conteúdo (idempotente).
        $cached = Cache::get('llm:news:' . $contentHash);
        if (is_array($cached)) {
            $this->logUsage($purpose, $keys['openai_model'], 0, 0, 0, 0.0, 'ok', $contentHash, true);
            return $cached + ['cached' => true];
        }

        // 2. Rate limit por janela deslizante (1 hora).
        if (!$this->acquireSlot($purpose)) {
            $this->logUsage($purpose, $keys['openai_model'], 0, 0, 0, 0.0, 'rate_limited', $contentHash);
            return $this->result('rate_limited', $horizon, null, ['reason' => 'hourly call limit reached']);
        }

        $system = 'You are a feature extractor for a BTC market prediction system. '
            . 'Analyze the news and return STRICT JSON only (no markdown, no prose) with keys: '
            . 'sentiment (number, -1 to 1), relevance (number, 0 to 1: how much this news affects BTC), '
            . 'impact (number, 0 to 1: expected magnitude of the move), '
            . 'direction (number, -1 to 1: expected short-term direction of BTC), '
            . 'summary (string, max 200 chars). '
            . 'You do NOT make final predictions; you only describe the news features. '
            . "The analysis horizon is {$horizon}.";

        $user = "Title: {$title}\nDescription: {$description}\nNews horizon context: {$horizon}";

        $body = [
            'model' => $keys['openai_model'],
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        try {
            $res = $this->http->postJson(
                'https://api.openai.com/v1/chat/completions',
                $body,
                ['Authorization' => 'Bearer ' . $keys['openai_api_key'], 'Content-Type' => 'application/json'],
                (int)prometheus_config('llm.response_cache_ttl', 86400)
            );
        } catch (\Throwable $e) {
            SourceHealth::failure('openai', $e->getMessage());
            Logger::error('openai_http_error', ['purpose' => $purpose, 'message' => $e->getMessage()]);
            $this->logUsage($purpose, $keys['openai_model'] ?? 'unknown', 0, 0, 0, 0.0, 'http_error', $contentHash);
            return $this->result('http_error', $horizon, null, ['error' => $e->getMessage()]);
        }

        $content = $res['choices'][0]['message']['content'] ?? null;
        $promptTokens = (int)($res['usage']['prompt_tokens'] ?? 0);
        $completionTokens = (int)($res['usage']['completion_tokens'] ?? 0);
        $totalTokens = (int)($res['usage']['total_tokens'] ?? ($promptTokens + $completionTokens));
        $cost = $this->estimateCost((string)$keys['openai_model'], $promptTokens, $completionTokens);

        if (!is_string($content) || trim($content) === '') {
            $this->logUsage($purpose, (string)$keys['openai_model'], $promptTokens, $completionTokens, $totalTokens, $cost, 'invalid_json', $contentHash);
            return $this->result('invalid_json', $horizon, null, ['error' => 'empty or missing message content']);
        }

        $json = json_decode($this->extractJson($content), true);
        if (!is_array($json)) {
            Logger::warning('openai_invalid_json', ['purpose' => $purpose, 'content_preview' => mb_substr($content, 0, 200)]);
            $this->logUsage($purpose, (string)$keys['openai_model'], $promptTokens, $completionTokens, $totalTokens, $cost, 'invalid_json', $contentHash);
            return $this->result('invalid_json', $horizon, null, ['error' => 'response is not valid JSON', 'raw_preview' => mb_substr($content, 0, 200)]);
        }

        $validated = $this->validateFeatures($json);
        if ($validated === null) {
            $this->logUsage($purpose, (string)$keys['openai_model'], $promptTokens, $completionTokens, $totalTokens, $cost, 'invalid_json', $contentHash);
            return $this->result('invalid_json', $horizon, null, ['error' => 'required numeric fields missing or out of range']);
        }

        $this->logUsage($purpose, (string)$keys['openai_model'], $promptTokens, $completionTokens, $totalTokens, $cost, 'ok', $contentHash);
        SourceHealth::success('openai', date('Y-m-d H:i:s'), 0);

        $out = $this->result('ok', $horizon, $validated, [
            'tokens' => $totalTokens,
            'cost_usd' => $cost,
            'model' => $keys['openai_model'],
        ]);
        Cache::set('llm:news:' . $contentHash, $out, (int)prometheus_config('llm.response_cache_ttl', 86400));
        return $out;
    }

    /** Extrai o primeiro objeto JSON de uma resposta possivelmente poluída com markdown. */
    private function extractJson(string $content): string
    {
        $trimmed = trim($content);
        // Remove fences ```json ... ``` se existirem.
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $trimmed, $m)) {
            return $m[1];
        }
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($trimmed, $start, $end - $start + 1);
        }
        return $trimmed;
    }

    /** Valida e limita os campos numéricos. Retorna null se campos essenciais faltarem. */
    private function validateFeatures(array $json): ?array
    {
        if (!isset($json['sentiment'], $json['relevance'], $json['impact'], $json['direction'])) {
            return null;
        }
        if (!is_numeric($json['sentiment']) || !is_numeric($json['relevance']) || !is_numeric($json['impact']) || !is_numeric($json['direction'])) {
            return null;
        }
        return [
            'sentiment' => max(-1.0, min(1.0, (float)$json['sentiment'])),
            'relevance' => max(0.0, min(1.0, (float)$json['relevance'])),
            'impact' => max(0.0, min(1.0, (float)$json['impact'])),
            'direction' => max(-1.0, min(1.0, (float)$json['direction'])),
            'summary' => mb_substr((string)($json['summary'] ?? ''), 0, 240),
        ];
    }

    private function result(string $status, string $horizon, ?array $features, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'sentiment' => $features['sentiment'] ?? null,
            'relevance' => $features['relevance'] ?? null,
            'impact' => $features['impact'] ?? null,
            'direction' => $features['direction'] ?? null,
            'summary' => $features['summary'] ?? null,
            'horizon' => $horizon,
        ], $extra);
    }

    private function estimateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        if (!isset(self::MODEL_PRICES[$model])) {
            return 0.0; // preço desconhecido — custo 0 mas tokens registrados.
        }
        [$in, $out] = self::MODEL_PRICES[$model];
        return round(($promptTokens / 1_000_000) * $in + ($completionTokens / 1_000_000) * $out, 8);
    }

    /** Janela deslizante de 1h contra o limite configurado. */
    private function acquireSlot(string $purpose): bool
    {
        $maxCalls = (int)prometheus_config('llm.max_calls_per_hour', 30);
        if ($maxCalls <= 0) {
            return true;
        }
        $row = Database::fetch(
            'SELECT COUNT(*) AS c FROM llm_usage WHERE purpose = ? AND status = "ok" AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            [$purpose]
        );
        return (int)($row['c'] ?? 0) < $maxCalls;
    }

    private function logUsage(string $purpose, string $model, int $pt, int $ct, int $tt, float $cost, string $status, ?string $hash, bool $fromCache = false): void
    {
        try {
            Database::execute(
                'INSERT INTO llm_usage (purpose, model, prompt_tokens, completion_tokens, total_tokens, cost_usd, status, content_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$purpose, $model, $pt, $ct, $tt, $cost, $status, $hash]
            );
            if ($fromCache) {
                Logger::info('llm_cache_hit', ['purpose' => $purpose]);
            }
        } catch (\Throwable $e) {
            Logger::error('llm_usage_log_failed', ['message' => $e->getMessage()]);
        }
    }

    private HttpClient $http;
}
