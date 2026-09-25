<?php
declare(strict_types=1);

namespace Prometheus\modules;

use Prometheus\core\AsOfTime;
use Prometheus\core\Database;
use Prometheus\core\Signal;

/**
 * HERMES ? m?dulo de sentimento de not?cias (Item 8).
 *
 * Agrega features j? extra?das pelo OpenAIService (a partir da tabela `news`).
 * O LLM N?O decide a previs?o final: apenas fornece sentiment/relevance/impact/
 * direction por not?cia; HERMES agrega com pesos por relev?ncia ? impacto.
 *
 * O Signal sempre carrega metadata com o status real do LLM/fonte ?
 * nunca um neutro silencioso por erro.
 */
final class HermesNews
{
    public function signal(string $horizon = '1h'): Signal
    {
        // As-of-time: em backtest, janela [T-48h, T] (sem look-ahead de not?cias futuras).
        $rows = Database::fetchAll(
            'SELECT sentiment, relevance, impact, direction, published_at, summary
             FROM news
             WHERE ' . AsOfTime::sqlLowerBoundRelative('published_at', '48 HOUR') . '
               AND ' . AsOfTime::sqlUpperBound('published_at') . '
               AND ingested_at IS NOT NULL AND ' . AsOfTime::sqlUtcUpperBound('ingested_at') . '
               AND temporal_quality IN ("EXACT","INGESTION_ONLY")
               AND (available_at IS NULL OR (' . AsOfTime::sqlUtcUpperBound('available_at') . ' AND ingested_at >= available_at))
               AND analysis_created_at IS NOT NULL AND ' . AsOfTime::sqlUtcUpperBound('analysis_created_at') . '
               AND sentiment IS NOT NULL
             ORDER BY published_at DESC
             LIMIT 50'
        );

        $llmStats = $this->recentLlmStatus();

        if (!$rows) {
            $llmFailed = ($llmStats['invalid_json'] ?? 0) > 0 || ($llmStats['http_error'] ?? 0) > 0 || ($llmStats['rate_limited'] ?? 0) > 0;
            $llmUnavailable = ($llmStats['no_api_key'] ?? 0) > 0 || ($llmStats['ok'] ?? 0) === 0;
            $reason = $llmFailed ? 'llm_analysis_failed' : ($llmUnavailable ? 'news_available_but_no_llm_output' : 'no_temporally_eligible_news');
            return new Signal('HERMES', 0.0, 0.1, [
                'reason' => $reason,
                'status' => $llmFailed ? 'ERROR' : 'UNAVAILABLE',
                'llm_status_24h' => $llmStats,
                'horizon' => $horizon,
            ]);
        }

        $sum = 0.0;
        $weight = 0.0;
        $freshnessPenalty = 0.0;
        $oldest = null;

        $nowEffective = strtotime(AsOfTime::getEffectiveNow());
        foreach ($rows as $r) {
            $ageHours = $r['published_at'] !== null
                ? max(0.0, ($nowEffective - strtotime((string)$r['published_at'])) / 3600.0)
                : 48.0;
            $decay = exp(-$ageHours / 24.0); // decaimento exponencial de frescor
            $w = max(0.05, (float)$r['relevance'] * (0.5 + (float)$r['impact'])) * $decay;
            $sum += ((float)$r['direction'] * 0.7 + (float)$r['sentiment'] * 0.3) * $w;
            $weight += $w;
            if ($oldest === null || $ageHours < $oldest) {
                $oldest = $ageHours;
            }
        }

        $value = $weight > 0 ? $sum / $weight : 0.0;
        // Confian?a: volume de not?cias ponderado e status do LLM.
        $confidence = min(0.9, $weight / 10.0);
        if (($llmStats['no_api_key'] ?? 0) > 0 && ($llmStats['ok'] ?? 0) === 0) {
            $confidence = min($confidence, 0.2); // sinais v?m de LLM n?o configurado ? baixa confian?a
        }

        return new Signal('HERMES', $value, $confidence, [
            'status' => 'AVAILABLE',
            'items' => count($rows),
            'news_weight' => round($weight, 4),
            'newest_item_hours' => $oldest !== null ? round($oldest, 2) : null,
            'llm_status_24h' => $llmStats,
            'horizon' => $horizon,
        ]);
    }

    /** Distribui??o de status do LLM nas ?ltimas 24h (uso/custo audit?vel). */
    private function recentLlmStatus(): array
    {
        try {
            $rows = Database::fetchAll(
                'SELECT status, COUNT(*) AS c FROM llm_usage WHERE purpose = "news_analysis" AND ' . AsOfTime::sqlLowerBoundRelative('created_at', '24 HOUR') . ' AND ' . AsOfTime::sqlUpperBound('created_at') . ' GROUP BY status'
            );
        } catch (\Throwable $e) {
            return ['unknown' => 1]; // tabela pode n?o existir em instala??es antigas
        }
        $out = ['ok' => 0, 'no_api_key' => 0, 'http_error' => 0, 'invalid_json' => 0, 'rate_limited' => 0];
        foreach ($rows as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
        }
        return $out;
    }
}
