<?php
declare(strict_types=1);
// Recompute DETERMINÃSTICO dos prediction_results v1 â†’ v2 (DirectionPolicy).
//
// DecisÃ£o tÃ©cnica (item 16 da especificaÃ§Ã£o): os resultados antigos foram
// produzidos com a semÃ¢ntica ternÃ¡ria (SIDEWAYS em banda Â±0.05%), incompatÃ­vel
// com a v2 binÃ¡ria. Como o recompute Ã© DETERMINÃSTICO â€” initial_price e
// target_time jÃ¡ persistidos na previsÃ£o, target_price = primeiro candle 1m
// com open_time >= target_time e <= NOW (mesma polÃ­tica de preÃ§o do evaluator),
// sem NENHUM dado que nÃ£o existisse â€” a opÃ§Ã£o (A) da especificaÃ§Ã£o Ã© segura.
// Backup lÃ³gico do estado anterior antes do recompute (item 16: nÃ£o apagar).
//
// Uso: php admin/migrate_eval_v2.php [--dry-run]
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\Database;
use Prometheus\core\DirectionPolicy;
use Prometheus\evaluation\TargetPriceResolver;

$dryRun = in_array('--dry-run', $argv, true);

$rows = Database::fetchAll(
    'SELECT p.id, p.symbol, p.target_time, p.initial_price, p.predicted_direction, p.probability_up,
            r.id rid, r.final_price, r.actual_direction, r.correct, r.evaluation_version
     FROM predictions p
     JOIN prediction_results r ON r.prediction_id = p.id
     ORDER BY p.id ASC'
);

echo 'resultados v1 a recomputar: ' . count($rows) . PHP_EOL;
if ($dryRun) {
    echo "(dry-run: nada serÃ¡ alterado)\n";
}

$backup = [];
$changed = 0;
$flat = 0;
$targetPriceMismatch = 0;

foreach ($rows as $r) {
    $initial = (float)$r['initial_price'];

    // Recompute determinÃ­stico: final_price Ã© SEMPRE re-derivado da candle de
    // polÃ­tica (primeiro candle 1m com open_time >= target_time e <= NOW) â€”
    // determinÃ­stico e idempotente. O valor armazenado anteriormente NÃƒO Ã©
    // confiÃ¡vel: registros legados foram gravados sem filtro de intervalo
    // (antes da coleta multi-timeframe). O valor antigo sÃ³ Ã© usado se a
    // candle histÃ³rica nÃ£o existir mais (dado podado).
    $candle = TargetPriceResolver::resolve(
        (string)$r['symbol'], (string)$r['target_time'], date('Y-m-d H:i:s'),
        (string)prometheus_config('collector.market_interval', '1m')
    );
    $finalPrice = $candle ? (float)$candle['close_price'] : null;
    if ($finalPrice === null) {
        echo "  #{$r['id']}: SEM candle de vencimento â€” mantido pendente\n";
        continue;
    }

    $realized = ($finalPrice - $initial) / max(1e-12, $initial);
    $actual = DirectionPolicy::actual($realized);

    // DireÃ§Ã£o prevista v2: re-derivada da P(up) ORIGINALMENTE persistida na
    // previsÃ£o (informaÃ§Ã£o que jÃ¡ existia em created_at â€” determinÃ­stico, sem
    // reescrever a previsÃ£o). PrevisÃµes legadas SIDEWAYS (banda Â±0.04) viram
    // UP ou DOWN conforme a distribuiÃ§Ã£o, exatamente como a v2 classificaria.
    $pred = DirectionPolicy::predictFromUp((float)$r['probability_up'])['direction'];
    $predIsLegacy = $r['predicted_direction'] === 'SIDEWAYS' && $pred !== 'INDETERMINATE';
    $hit = DirectionPolicy::hit($pred, $actual);
    $brier = DirectionPolicy::brier((float)$r['probability_up'], $actual);

    if ($actual === 'FLAT') {
        $flat++;
    }
    if ($r['final_price'] !== null && abs((float)$r['final_price'] - $finalPrice) > 1e-9) {
        $targetPriceMismatch++;
    }

    if (!$dryRun) {
        Database::execute(
            'UPDATE prediction_results SET final_price=?, actual_direction=?, correct=?, directional_hit=?, realized_return=?, error_score=?, evaluation_version=2, return_pct=? WHERE id=?',
            [$finalPrice, $actual, $hit, $hit, $realized, $brier, $realized * 100, $r['rid']]
        );
    }

    $backup[] = [
        'rid' => $r['rid'],
        'old_predicted' => $r['predicted_direction'],
        'old_actual' => $r['actual_direction'],
        'old_correct' => $r['correct'],
        'rederived_predicted' => $pred,
        'new_actual' => $actual,
        'new_hit' => $hit,
    ];
    $changed++;
}

// Backup lÃ³gico (JSON) do estado prÃ©-recompute â€” nada Ã© apagado.
if (!$dryRun && $backup) {
    $dir = PROMETHEUS_ROOT . '/storage/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $file = $dir . '/prediction_results_pre_v2_' . date('Ymd_His') . '.json';
    file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT));
    echo "backup lÃ³gico: $file\n";
}

// MÃ©tricas derivadas contaminadas pela semÃ¢ntica v1 â†’ reset (recompute
// determinÃ­stico pela prÃ³xima avaliaÃ§Ã£o/otimizaÃ§Ã£o com dados v2).
if (!$dryRun) {
    Database::execute('DELETE FROM module_performance');
    Database::execute('DELETE FROM module_weights');
    echo "module_performance e module_weights zerados (eram acumulados sob a semÃ¢ntica v1; serÃ£o reconstruÃ­dos incrementalmente com v2)\n";
}

echo "recomputados: $changed (FLAT: $flat, target_price divergente do registrado: $targetPriceMismatch)\n";
echo $dryRun ? "DRY-RUN concluÃ­do.\n" : "MIGRAÃ‡ÃƒO v2 concluÃ­da.\n";
