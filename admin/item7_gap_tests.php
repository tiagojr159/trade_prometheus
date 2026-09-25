<?php
declare(strict_types=1);
// ETAPA 07 — Binance spot: gaps, dedupe, recuperação, validação OHLCV.
chdir(dirname(__DIR__));
require 'bootstrap.php';

use Prometheus\core\Database;
use Prometheus\collectors\MarketCollector;

$pass = 0; $fail = 0; $failures = [];
function check7(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail, $failures;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $name . ($detail ? " ({$detail})" : '') . PHP_EOL;
    if ($ok) { $pass++; } else { $fail++; $failures[] = $name; }
}

echo "== ETAPA 07: Binance spot / candles ==\n";

// 1. Coleta real de 500 candles 1m
$col = new MarketCollector();
$count = $col->collect('BTCUSDT', '1m', 500);
check7('coleta real executada', $count > 0, $count . ' candles');

// 2. Validação OHLCV: high >= max(open,close), low <= min(open,close), volume >= 0
$bad = Database::fetch(
    "SELECT COUNT(*) c FROM market_data
     WHERE symbol='BTCUSDT' AND interval_name='1m'
       AND (high_price < GREATEST(open_price, close_price)
         OR low_price  > LEAST(open_price, close_price)
         OR volume < 0
         OR open_price <= 0 OR close_price <= 0)"
);
check7('OHLCV coerente (high/low/volume)', (int)$bad['c'] === 0, $bad['c'] . ' candles inválidos');

// 3. close_time = open_time + 60s - 1 (candles 1m)
$bad = Database::fetch(
    "SELECT COUNT(*) c FROM market_data
     WHERE symbol='BTCUSDT' AND interval_name='1m'
       AND UNIX_TIMESTAMP(close_time) <> UNIX_TIMESTAMP(open_time) + 59"
);
check7('close_time consistente com timeframe 1m', (int)$bad['c'] === 0, $bad['c'] . ' inconsistentes');

// 4. Sem duplicação: unique key (symbol, interval_name, open_time)
$dup = Database::fetch(
    "SELECT COUNT(*) c FROM (
        SELECT symbol, interval_name, open_time, COUNT(*) n
        FROM market_data WHERE symbol='BTCUSDT' GROUP BY symbol, interval_name, open_time HAVING n > 1
     ) d"
);
check7('sem candles duplicados (mesma janela)', (int)$dup['c'] === 0);

// 5. Idempotência: coletar 2x não duplica
$before = (int)Database::fetch("SELECT COUNT(*) c FROM market_data WHERE symbol='BTCUSDT' AND interval_name='1m'")['c'];
$col->collect('BTCUSDT', '1m', 100);
$after = (int)Database::fetch("SELECT COUNT(*) c FROM market_data WHERE symbol='BTCUSDT' AND interval_name='1m'")['c'];
check7('re-coleta idempotente (ON DUPLICATE KEY UPDATE)', $after === $before, "antes={$before} depois={$after}");

// 6. Detecção de gaps nas últimas 500 candles
$rows = Database::fetchAll(
    "SELECT UNIX_TIMESTAMP(open_time) t FROM market_data
     WHERE symbol='BTCUSDT' AND interval_name='1m'
     ORDER BY open_time DESC LIMIT 500"
);
$gaps = 0;
for ($i = 0; $i < count($rows) - 1; $i++) {
    if ($rows[$i]['t'] - $rows[$i + 1]['t'] !== 60) {
        $gaps++;
    }
}
echo "  [INFO] gaps detectados nas últimas 500 candles: {$gaps}\n";

// 7. Recuperação automática: se houver gap, coletar janela anterior preenche
//    Simulação determinística: deleta 3 candles do meio e re-coleta
$mid = $rows[100]; // ~100 candles atrás
$deleted = Database::execute(
    "DELETE FROM market_data WHERE symbol='BTCUSDT' AND interval_name='1m'
       AND open_time IN (
           FROM_UNIXTIME(?) - INTERVAL 1 MINUTE, FROM_UNIXTIME(?), FROM_UNIXTIME(?) + INTERVAL 1 MINUTE)",
    [$mid['t'], $mid['t'], $mid['t']]
);
if ($deleted >= 2) {
    $col->collect('BTCUSDT', '1m', 500);
    $restored = Database::fetch(
        "SELECT COUNT(*) c FROM market_data WHERE symbol='BTCUSDT' AND interval_name='1m' AND UNIX_TIMESTAMP(open_time) BETWEEN ? - 60 AND ? + 60",
        [$mid['t'], $mid['t']]
    );
    check7('recuperação automática de candles faltantes', (int)$restored['c'] >= 3, $restored['c'] . '/3 restauradas');
} else {
    check7('recuperação automática de candles faltantes', false, "delete afetou {$deleted}");
}

// 8. Freshness: última candle não pode estar > 10 min atrasada com collector saudável
$last = Database::fetch("SELECT MAX(open_time) t FROM market_data WHERE symbol='BTCUSDT' AND interval_name='1m'");
$age = time() - strtotime($last['t']);
check7('última candle recente (< 10 min)', $age < 600, "age={$age}s");

echo "\nRESULTADO: {$pass} pass, {$fail} fail\n";
if ($failures) { echo 'Falhas: ' . implode('; ', $failures) . PHP_EOL; exit(1); }
exit(0);
