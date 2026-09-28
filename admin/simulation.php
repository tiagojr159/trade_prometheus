<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

use Prometheus\admin\AdminGuard;
use Prometheus\core\Database;

AdminGuard::requireAdmin();
$symbol = prometheus_config('default_symbol', 'BTCUSDT');
$account = null;
$orders = [];
$priceRow = null;
$setupError = false;
try {
    $account = Database::fetch('SELECT * FROM paper_trading_accounts WHERE id=1');
    $priceRow = Database::fetch('SELECT close_price, open_time FROM market_data WHERE symbol=? ORDER BY open_time DESC LIMIT 1', [$symbol]);
    $orders = Database::fetchAll('SELECT * FROM paper_trading_orders ORDER BY id DESC LIMIT 50');
} catch (Throwable $e) {
    $setupError = true;
}
$price = (float)($priceRow['close_price'] ?? 0);
$cash = (float)($account['cash_balance'] ?? 100);
$btc = (float)($account['btc_balance'] ?? 0);
$equity = $cash + $btc * $price;
$initial = (float)($account['initial_cash'] ?? 100);
$profit = $equity - $initial;
$position = $btc > 0.000000000001 ? 'Comprado' : 'Em dinheiro';
function money(float $value): string { return '$' . number_format($value, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Simulação de operações - PROMETHEUS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="../assets/css/prometheus.css" rel="stylesheet"></head>
<body>
<nav class="navbar navbar-expand-lg border-bottom"><div class="container-fluid px-4">
  <a class="navbar-brand fw-bold" href="../index.php">PROMETHEUS</a>
  <div class="navbar-nav"><a class="nav-link" href="dashboard.php">Previsões</a><a class="nav-link active" href="simulation.php">Simulação</a><a class="nav-link" href="modules.php">Módulos</a><a class="nav-link" href="performance.php">Desempenho</a><a class="nav-link" href="settings.php">Configurações</a></div>
  <span class="navbar-text ms-auto">Simulação automática · <?= htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') ?></span>
</div></nav>
<main class="container-fluid p-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><h1 class="h3 mb-1">Simulação de operações</h1><p class="text-muted mb-0">Ordens simuladas com base nas previsões de 15 minutos. Nenhum dinheiro real é movimentado.</p></div><span class="badge bg-primary">Saldo inicial: <?= money($initial) ?></span></div>
  <?php if ($setupError || !$account): ?><div class="alert alert-warning">A simulação ainda não está instalada no banco. Importe <code>sql/migrations/010_paper_trading.sql</code> uma vez para criar a conta e o histórico.</div><?php endif; ?>
  <section class="row g-3 mb-3">
    <div class="col-12 col-md-6 col-xl-3"><div class="metric"><span>Patrimônio estimado</span><strong><?= money($equity) ?></strong><small>Dinheiro + bitcoins ao preço atual</small></div></div>
    <div class="col-12 col-md-6 col-xl-3"><div class="metric"><span>Resultado estimado</span><strong class="<?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= $profit >= 0 ? '+' : '' ?><?= money($profit) ?></strong><small>Em relação aos US$ 100 iniciais</small></div></div>
    <div class="col-12 col-md-6 col-xl-3"><div class="metric"><span>Posição</span><strong><?= $position ?></strong><small><?= number_format($btc, 8, ',', '.') ?> BTC · dinheiro disponível <?= money($cash) ?></small></div></div>
    <div class="col-12 col-md-6 col-xl-3"><div class="metric"><span>Preço atual do BTC</span><strong><?= $price > 0 ? money($price) : 'Indisponível' ?></strong><small><?= htmlspecialchars((string)($priceRow['open_time'] ?? 'Aguardando cotação'), ENT_QUOTES, 'UTF-8') ?></small></div></div>
  </section>
  <section class="row g-3"><div class="col-12 col-xl-8"><div class="panel"><h2>Histórico de operações</h2><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Data e hora</th><th>Operação</th><th>Quantidade</th><th>Preço</th><th>Valor</th><th>Chance de alta</th><th>Saldo após</th></tr></thead><tbody>
  <?php if (!$orders): ?><tr><td colspan="7" class="text-muted">Nenhuma operação simulada ainda. O cron executará a estratégia quando houver uma previsão elegível.</td></tr><?php endif; ?>
  <?php foreach ($orders as $order): ?><tr><td><?= htmlspecialchars((string)$order['created_at'], ENT_QUOTES, 'UTF-8') ?></td><td><span class="badge <?= $order['side'] === 'BUY' ? 'bg-success' : 'bg-danger' ?>"><?= $order['side'] === 'BUY' ? 'COMPRA' : 'VENDA' ?></span></td><td><?= number_format((float)$order['quantity_btc'], 8, ',', '.') ?> BTC</td><td><?= money((float)$order['price']) ?></td><td><?= money((float)$order['gross_usd']) ?></td><td><?= number_format((float)$order['probability_up'] * 100, 1, ',', '.') ?>%</td><td><?= money((float)$order['cash_after']) ?> + <?= number_format((float)$order['btc_after'], 8, ',', '.') ?> BTC</td></tr><?php endforeach; ?>
  </tbody></table></div></div></div>
  <div class="col-12 col-xl-4"><div class="panel"><h2>Como a estratégia decide</h2><p>Compra quando a previsão de 15 minutos indica alta, com chance de alta de pelo menos 58% e confiança mínima de 20%.</p><p>Vende os bitcoins quando a previsão indica baixa com chance mínima de 58%.</p><p>Há intervalo mínimo de 15 minutos entre operações. Se o sinal não atingir os critérios, a simulação aguarda.</p><small class="text-muted">A frequência depende das previsões: não há garantia de três operações por hora nem de lucro. Taxas e derrapagem de preço não estão incluídas.</small></div></div></section>
</main></body></html>
