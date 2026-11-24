<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\admin\AdminGuard;
use Prometheus\core\Database;

AdminGuard::requireAdmin();
$escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$number = static fn($value, int $precision = 2): string => number_format((float)$value, $precision, ',', '.');
$seasons = [];
$accounts = [];
$reasons = [];
$models = [];
$season = null;
$status = null;
try {
    $seasons = Database::fetchAll('SELECT id,season_key,started_at_utc FROM quant_tournament_seasons ORDER BY id DESC LIMIT 100');
    $requested = (string)($_GET['season'] ?? ($seasons[0]['id'] ?? ''));
    foreach ($seasons as $candidate) {
        if ((string)$candidate['id'] === $requested) $season = $candidate;
    }
    if ($season) {
        $accounts = Database::fetchAll('SELECT a.*,COALESCE(e.equity,a.initial_equity) AS equity,
            COALESCE(e.unrealized_pnl,0) AS unrealized_pnl,
            (SELECT reason_code FROM quant_decisions WHERE account_id=a.id ORDER BY id DESC LIMIT 1) AS reason_code,
            COALESCE(f.fees,0) AS fees, COALESCE(f.closed,0) AS closed, COALESCE(f.won,0) AS won
            FROM quant_tournament_accounts a
            LEFT JOIN quant_equity_snapshots e ON e.account_id=a.id AND e.quote_id=a.last_quote_id
            LEFT JOIN (SELECT i.account_id,SUM(x.fee) fees,SUM(x.quantity_delta<0) closed,
                SUM(x.quantity_delta<0 AND x.realized_pnl>0) won
                FROM quant_trade_intents i JOIN quant_spot_fills x ON x.decision_id=i.decision_id
                JOIN quant_tournament_accounts t ON t.id=i.account_id AND t.season_id=?
                GROUP BY i.account_id) f ON f.account_id=a.id
            WHERE a.season_id=? ORDER BY equity DESC,a.strategy_key ASC', [$season['id'], $season['id']]);
        $reasons = Database::fetchAll('SELECT d.reason_code,COUNT(*) n FROM quant_decisions d
            JOIN quant_tournament_accounts a ON a.id=d.account_id
            WHERE a.season_id=? AND d.as_of_utc>=UTC_TIMESTAMP()-INTERVAL 1 DAY
            GROUP BY d.reason_code ORDER BY n DESC LIMIT 20', [$season['id']]);
    }
    $models = Database::fetchAll('SELECT m.model_id,m.horizon,COUNT(p.id) n,
        SUM(l.status=\'TARGET_GAP\') gaps, SUM(l.direction IN (\'UP\',\'DOWN\')) binary_n,
        AVG(CASE WHEN l.direction IN (\'UP\',\'DOWN\') THEN POW(p.probability_up-(l.direction=\'UP\'),2) END) brier,
        AVG(CASE WHEN l.direction IN (\'UP\',\'DOWN\') AND ABS(2*p.probability_up-1)>=1e-12
            THEN (p.probability_up>0.5)=(l.direction=\'UP\') END) accuracy
        FROM quant_model_versions m LEFT JOIN quant_shadow_predictions p ON p.model_id=m.model_id
        LEFT JOIN quant_shadow_labels l ON l.prediction_id=p.id AND l.label_version=1
        GROUP BY m.model_id,m.horizon,m.registered_at_utc ORDER BY m.registered_at_utc DESC LIMIT 20');
} catch (Throwable $error) {
    $status = 'Dados V3 indisponíveis. Verifique as migrações e a conexão.';
}
?><!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Torneio V3 | Prometheus</title>
<style>
body{margin:0;background:#111827;color:#edf2f7;font:15px system-ui,sans-serif}main{max-width:1420px;margin:auto;padding:2rem}
a{color:#93c5fd}h1,h2{margin:.5rem 0 1rem}h2{font-size:1.15rem}.table-wrap{overflow:auto;margin:1rem 0 2rem}
table{border-collapse:collapse;width:100%;text-align:left;white-space:nowrap}th,td{border-bottom:1px solid #334155;padding:.65rem}
th{color:#cbd5e1;background:#1e293b}select,button{font:inherit;padding:.3rem .5rem}
</style></head>
<body><main>
<a href="simulation.php">Voltar ao torneio legado</a>
<h1>Torneio V3</h1>
<?php if ($status): ?><p role="status"><?= $escape($status) ?></p><?php endif; ?>
<?php if (!$seasons && !$status): ?><p>Nenhuma temporada V3 iniciada.</p><?php endif; ?>
<?php if ($seasons): ?>
<form method="get"><label for="season">Temporada</label>
<select id="season" name="season">
<?php foreach ($seasons as $option): ?>
<option value="<?= $escape($option['id']) ?>" <?= $season && $season['id'] === $option['id'] ? 'selected' : '' ?>><?= $escape($option['season_key']) ?></option>
<?php endforeach; ?>
</select> <button type="submit">Ver temporada</button></form>
<?php endif; ?>
<?php if ($season): ?>
<p>Início <?= $escape($season['started_at_utc']) ?> UTC · Capital inicial virtual <?= $number(array_sum(array_column($accounts, 'initial_equity'))) ?> USDT · Referência em caixa 100,00 USDT por conta</p>
<div class="table-wrap"><table>
<thead><tr><th>Estratégia</th><th>Patrimônio (USDT)</th><th>Retorno</th><th>P&amp;L realizado</th><th>P&amp;L aberto</th><th>Drawdown máx.</th><th>Saídas e acerto</th><th>Taxas</th><th>Posição</th><th>Estado</th><th>Último tick UTC</th><th>Próximo UTC</th><th>Motivo</th></tr></thead>
<tbody><?php foreach ($accounts as $account): ?>
<tr>
<td><?= $escape($account['strategy_key']) ?> <small>v<?= $escape($account['strategy_version']) ?>, <?= $account['strategy_key'] === 'FUNDING_BIAS' ? 'perp. pendente' : 'spot 1x' ?></small></td>
<td><?= $number($account['equity']) ?></td><td><?= $number(100 * ($account['equity'] / $account['initial_equity'] - 1)) ?>%</td>
<td><?= $number($account['realized_pnl']) ?></td><td><?= $number($account['unrealized_pnl']) ?></td>
<td><?= $number((float)$account['max_drawdown_bps'] / 100) ?>%</td>
<td><?= (int)$account['closed'] ?> · <?= $account['closed'] > 0 ? $number(100 * $account['won'] / $account['closed'], 1) . '%' : 'amostra insuficiente' ?></td>
<td><?= $number($account['fees'], 4) ?></td><td><?= $number($account['quantity'], 5) ?> BTC</td>
<td><?= $escape($account['status']) ?></td><td><?= $escape($account['last_tick_at_utc'] ?? '—') ?></td>
<td><?= $escape($account['next_decision_at_utc']) ?></td><td><?= $escape($account['reason_code'] ?? '—') ?></td>
</tr>
<?php endforeach; ?></tbody></table></div>
<h2>Motivos registrados nas últimas 24 horas</h2>
<?php if (!$reasons): ?><p>Sem decisões no período.</p><?php else: ?><ul>
<?php foreach ($reasons as $reason): ?><li><?= $escape($reason['reason_code']) ?> · <?= (int)$reason['n'] ?></li><?php endforeach; ?>
</ul><?php endif; ?>
<?php endif; ?>
<h2>Modelos em shadow</h2>
<?php if (!$models): ?><p>Nenhum modelo disponível para exibição.</p><?php else: ?>
<div class="table-wrap"><table>
<thead><tr><th>Modelo</th><th>Horizonte</th><th>Previsões</th><th>Alvos ausentes</th><th>Labels binários</th><th>Acurácia</th><th>Brier</th></tr></thead>
<tbody><?php foreach ($models as $model): ?><tr>
<td title="<?= $escape($model['model_id']) ?>"><?= $escape(substr($model['model_id'], 0, 12)) ?></td>
<td><?= $escape($model['horizon']) ?></td><td><?= (int)$model['n'] ?></td><td><?= (int)$model['gaps'] ?></td>
<td><?= (int)$model['binary_n'] ?></td><td><?= $model['accuracy'] === null ? '—' : $number(100 * $model['accuracy']) . '%' ?></td>
<td><?= $model['brier'] === null ? '—' : $number($model['brier'], 6) ?></td>
</tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</main></body></html>
