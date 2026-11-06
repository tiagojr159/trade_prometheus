<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
use Prometheus\admin\AdminGuard;
use Prometheus\core\Database;

AdminGuard::requireAdmin();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$csrf = (string)($_SESSION['settings_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['settings_csrf'] = $csrf;
}
$resetMessage = null;
$resetError = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['reset_paper_data'])) {
    if (!isset($_POST['csrf']) || !hash_equals($csrf, (string)$_POST['csrf'])) {
        $resetError = 'A sessão expirou. Atualize a página e tente novamente.';
    } elseif ((string)($_POST['confirm_text'] ?? '') !== 'ZERAR PAPER TRADING') {
        $resetError = 'Digite ZERAR PAPER TRADING para confirmar a operação.';
    } else {
        $db = Database::connection();
        try {
            $db->beginTransaction();
            $existingTables = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')
                ->fetchAll(PDO::FETCH_COLUMN);
            $existingTables = array_fill_keys($existingTables, true);

            // Delete children before their parent decisions to respect foreign keys.
            foreach ([
                'paper_trading_v2_orders',
                'paper_trading_v2_trades',
                'paper_trading_decisions',
                'paper_trading_equity',
                'paper_trading_risk_events',
                'paper_trading_orders',
                'system_logs',
                'llm_usage',
            ] as $table) {
                if (isset($existingTables[$table])) {
                    $db->exec('DELETE FROM `' . $table . '`');
                }
            }

            if (isset($existingTables['paper_trading_v2_accounts'])) {
                $db->exec("UPDATE paper_trading_v2_accounts SET
                    cash_balance=initial_balance, position_side='FLAT', quantity_btc=0,
                    entry_price=NULL, entry_market_data_id=NULL, entry_prediction_id=NULL,
                    entry_at=NULL, entry_reason=NULL, entry_fee_usd=0, entry_slippage_usd=0,
                    reserved_cash=0, realized_pnl=0, total_fees=0, total_slippage=0,
                    peak_equity=initial_balance, max_drawdown_pct=0");
            }
            if (isset($existingTables['paper_trading_accounts'])) {
                $db->exec('UPDATE paper_trading_accounts SET cash_balance=initial_cash, btc_balance=0, last_trade_at=NULL');
            }
            if (isset($existingTables['paper_trading_heartbeat'])) {
                $db->exec("UPDATE paper_trading_heartbeat SET status='STOPPED', last_started_at=NULL,
                    last_finished_at=NULL, last_prediction_id=NULL, last_decision=NULL,
                    last_reason=NULL, last_error=NULL, next_evaluation_at=NULL");
            }

            $db->commit();
            $resetMessage = 'Histórico do paper trading e logs apagados. As contas foram restauradas ao saldo inicial; usuários, configurações, previsões e dados de mercado foram preservados.';
            $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
            $csrf = $_SESSION['settings_csrf'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $resetError = 'Não foi possível zerar os dados. Nenhuma alteração foi confirmada.';
        }
    }
}

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
<div class="panel">
<h2>Zerar histórico do paper trading</h2>
<p>Apaga ordens, negociações, decisões, histórico de equity, eventos de risco e logs de sistema e de uso de LLM. As contas permanecem cadastradas e voltam ao saldo inicial (US$ 100 nas contas do torneio), sem posição aberta. Usuários, permissões, configurações, previsões e dados de mercado são preservados.</p>
<?php if ($resetMessage !== null): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($resetMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($resetError !== null): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($resetError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post" class="border rounded p-3" onsubmit="return confirm('Esta ação apagará o histórico de operações e os logs. As contas serão restauradas ao saldo inicial. Continuar?');">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="reset_paper_data" value="1">
  <label for="confirm_text" class="form-label">Digite <code>ZERAR PAPER TRADING</code> para confirmar</label>
  <div class="d-flex gap-2 flex-wrap">
    <input id="confirm_text" name="confirm_text" class="form-control" style="max-width: 24rem" autocomplete="off" required>
    <button type="submit" class="btn btn-danger">Zerar histórico e logs</button>
  </div>
</form>
</div>
</main></body></html>
