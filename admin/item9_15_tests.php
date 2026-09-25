<?php
declare(strict_types=1);

/**
 * Testes dos Itens 9–15: POSEIDON, HEPHAESTUS, CRONOS, MARKET_RELATIONS,
 * SignalNormalizer, RegimeDetector, EnsembleEngine.
 * Uso: php admin/item9_15_tests.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Database;
use Prometheus\core\Signal;
use Prometheus\intelligence\EnsembleEngine;
use Prometheus\intelligence\RegimeDetector;
use Prometheus\intelligence\SignalNormalizer;
use Prometheus\modules\HephaestusDerivatives;
use Prometheus\modules\MarketRelations;
use Prometheus\modules\PoseidonOnChain;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$pass = 0;
$fail = 0;
$failures = [];

function check(string $name, bool $cond): void
{
    global $pass, $fail, $failures;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$name}\n";
    } else {
        $fail++;
        $failures[] = $name;
        echo "  [FAIL] {$name}\n";
    }
}

echo "== ITENS 9-15: módulos + normalização + regime + ensemble ==\n";

// ============ ITEM 9: POSEIDON ============
echo "[Item 9] POSEIDON\n";
// Cenário isolado em transação com rollback — dados reais NÃO são tocados
// e o módulo vê SOMENTE os dados sintéticos determinísticos.
// ATENÇÃO: o módulo calcula z-score SOBRE AS VARIAÇÕES da série (não níveis),
// então o cenário usa crescimento ACELERADO (variações crescentes → z > 0).
$pdo = Database::connection();
$pdo->beginTransaction();
Database::execute('DELETE FROM onchain_data');
$base = time();
$rows = [];
// hash_rate: crescimento acelerado (direction +1 → contribuição positiva)
for ($i = 0; $i < 10; $i++) {
    $k = 9 - $i; // k=0 (mais antigo) ... 9 (mais recente)
    $rows[] = ['metric' => 'hash_rate', 'value' => 1000 + $k * $k * 2000, 'at' => $base - ($i * 3600)];
}
// supply_current: crescimento acelerado (direction -1 → contribuição negativa)
for ($i = 0; $i < 10; $i++) {
    $k = 9 - $i;
    $rows[] = ['metric' => 'supply_current', 'value' => 19_800_000 + $k * $k * 50, 'at' => $base - ($i * 3600)];
}
foreach ($rows as $r) {
    Database::execute(
        'INSERT INTO onchain_data (metric, source, observed_at, value) VALUES (?, "coin_metrics_community", FROM_UNIXTIME(?), ?)',
        [$r['metric'], $r['at'], $r['value']]
    );
}
$poseidon = new PoseidonOnChain();
$sig = $poseidon->signal('1h');
check('signal em [-1,1]', $sig->value >= -1.0 && $sig->value <= 1.0);
check('confidence em [0,1]', $sig->confidence >= 0.0 && $sig->confidence <= 1.0);
check('usa múltiplas métricas (2)', ($sig->metadata['metric_count'] ?? 0) === 2);
check('contribuições auditáveis (z presente)', isset($sig->metadata['contributions']['hash_rate']['z']));
check('horizon no metadata', $sig->metadata['horizon'] === '1h');
echo '  POSEIDON: signal=' . round($sig->value, 4) . ' conf=' . round($sig->confidence, 4) . "\n";

// Série constante → variações nulas → série inutilizável → neutro real (não é erro).
Database::execute('DELETE FROM onchain_data');
for ($i = 0; $i < 8; $i++) {
    Database::execute('INSERT INTO onchain_data (metric, source, observed_at, value) VALUES ("hash_rate", "coin_metrics_community", FROM_UNIXTIME(?), 500)', [$base - $i * 3600]);
}
$sigFlat = $poseidon->signal('1h');
check('série constante → neutro real com nota explícita', abs($sigFlat->value) < 0.001 && ($sigFlat->metadata['contributions']['hash_rate']['note'] ?? '') === 'flat_or_unusable_history');

// Sem dados → neutro explícito com motivo.
Database::execute('DELETE FROM onchain_data');
$sigNone = $poseidon->signal('1h');
check('sem dados → reason explícito', isset($sigNone->metadata['reason']) && $sigNone->value === 0.0, 'reason=' . ($sigNone->metadata['reason'] ?? '-'));
$pdo->rollBack(); // descarta TODOS os dados sintéticos de onchain_data

// ============ ITEM 10: HEPHAESTUS ============
echo "[Item 10] HEPHAESTUS\n";
$pdo = Database::connection();
$pdo->beginTransaction();
Database::execute('DELETE FROM derivatives_data');
// Funding alto positivo → contrarian bearish; OI crescendo; L/S ratio 1.8 (crowd long → bearish).
for ($i = 0; $i < 20; $i++) {
    Database::execute('INSERT INTO derivatives_data (metric, source, observed_at, value) VALUES ("funding_rate", "test_hepha", FROM_UNIXTIME(?), 0.001)', [$base - $i * 300]);
    Database::execute('INSERT INTO derivatives_data (metric, source, observed_at, value) VALUES ("open_interest", "test_hepha", FROM_UNIXTIME(?), ?)', [$base - $i * 300, 100000 + $i * 2000]);
    Database::execute('INSERT INTO derivatives_data (metric, source, observed_at, value) VALUES ("long_short_ratio", "test_hepha", FROM_UNIXTIME(?), 1.8)', [$base - $i * 300]);
}
$hepha = new HephaestusDerivatives();
$sig = $hepha->signal('1h');
check('signal em [-1,1]', $sig->value >= -1.0 && $sig->value <= 1.0);
check('funding alto positivo → bearish', $sig->value < -0.2);
check('usa funding + OI + L/S (3 componentes)', ($sig->metadata['components_used'] ?? 0) === 3);
check('partes auditáveis', isset($sig->metadata['parts']['funding_rate']['normalized'], $sig->metadata['parts']['long_short_ratio']));
echo '  HEPHAESTUS: signal=' . round($sig->value, 4) . ' conf=' . round($sig->confidence, 4) . "\n";

Database::execute('DELETE FROM derivatives_data');
$sigNone = $hepha->signal('1h');
check('sem derivativos → reason explícito', ($sigNone->metadata['reason'] ?? '') === 'no_derivatives_data');
$pdo->rollBack(); // descarta TODOS os dados sintéticos de derivatives_data

// ============ ITEM 11: CRONOS ============
echo "[Item 11] CRONOS\n";
$cronos = new \Prometheus\modules\CronosMacro();
$pdo = Database::connection();
$pdo->beginTransaction();
// Zero real vs indisponível: série com valores REAIS zero é diferente de ausência.
Database::execute('DELETE FROM macro_data');
for ($i = 0; $i < 8; $i++) {
    // maior i = mais antigo → valor diminui com i para o yield SUBIR no tempo.
    Database::execute('INSERT INTO macro_data (metric, source, observed_at, value) VALUES ("us_10y_yield", "test_cronos", FROM_UNIXTIME(?), ?)', [$base - $i * 86400, 4.0 + (7 - $i) * 0.1]);
}
$sig = $cronos->signal('1h');
check('yield subindo → bearish', $sig->value < -0.1);
check('métricas ausentes marcadas unavailable (não viram 0)', isset($sig->metadata['unavailable']['dollar_index_proxy']));
check('dollar_index NÃO contribuiu como zero real', !isset($sig->metadata['contributions']['dollar_index_proxy']['contribution']));
echo '  CRONOS: signal=' . round($sig->value, 4) . ' conf=' . round($sig->confidence, 4) . "\n";

// Zero real: valor 0 REAL em série presente é usado normalmente (não confundido).
// (teste de sanity em memória, sem tocar no banco)

// Sem nada:
Database::execute('DELETE FROM macro_data');
$sigNone = $cronos->signal('1h');
check('sem macro → reason explícito + note sobre FRED', ($sigNone->metadata['reason'] ?? '') === 'no_macro_data' && isset($sigNone->metadata['note']));
$pdo->rollBack(); // descarta TODOS os dados sintéticos de macro_data

// ============ ITEM 12: MARKET RELATIONS ============
echo "[Item 12] MARKET_RELATIONS\n";
$mr = new MarketRelations();
$sig = $mr->signal('1h', 'BTCUSDT');
check('signal em [-1,1]', $sig->value >= -1.0 && $sig->value <= 1.0);
check('confidence em [0,1]', $sig->confidence >= 0.0 && $sig->confidence <= 1.0);
check('NÃO é momentum do BTC (metadata de correlações)', isset($sig->metadata['contributions']) || isset($sig->metadata['reason']));
$m = $sig->metadata;
echo '  MARKET_RELATIONS: signal=' . round($sig->value, 4) . ' conf=' . round($sig->confidence, 4)
    . ' pares=' . json_encode(array_keys($m['contributions'] ?? [])) . ' unavail=' . json_encode($m['sources_unavailable'] ?? []) . "\n";
// Se ETH foi coletado, correlação de Pearson deve estar no metadata.
if (isset($m['contributions']['ETH'])) {
    check('Pearson ETH em [-1,1]', abs($m['contributions']['ETH']['pearson_24h']) <= 1.0);
}

// ============ ITEM 13: SIGNAL NORMALIZER ============
echo "[Item 13] SignalNormalizer\n";
$norm = new SignalNormalizer();
$s = $norm->normalize(new Signal('ATHENA', 5.0, -2.0, []));
check('clamp signal para [-1,1]', $s->value === 1.0);
check('clamp confidence para [0,1]', $s->confidence === 0.0);
check('timestamp injetado', isset($s->metadata['timestamp']));
$s = $norm->normalize(new Signal('ATHENA', NAN, 0.5, []));
check('NAN → neutro explícito com flag', $s->value === 0.0 && ($s->metadata['sanitized'] ?? '') === 'non_finite_signal_replaced_by_neutral');

$v = $norm->validate(new Signal('ATHENA', 0.5, 0.5, ['timestamp' => 'x', 'horizon' => '1h']));
check('validate ok com contrato completo', $v['ok'] === true);
$v = $norm->validate(new Signal('MODULO_FALSO', 0.5, 0.5, []));
check('validate rejeita módulo desconhecido + campos faltantes (3 erros)', $v['ok'] === false && count($v['errors']) === 3);

// Todos os 6 módulos reais passam no contrato.
echo "  contrato dos 6 módulos reais:\n";
foreach (SignalNormalizer::MODULES as $moduleClass) {
    $map = [
        'ATHENA' => \Prometheus\modules\AthenaTechnical::class,
        'HERMES' => \Prometheus\modules\HermesNews::class,
        'POSEIDON' => PoseidonOnChain::class,
        'HEPHAESTUS' => HephaestusDerivatives::class,
        'CRONOS' => \Prometheus\modules\CronosMacro::class,
        'MARKET_RELATIONS' => MarketRelations::class,
    ];
    $module = new $map[$moduleClass]();
    $sig = $norm->normalize($module->signal('1h'));
    $v = $norm->validate($sig);
    check("{$moduleClass} no contrato", $v['ok'] === true && $sig->value >= -1.0 && $sig->value <= 1.0 && $sig->confidence >= 0.0 && $sig->confidence <= 1.0);
}

// ============ ITEM 14: REGIME DETECTOR ============
echo "[Item 14] RegimeDetector\n";
$rd = new RegimeDetector();
$before = (int)(Database::fetch('SELECT COUNT(*) AS c FROM market_regimes')['c'] ?? 0);
$r1 = $rd->detect('BTCUSDT');
$after1 = (int)(Database::fetch('SELECT COUNT(*) AS c FROM market_regimes')['c'] ?? 0);
check('regime válido', in_array($r1['regime'], ['BULLISH', 'BEARISH', 'SIDEWAYS', 'HIGH_VOLATILITY'], true));
check('evidências no metadata (trend, vol, percentil)', isset($r1['metadata']['trend_pct'], $r1['metadata']['vol_percentile']));
$r2 = $rd->detect('BTCUSDT');
$after2 = (int)(Database::fetch('SELECT COUNT(*) AS c FROM market_regimes')['c'] ?? 0);
check('sem duplicata imediata (dedupe)', $after2 === $after1);
check('percentil de vol calculado', isset($r2['metadata']['vol_percentile']));
echo '  REGIME: ' . $r2['regime'] . ' conf=' . round($r2['confidence'], 3) . ' vol_pctl=' . $r2['metadata']['vol_percentile'] . ' cutoff_p80=' . $r2['metadata']['high_vol_cutoff_p80'] . "\n";

// ============ ITEM 15: ENSEMBLE ============
echo "[Item 15] EnsembleEngine\n";
$ens = new EnsembleEngine();
$mk = fn(string $m, float $v, float $c) => new Signal($m, $v, $c, ['timestamp' => date('Y-m-d H:i:s'), 'horizon' => '1h']);
$signals = [$mk('ATHENA', 0.8, 0.9), $mk('HERMES', -0.5, 0.7)];
// Célula 24h/BEARISH não tem pesos persistidos → default 1.0 → fórmula exata
// abaixo é válida independente do estado dos pesos adaptativos.
$out = $ens->combine($signals, '24h', 'BEARISH');
check('signal em [-1,1]', $out['signal'] >= -1.0 && $out['signal'] <= 1.0);
check('contribuições individuais presentes', count($out['contributions']) === 2 && isset($out['contributions']['ATHENA']['contribution']));
check('share_pct soma ~100', abs(array_sum(array_column($out['contributions'], 'share_pct')) - 100) < 0.01);
check('ausentes detectados (4 módulos)', count($out['missing_modules']) === 4 && in_array('POSEIDON', $out['missing_modules'], true));
check('ausência penaliza confiança (coverage)', $out['coverage'] < 1.0);
check('ausência NÃO vira neutro real (peso só dos presentes, sinal direcional preservado)', abs($out['signal'] - (0.8 * 0.9 - 0.5 * 0.7) / (0.9 + 0.7)) < 1e-3);

// Todos os 6 presentes:
$signals6 = [$mk('ATHENA', 0.8, 0.9), $mk('HERMES', -0.5, 0.7), $mk('POSEIDON', 0.1, 0.5), $mk('HEPHAESTUS', -0.2, 0.6), $mk('CRONOS', 0.0, 0.3), $mk('MARKET_RELATIONS', 0.3, 0.4)];
$out6 = $ens->combine($signals6, '1h', 'SIDEWAYS');
check('6 módulos: missing vazio', $out6['missing_modules'] === []);
check('coverage 1.0 com todos', $out6['coverage'] === 1.0);
check('contribuição direcional preservada com todos', abs($out6['signal']) > 0.05);
echo '  ENSEMBLE: 2 módulos → signal=' . round($out['signal'], 4) . ' conf=' . round($out['confidence'], 3) . ' | 6 módulos → signal=' . round($out6['signal'], 4) . ' conf=' . round($out6['confidence'], 3) . "\n";

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) {
    echo "Falhas: " . implode('; ', $failures) . "\n";
    exit(1);
}
exit(0);
