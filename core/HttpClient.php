<?php
declare(strict_types=1);

namespace Prometheus\core;

/**
 * ETAPA 06 — Cliente HTTP resiliente para collectors.
 *
 * - timeout configurável (collector.api_timeout);
 * - retry com exponential backoff + jitter (máx. collector.api_retries, default 2);
 * - 429: respeita Retry-After (até 60s) e conta como tentativa;
 * - 5xx: retentável; 4xx (exceto 429): falha imediata;
 * - cache opcional por TTL (Cache);
 * - NUNCA devolve dado fictício: falha lança RuntimeException.
 */
final class HttpClient
{
    public function getJson(string $url, array $headers = [], ?int $ttl = null): array
    {
        $cacheKey = 'GET:' . $url . ':' . json_encode($headers);
        if ($ttl !== null) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $data = $this->requestJsonWithRetry('GET', $url, null, $headers);
        if ($ttl !== null) {
            Cache::set($cacheKey, $data, $ttl);
        }
        return $data;
    }

    public function postJson(string $url, array $body, array $headers = [], ?int $ttl = null): array
    {
        $cacheKey = 'POST:' . $url . ':' . json_encode($body) . ':' . json_encode($headers);
        if ($ttl !== null) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $data = $this->requestJsonWithRetry('POST', $url, $body, $headers);
        if ($ttl !== null) {
            Cache::set($cacheKey, $data, $ttl);
        }
        return $data;
    }

    private function requestJsonWithRetry(string $method, string $url, ?array $body, array $headers): array
    {
        $maxRetries = (int)prometheus_config('collector.api_retries', 2);
        $attempt = 0;
        lastRetry:
        try {
            return $this->requestJson($method, $url, $body, $headers);
        } catch (RetryableHttpException $e) {
            if ($attempt >= $maxRetries) {
                throw new \RuntimeException('HTTP request failed after ' . ($attempt + 1) . ' attempts: ' . $url . ' — ' . $e->getMessage(), 0, $e);
            }
            $attempt++;
            // Exponential backoff + jitter: 0.5s, 1s, 2s...
            $delay = 0.5 * (2 ** ($attempt - 1)) + (mt_rand() / mt_getrandmax()) * 0.3;
            if ($e->retryAfter > 0) {
                $delay = min(60.0, max($delay, (float)$e->retryAfter));
            }
            usleep((int)round($delay * 1e6));
            goto lastRetry;
        }
    }

    private function requestJson(string $method, string $url, ?array $body, array $headers): array
    {
        $ch = curl_init($url);
        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)prometheus_config('collector.api_timeout', 15),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_USERAGENT => 'PrometheusBTC/1.0',
        ]);
        if ($method === 'POST') {
            // CORREÇÃO: Content-Type precisa estar no array ANTES de
            // CURLOPT_HTTPHEADER — a reatribuição posterior não era enviada
            // (POST sem Content-Type: application/json → APIs rejeitam com 400).
            // Se o caller já passou Content-Type no mapa, não duplica
            // (header duplicado → 400 em algumas APIs).
            $hasContentType = false;
            foreach ($headerLines as $line) {
                if (stripos($line, 'content-type:') === 0) {
                    $hasContentType = true;
                    break;
                }
            }
            if (!$hasContentType) {
                $headerLines[] = 'Content-Type: application/json';
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $retryAfterHeader = defined('CURLINFO_RETRY_AFTER')
            ? @curl_getinfo($ch, CURLINFO_RETRY_AFTER)
            : false; // build cURL do PHP 7.4 sem suporte a Retry-After
        curl_close($ch);

        if ($raw === false) {
            // erro de rede/timeout: retentável
            throw new RetryableHttpException('network error: ' . $err, 0);
        }
        if ($status === 429) {
            $retryAfter = (is_numeric($retryAfterHeader) && $retryAfterHeader > 0) ? min(60, (int)$retryAfterHeader) : 5;
            throw new RetryableHttpException('HTTP 429 rate limited', $retryAfter);
        }
        if ($status >= 500) {
            throw new RetryableHttpException('HTTP ' . $status . ' server error', 0);
        }
        if ($status >= 400) {
            throw new \RuntimeException('HTTP request failed: ' . $url . ' status=' . $status);
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response from ' . $url);
        }
        return $decoded;
    }
}

/** Exceção interna de falha retentável (rede, 429, 5xx). */
final class RetryableHttpException extends \RuntimeException
{
    /** @var int Sugestão de Retry-After em segundos (0 = usar backoff). */
    public $retryAfter;

    public function __construct(string $message, int $retryAfter = 0)
    {
        parent::__construct($message);
        $this->retryAfter = $retryAfter;
    }
}
