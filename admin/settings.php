<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
use Prometheus\admin\AdminGuard;
use Prometheus\core\Database;

AdminGuard::requireAdmin();

$keys = require dirname(__DIR__) . '/config/api_keys.php';
$dbConfigured = true;
try {
    Database::connection();
} catch (Throwable $e) {
    $dbConfigured = false;
}

$llmStats = Database::fetchAll('SELECT status, COUNT(*) AS c FROM llm_usage WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY status');
$coinMetrics = Database::fetch('SELECT MAX(observed_at) AS t, COUNT(*) AS n FROM onchain_data WHERE source = "coin_metrics_community"');
$coinMetricsOnline = $coinMetrics && (int)($coinMetrics['n'] ?? 0) > 0 && $coinMetrics['t'] !== null
    && (time() - strtotime((string)$coinMetrics['t'])) <= 3 * 86400;
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Config - PROMETHEUS</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="../assets/css/prometheus.css" rel="stylesheet"></head><body><main class="container p-4"><h1>Configuracao</h1>
<p>Chaves sao fornecidas via variaveis de ambiente (<code>OPENAI_API_KEY</code>, <code>NEWS_API_KEY</code>, <code>FRED_API_KEY</code>) e <strong>nunca sao exibidas nesta pagina</strong>. Coin Metrics Community nao exige chave.</p>
<div class="panel">
<h2>Status dos componentes</h2>
<ul class="list-group list-group-flush">
  <li class="list-group-item d-flex justify-content-between">Banco de dados <b><?= $dbConfigured ? 'conectado' : 'indisponivel' ?></b></li>
  <li class="list-group-item d-flex justify-content-between">OpenAI (HERMES) <b><?= !empty($keys['openai_api_key']) ? 'configurada' : 'nao configurada' ?></b></li>
  <li class="list-group-item d-flex justify-content-between">NewsAPI (HERMES) <b><?= !empty($keys['news_api_key']) ? 'configurada' : 'nao configurada' ?></b></li>
  <li class="list-group-item d-flex justify-content-between">FRED (CRONOS) <b><?= !empty($keys['fred_api_key']) ? 'configurada' : 'nao configurada' ?></b></li>
  <li class="list-group-item d-flex justify-content-between">Coin Metrics Community (POSEIDON) <b><?= $coinMetricsOnline ? 'online' : 'offline' ?></b></li>
</ul>
</div>
<div class="panel">
<h2>Uso de LLM (24h)</h2>
<?php if (!$llmStats): ?>
  <p class="text-muted">Nenhuma chamada registrada nas ultimas 24h.</p>
<?php else: ?>
  <ul class="list-group list-group-flush">
  <?php foreach ($llmStats as $s): ?>
    <li class="list-group-item d-flex justify-content-between"><?= htmlspecialchars((string)$s['status']) ?> <b><?= (int)$s['c'] ?></b></li>
  <?php endforeach; ?>
  </ul>
<?php endif; ?>
</div>
</main></body></html>
