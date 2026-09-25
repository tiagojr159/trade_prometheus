<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
use Prometheus\admin\AdminGuard;
use Prometheus\core\Database;
use Prometheus\prediction\ProbabilityCalculator;

AdminGuard::requireAdmin();

// ---------- Dados reais (sem número fictício: ausência é exibida como indisponível) ----------
$symbol = prometheus_config('default_symbol', 'BTCUSDT');

$price = Database::fetch('SELECT close_price, open_time FROM market_data WHERE symbol=? ORDER BY open_time DESC LIMIT 1', [$symbol]);
$predictions = Database::fetchAll('SELECT * FROM predictions WHERE symbol=? ORDER BY created_at DESC LIMIT 4', [$symbol]);
$regime = Database::fetch('SELECT * FROM market_regimes ORDER BY created_at DESC LIMIT 1');

// Últimos sinais por módulo (o mais recente de cada um dos 6).
$signalsByModule = [];
foreach (Database::fetchAll('SELECT s.* FROM signals s INNER JOIN (SELECT module, MAX(id) AS maxid FROM signals GROUP BY module) m ON m.maxid = s.id') as $s) {
    $signalsByModule[$s['module']] = $s;
}

$weights = Database::fetchAll('SELECT w.* FROM module_weights w INNER JOIN (SELECT module, horizon, MAX(updated_at) AS mu FROM module_weights GROUP BY module, horizon) x ON x.module=w.module AND x.horizon=w.horizon AND x.mu=w.updated_at LIMIT 24');
$news = Database::fetchAll('SELECT title, source, sentiment, published_at FROM news ORDER BY published_at DESC LIMIT 6');
$history = Database::fetchAll('SELECT p.horizon, p.predicted_direction, p.probability_up, p.confidence, p.created_at, r.actual_direction, r.directional_hit FROM predictions p LEFT JOIN prediction_results r ON r.prediction_id=p.id WHERE p.symbol=? ORDER BY p.created_at DESC LIMIT 12', [$symbol]);
$perf = Database::fetchAll('SELECT module, horizon, regime, sample_size, accuracy, brier_score FROM module_performance ORDER BY updated_at DESC LIMIT 12');

// Métricas gerais reais.
// Métricas gerais: SEMÂNTICA v2 — accuracy direcional usa directional_hit
// (binário); FLAT/legacy ficam de fora (nunca contam como erro).
$overall = Database::fetch('SELECT COUNT(*) AS n, AVG(r.directional_hit) AS accuracy, AVG(r.return_pct) AS avg_return FROM predictions p JOIN prediction_results r ON r.prediction_id=p.id WHERE p.symbol=? AND r.evaluation_version = 2 AND r.directional_hit IS NOT NULL', [$symbol]);

// Status das fontes (última coleta de cada uma).
$sources = [
    'binance_candles' => Database::fetch('SELECT MAX(created_at) AS t, COUNT(*) AS n FROM market_data WHERE symbol=?', [$symbol]),
    'derivatives' => Database::fetch('SELECT MAX(created_at) AS t, COUNT(*) AS n FROM derivatives_data'),
    'onchain' => Database::fetch('SELECT MAX(created_at) AS t, COUNT(*) AS n FROM onchain_data'),
    'macro_fred' => Database::fetch('SELECT MAX(created_at) AS t, COUNT(*) AS n FROM macro_data'),
    'news' => Database::fetch('SELECT MAX(created_at) AS t, COUNT(*) AS n FROM news'),
    'llm' => Database::fetch('SELECT MAX(created_at) AS t, COUNT(*) AS n FROM llm_usage WHERE status="ok"'),
];

// Calibração real (se houver avaliações suficientes).
$calibration = (new ProbabilityCalculator())->calibration();

// Horizontes 15m/1h/4h/24h da última rodada de previsões.
$byHorizon = [];
foreach (Database::fetchAll('SELECT p.* FROM predictions p INNER JOIN (SELECT horizon, MAX(id) AS maxid FROM predictions WHERE symbol=? GROUP BY horizon) m ON m.maxid=p.id', [$symbol]) as $p) {
    $byHorizon[$p['horizon']] = $p;
}

