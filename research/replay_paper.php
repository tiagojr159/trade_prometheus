<?php
declare(strict_types=1);

// Isolate the simulator's PHP clock in this CLI process. SQL NOW() must agree.
namespace Prometheus\core {
    function time(): int { return $GLOBALS['paperReplayTime']; }
    function date(string $format, ?int $timestamp = null): string {
        return \date($format, $timestamp ?? time());
    }
}

namespace {
    use Prometheus\core\Database;
    use Prometheus\core\PaperStrategyEngine;
    use Prometheus\core\PaperTrader;

    if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
    require dirname(__DIR__) . '/bootstrap.php';
    if ($argc !== 3) exit("Uso: php research/replay_paper.php AAAA-MM-DD AAAA-MM-DD (fim exclusivo)\n");
    [$script, $start, $end] = $argv;
    foreach ([$start, $end] as $day) {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (!$parsed || $parsed->format('Y-m-d') !== $day) throw new \InvalidArgumentException('Data inválida.');
    }
    if ($end <= $start) throw new \InvalidArgumentException('Intervalo inválido.');
    $db = Database::connection();
    // Use the actual observed cron cadence, including outages. Replaying every
    // minute would invent stop/target fills between registered observations.
    $cycles = Database::fetchAll('SELECT created_at FROM paper_trading_equity
        WHERE mode="PROMETHEUS" AND created_at>=? AND created_at<? ORDER BY created_at,id', [$start, $end]);
    if (!$cycles) throw new \RuntimeException('Sem ciclos registrados no período.');
    // Index the same candles by closing time for the repeated as-of scans.
    // The engine still applies close, availability and ingestion cutoffs per tick.
    $marketCopy = $db->prepare('CREATE TEMPORARY TABLE replay_market
        (INDEX replay_close (symbol,interval_name,close_time,open_time))
        SELECT id,symbol,interval_name,open_time,close_time,open_price,high_price,low_price,close_price,volume,
               ingested_at,available_at,temporal_quality FROM market_data WHERE symbol=? AND close_time<?');
    $marketCopy->execute([prometheus_config('default_symbol','BTCUSDT'), $end]);
    $db->exec('ALTER TABLE replay_market RENAME TO market_data');
    foreach (['paper_trading_v2_accounts','paper_trading_heartbeat','paper_trading_decisions',
              'paper_trading_equity','paper_trading_risk_events','paper_trading_v2_orders','paper_trading_v2_trades'] as $table) {
        $db->exec("CREATE TEMPORARY TABLE replay_copy LIKE `$table`");
        $db->exec("ALTER TABLE replay_copy RENAME TO `$table`");
    }
    $db->exec('SET SESSION TRANSACTION READ ONLY');
    foreach (array_keys(PaperStrategyEngine::modes()) as $mode) {
        Database::execute('INSERT INTO paper_trading_v2_accounts (mode,initial_balance,cash_balance,peak_equity) VALUES (?,100,100,100)', [$mode]);
        Database::execute('INSERT INTO paper_trading_heartbeat (mode) VALUES (?)', [$mode]);
    }
    $trader = new PaperTrader();
    foreach ($cycles as $cycle) {
        $GLOBALS['paperReplayTime'] = strtotime($cycle['created_at']);
        $db->exec('SET timestamp=' . (int)$GLOBALS['paperReplayTime']);
        $result = $trader->run(prometheus_config('default_symbol','BTCUSDT'));
        foreach ($result['modes'] as $mode => $outcome) {
            if ($outcome['status'] === 'error') throw new \RuntimeException($mode . ' ' . $outcome['message']);
        }
    }
    echo count($cycles), " ciclos de ", $cycles[0]['created_at'], " a ", end($cycles)['created_at'], "\n";
    echo "modo,equity_usd,trades_fechados,custos_pagos_usd,drawdown_pct\n";
    foreach (array_keys(PaperStrategyEngine::modes()) as $mode) {
        $equity = Database::fetch('SELECT equity FROM paper_trading_equity WHERE mode=? ORDER BY id DESC LIMIT 1', [$mode]);
        $stats = Database::fetch('SELECT max_drawdown_pct,total_fees+total_slippage AS costs FROM paper_trading_v2_accounts WHERE mode=?', [$mode]);
        $closed = Database::fetch('SELECT COUNT(*) n FROM paper_trading_v2_trades WHERE mode=?', [$mode]);
        printf("%s,%s,%d,%.4f,%.4f\n", $mode, $equity ? number_format((float)$equity['equity'],4,'.','') : 'NA',
            $closed['n'], $stats['costs'], $stats['max_drawdown_pct']);
    }
}
