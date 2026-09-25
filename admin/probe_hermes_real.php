<?php
declare(strict_types=1);
// Probe Item 5 — fluxo REAL NewsAPI → OpenAI → JSON estruturado → features.
// O LLM NÃO decide a previsão final: só produz sentiment/relevance/impact/direction.
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\intelligence\OpenAIService;
use Prometheus\core\Database;

$news = Database::fetchAll(
    'SELECT title, summary AS description, published_at FROM news WHERE sentiment IS NULL ORDER BY published_at DESC LIMIT 3'
);
if (!$news) {
    $news =    Database::fetchAll('SELECT title, summary AS description, published_at FROM news ORDER BY published_at DESC LIMIT 3');
}
if (!$news) {
    echo "NO_NEWS_IN_DB\n";
    exit(1);
}

$svc = new OpenAIService();
foreach ($news as $i => $n) {
    $r = $svc->analyzeNews((string)$n['title'], (string)($n['description'] ?? ''), '1h');
    echo "--- noticia " . ($i + 1) . " ---\n";
    echo "title: " . substr((string)$n['title'], 0, 70) . "\n";
    echo "status: " . $r['status'] . (empty($r['cached']) ? '' : ' (cached)') . "\n";
    if ($r['status'] === 'ok') {
        echo "  sentiment=" . var_export($r['sentiment'], true)
            . " relevance=" . var_export($r['relevance'], true)
            . " impact=" . var_export($r['impact'], true)
            . " direction=" . var_export($r['direction'], true)
            . " horizon=" . ($r['horizon'] ?? '-') . "\n";
        echo "  summary: " . substr((string)($r['summary'] ?? ''), 0, 120) . "\n";
    } else {
        echo "  error: " . ($r['error'] ?? '-') . "\n";
    }
}

// Persistir sentiment das analisadas com sucesso (fluxo real completo).
$rows = Database::fetchAll('SELECT id, title, summary AS description FROM news WHERE sentiment IS NULL ORDER BY published_at DESC LIMIT 3');
foreach ($rows as $n) {
    $r = $svc->analyzeNews((string)$n['title'], (string)($n['description'] ?? ''), '1h');
    if ($r['status'] === 'ok') {
        Database::execute(
            'UPDATE news SET sentiment=?, relevance=?, impact=?, direction=? WHERE id=?',
            [$r['sentiment'], $r['relevance'], $r['impact'], $r['direction'], $n['id']]
        );
        echo "persisted news id={$n['id']}\n";
    }
}