function srcBadge(?array $row, int $staleMinutes = 30): string
{
    if (!$row || (int)($row['n'] ?? 0) === 0 || $row['t'] === null) {
        return '<span class="badge bg-secondary">indisponível</span>';
    }
    $ageMin = (time() - strtotime((string)$row['t'])) / 60;
    return $ageMin > $staleMinutes
        ? '<span class="badge bg-warning text-dark">desatualizada (' . (int)$ageMin . ' min)</span>'
        : '<span class="badge bg-success">ok</span>';
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PROMETHEUS - BTC Intelligence</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/prometheus.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg border-bottom">
  <div class="container-fluid px-4">
    <a class="navbar-brand fw-bold" href="../index.php">PROMETHEUS</a>
    <div class="navbar-nav">
      <a class="nav-link" href="predictions.php">Previsões</a>
      <a class="nav-link" href="modules.php">Módulos</a>
      <a class="nav-link" href="performance.php">Performance</a>
      <a class="nav-link" href="settings.php">Config</a>
    </div>
    <div class="auto-refresh-control ms-auto me-3">
      <label for="autoRefreshInterval" class="form-label">Atualizar</label>
      <select id="autoRefreshInterval" class="form-select form-select-sm" aria-label="Intervalo de atualizacao automatica">
        <option value="0">manual</option>
        <option value="60" selected>1 min</option>
        <option value="300">5 min</option>
        <option value="600">10 min</option>
      </select>
      <small id="autoRefreshStatus" class="text-muted">auto ligado</small>
    </div>
    <span class="navbar-text">
      <small>Atualizado: <?= date('H:i:s') ?> · <?= htmlspecialchars($symbol) ?></small>
    </span>
  </div>
</nav>
<main class="container-fluid p-4">

  <section class="row g-3 mb-3">
    <div class="col-12 col-xl-3">
      <div class="metric">
        <span>Preço BTC</span>
        <?php if ($price): ?>
          <strong>$<?= number_format((float)$price['close_price'], 2, ',', '.') ?></strong>
          <small>candle <?= htmlspecialchars((string)$price['open_time']) ?></small>
        <?php else: ?>
          <strong>indisponível</strong><small>execute a coleta de mercado</small>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12 col-xl-3">
      <div class="metric">
        <span>Regime de mercado (estado, não direção)</span>
        <strong><?= $regime ? htmlspecialchars((string)$regime['regime']) : 'indeterminado' ?></strong>
        <small><?= $regime ? 'confiança ' . number_format((float)$regime['confidence'] * 100, 1) . '% · independente da direção prevista' : 'sem classificação ainda' ?></small>
      </div>
    </div>
    <div class="col-12 col-xl-6">
      <div class="metric">
        <span>Desempenho (todos os horizontes)</span>
        <?php if ($overall && (int)$overall['n'] > 0): ?>
          <strong><?= number_format(((float)$overall['accuracy']) * 100, 1) ?>% acerto</strong>
          <small><?= (int)$overall['n'] ?> previsões avaliadas · retorno médio <?= number_format((float)$overall['avg_return'], 3) ?>%</small>
        <?php else: ?>
          <strong>sem avaliações</strong><small>aguardando vencimento e avaliação</small>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Previsões por horizonte (reais; ausência explícita) -->
  <section class="row g-3">
    <?php foreach (['15m', '1h', '4h', '24h'] as $hz): ?>
      <div class="col-12 col-md-6 col-xl-3">
        <?php if (isset($byHorizon[$hz])): $p = $byHorizon[$hz]; ?>
          <?php
              // Semântica v2: direção é BINÁRIA (UP/DOWN); baixa convicção é
              // exposta como edge — NUNCA "SIDEWAYS" como direção probabilística.
              $pUp = (float)$p['probability_up'];
              $edgePp = (float)$p['edge'] * 100; // em pontos percentuais
              $dirClass = $p['predicted_direction'] === 'UP' ? 'up' : ($p['predicted_direction'] === 'DOWN' ? 'down' : '');
          ?>
          <div class="prediction <?= $dirClass ?>">
            <div class="d-flex justify-content-between align-items-start">
              <span><?= htmlspecialchars($hz) ?></span>
              <b><?= htmlspecialchars((string)$p['predicted_direction']) ?></b>
            </div>
            <div class="prob">P(up) <?= number_format($pUp * 100, 1) ?>%</div>
            <div class="progress my-2"><div class="progress-bar" style="width:<?= $pUp * 100 ?>%"></div></div>
            <small>P(down) <?= number_format((float)$p['probability_down'] * 100, 1) ?>% · conf. <?= number_format((float)$p['confidence'] * 100, 1) ?>% · edge <?= number_format($edgePp, 1) ?> p.p.</small>
          </div>
        <?php else: ?>
          <div class="prediction" style="opacity:.55">
            <span><?= htmlspecialchars($hz) ?></span>
            <b class="text-muted">sem previsão</b>
            <small>não gerada ainda para este horizonte</small>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="row g-3 mt-1">
    <div class="col-12 col-xl-4">
      <div class="panel">
        <h2>Sinais (mais recentes por módulo)</h2>
        <?php if (!$signalsByModule): ?>
          <p class="text-muted">Nenhum sinal registrado. Execute o ciclo de previsões.</p>
        <?php endif; ?>
        <?php foreach (['ATHENA', 'HERMES', 'POSEIDON', 'HEPHAESTUS', 'CRONOS', 'MARKET_RELATIONS'] as $m): ?>
          <?php if (isset($signalsByModule[$m])): $s = $signalsByModule[$m]; $signalMeta = json_decode((string)$s['metadata'], true) ?: []; $signalReason = strtolower((string)($signalMeta['reason'] ?? '')); $signalStatus = (string)($signalMeta['status'] ?? (preg_match('/(llm_analysis_failed|module_processing_failed|non_finite)/', $signalReason) ? 'ERROR' : (preg_match('/(no_|insufficient_|without_usable|unavailable)/', $signalReason) ? 'UNAVAILABLE' : 'AVAILABLE'))); ?>
            <div class="bar-row">
              <span><?= htmlspecialchars($m) ?> <small class="text-muted"><?= htmlspecialchars($signalStatus) ?></small></span>
              <?php if (in_array($signalStatus, ['AVAILABLE', 'STALE'], true)): ?>
                <b><?= number_format((float)$s['signal_value'], 3) ?> <small class="text-muted">(conf. <?= number_format((float)$s['confidence'], 2) ?>)</small></b>
              <?php else: ?>
                <b class="text-muted">-</b>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="bar-row"><span><?= htmlspecialchars($m) ?></span><b class="text-muted">indisponível</b></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-12 col-xl-4">
      <div class="panel">
        <h2>Pesos</h2>
        <?php if (!$weights): ?>
          <p class="text-muted">Pesos padrão (1.0) — otimização ativa após amostra mínima.</p>
        <?php endif; ?>
        <?php foreach ($weights as $w): ?>
          <div class="bar-row"><span><?= htmlspecialchars($w['module'] . ' ' . $w['horizon'] . ' ' . $w['regime']) ?></span><b><?= number_format((float)$w['weight'], 3) ?></b></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-12 col-xl-4">
      <div class="panel">
        <h2>Status das fontes</h2>
        <div class="bar-row"><span>Binance candles</span><b><?= srcBadge($sources['binance_candles']) ?></b></div>
        <div class="bar-row"><span>Derivativos (funding/OI/L-S)</span><b><?= srcBadge($sources['derivatives']) ?></b></div>
        <div class="bar-row"><span>On-chain</span><b><?= srcBadge($sources['onchain'], 120) ?></b></div>
        <div class="bar-row"><span>Macro (FRED)</span><b><?= srcBadge($sources['macro_fred'], 1440) ?></b></div>
        <div class="bar-row"><span>Notícias</span><b><?= srcBadge($sources['news'], 120) ?></b></div>
        <div class="bar-row"><span>LLM (OpenAI ok)</span><b><?= srcBadge($sources['llm'], 1440) ?></b></div>
        <small class="text-muted d-block mt-2">"indisponível" = sem coleta real; nada é substituído por valor fictício.</small>
      </div>
    </div>
  </section>

  <section class="row g-3 mt-1">
    <div class="col-12 col-xl-4">
      <div class="panel">
        <h2>Calibração de probabilidades</h2>
        <?php if (empty($calibration['bins'])): ?>
          <p class="text-muted">Sem previsões avaliadas suficientes para calibração.</p>
        <?php else: ?>
          <table class="table table-sm">
            <thead><tr><th>Faixa P(up)</th><th>n</th><th>Prev.</th><th>Real</th><th>Gap</th></tr></thead>
            <tbody>
            <?php foreach ($calibration['bins'] as $b): ?>
              <tr><td><?= htmlspecialchars($b['bin']) ?></td><td><?= $b['n'] ?></td><td><?= number_format($b['avg_predicted_p_up'], 3) ?></td><td><?= number_format($b['observed_up_freq'], 3) ?></td><td><?= number_format($b['calibration_gap'], 3) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (!empty($calibration['note'])): ?><small class="text-warning"><?= htmlspecialchars((string)$calibration['note']) ?></small><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12 col-xl-8">
      <div class="panel">
        <h2>Histórico</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th>Quando</th><th>Horizonte</th><th>Previsto</th><th>Real</th><th>P(up)</th><th>Conf.</th><th>OK</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
              <tr>
                <td><?= htmlspecialchars((string)$h['created_at']) ?></td>
                <td><?= htmlspecialchars((string)$h['horizon']) ?></td>
                <td><b><?= htmlspecialchars((string)$h['predicted_direction']) ?></b></td>
                <td><?= $h['actual_direction'] === null ? '<span class="text-muted">pendente</span>' : htmlspecialchars((string)$h['actual_direction']) ?></td>
                <td><?= number_format((float)$h['probability_up'] * 100, 1) ?>%</td>
                <td><?= number_format((float)$h['confidence'] * 100, 1) ?>%</td>
                <td><?= $h['directional_hit'] === null ? '-' : ((int)$h['directional_hit'] ? 'sim' : 'não') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section class="row g-3 mt-1">
    <div class="col-12 col-xl-7">
      <div class="panel">
        <h2>Performance por módulo</h2>
        <?php if (!$perf): ?>
          <p class="text-muted">Sem desempenho por módulo ainda (requer previsões avaliadas).</p>
        <?php endif; ?>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead><tr><th>Módulo</th><th>Horizonte</th><th>Regime</th><th>n</th><th>Acc</th><th>Brier</th></tr></thead>
            <tbody>
            <?php foreach ($perf as $p): ?>
              <tr><td><?= htmlspecialchars((string)$p['module']) ?></td><td><?= htmlspecialchars((string)$p['horizon']) ?></td><td><?= htmlspecialchars((string)$p['regime']) ?></td><td><?= (int)$p['sample_size'] ?></td><td><?= number_format((float)$p['accuracy'], 3) ?></td><td><?= number_format((float)$p['brier_score'], 3) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-12 col-xl-5">
      <div class="panel">
        <h2>Notícias</h2>
        <?php if (!$news): ?>
          <p class="text-muted">Nenhuma notícia coletada (NewsAPI <?= empty((require dirname(__DIR__) . '/config/api_keys.php')['news_api_key']) ? 'não configurada' : 'sem retorno' ?>).</p>
        <?php endif; ?>
        <?php foreach ($news as $n): ?>
          <p class="news"><b><?= htmlspecialchars((string)$n['source']) ?></b> <?= htmlspecialchars((string)$n['title']) ?></p>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</main>
<script>window.PROMETHEUS_PERF = <?= json_encode($perf, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="../assets/js/charts.js"></script>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
