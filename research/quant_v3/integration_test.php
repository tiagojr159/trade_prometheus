<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__, 2) . '/bootstrap.php';

use Prometheus\core\QuantClock;
use Prometheus\core\Database;
use Prometheus\core\QuantMarket;
use Prometheus\core\QuantPolicy;
use Prometheus\core\QuantTournament;
use Prometheus\prediction\QuantFeatures;
use Prometheus\prediction\QuantShadowService;

function check(bool $value, string $name): void
{
    if (!$value) throw new RuntimeException($name);
    echo "[PASS] $name\n";
}

function connect(): PDO
{
    return new PDO('mysql:host=127.0.0.1;port=' . (int)(getenv('QUANT_TEST_PORT') ?: 3306) . ';charset=utf8mb4',
        getenv('QUANT_TEST_USER') ?: 'root', getenv('QUANT_TEST_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false]);
}

if (($argv[1] ?? '') === 'tick') {
    if (!preg_match('/^quant_v3_test_[a-f0-9]{12}$/D', $argv[2] ?? '')) exit(2);
    $concurrent = connect();
    $concurrent->exec('USE ' . $argv[2]);
    echo json_encode((new QuantTournament($concurrent))->tick('BTCUSDT', new DateTimeImmutable($argv[3])), JSON_THROW_ON_ERROR);
    exit;
}

$db = connect();
$schema = 'quant_v3_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");
try {
    $db->exec("USE `$schema`");
    $base = file_get_contents(PROMETHEUS_ROOT . '/sql/prometheus.sql');
    preg_match('/CREATE TABLE IF NOT EXISTS market_data \([\s\S]+?\) ENGINE=InnoDB;/', $base, $table);
    $db->exec($table[0]);
    $db->exec('CREATE TABLE paper_trading_v2_accounts (id INT PRIMARY KEY, cash DECIMAL(20,8)) ENGINE=InnoDB');
    $db->exec('INSERT INTO paper_trading_v2_accounts VALUES (1,98.11)');
    for ($repeat = 0; $repeat < 2; $repeat++) {
        foreach (glob(PROMETHEUS_ROOT . '/sql/migrations/0{19,20}_quant_v3_*.sql', GLOB_BRACE) ?: [
            PROMETHEUS_ROOT . '/sql/migrations/019_quant_v3_tournament.sql',
            PROMETHEUS_ROOT . '/sql/migrations/020_quant_v3_shadow.sql',
        ] as $file) {
            foreach (explode(';', file_get_contents($file)) as $sql) {
                if (trim($sql) !== '') $db->exec($sql);
            }
        }
    }
    $tournament = new QuantTournament($db);
    $now = new DateTimeImmutable('2026-09-01T12:00:05Z');
    $evidence = ['MOMENTUM' => ['policy_version' => QuantPolicy::VERSION, 'horizon' => '1h', 'independent_trades' => 50,
        'gross_lcb_bps' => 140, 'report_sha256' => str_repeat('b', 64),
        'started_at_utc' => '2026-05-01T00:00:00Z', 'ended_at_utc' => '2026-08-05T00:00:00Z',
        'prospective' => true, 'ledger_verified' => true, 'as_of_verified' => true,
        'metrics' => ['net_bps' => 80, 'legacy_net_bps' => -10, 'buy_hold_net_bps' => 5, 'incremental_lcb_bps' => 30,
            'stress_1_5_net_bps' => 60, 'stress_2_net_bps' => 40, 'max_drawdown_bps' => 100, 'dsr_probability' => .99]]];
    $negative = $evidence;
    $negative['MOMENTUM']['metrics']['net_bps'] = -10;
    try {
        $tournament->start('rejected-evidence', 'BTCUSDT', $now, $negative);
        throw new RuntimeException('Negative evidence unexpectedly enabled the season');
    } catch (InvalidArgumentException $error) {
        check($error->getMessage() === 'PROMOTION_GATES_NOT_MET', 'Negative prospective return cannot enable entries');
    }
    $first = $tournament->start('disposable-a', 'BTCUSDT', $now->modify('-1 minute'), $evidence);
    $second = $tournament->start('disposable-b', 'BTCUSDT', $now->modify('-1 minute'));
    check($tournament->start('disposable-a', 'BTCUSDT', $now->modify('+1 second'), $evidence) === $first,
        'Idempotent season creation');
    $account = $db->query("SELECT id FROM quant_tournament_accounts WHERE season_id=$first AND strategy_key='MOMENTUM'")->fetchColumn();
    $balance = $db->query('SELECT season_id,COUNT(*) n,SUM(cash) total FROM quant_tournament_accounts GROUP BY season_id')->fetchAll();
    check(count($balance) === 2 && count(array_filter($balance, static fn($row): bool => (int)$row['n'] === 15
        && (float)$row['total'] === 1500.0)) === 2, '15 accounts and 1500 USDT independently per season');
    $addCandle = $db->prepare('INSERT INTO market_data
        (symbol,source,interval_name,open_time,close_time,open_price,high_price,low_price,close_price,volume,available_at,ingested_at,temporal_quality)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $zone = new DateTimeZone(PROMETHEUS_TIMEZONE);
    $record = static function (DateTimeImmutable $start, int $length, int $price, ?DateTimeImmutable $ingest = null) use ($addCandle, $zone): void {
        $close = $start->modify('+' . ($length - 1) . ' seconds');
        $addCandle->execute(['BTCUSDT', 'binance', $length === 60 ? '1m' : '15m',
            $start->setTimezone($zone)->format('Y-m-d H:i:s'), $close->setTimezone($zone)->format('Y-m-d H:i:s'),
            $price - 20, $price + 30, $price - 40, $price, 3, $close->format('Y-m-d H:i:s'),
            ($ingest ?? $close->modify('+1 second'))->format('Y-m-d H:i:s'), 'INGESTION_ONLY']);
    };
    $baseTime = $now->setTime(12, 0, 0);
    foreach ([60, 900] as $duration) {
        for ($i = 100; $i >= 1; $i--) {
            $record($baseTime->modify('-' . ($i * $duration) . ' seconds'), $duration, 100000 - (($i - 1) * 50));
        }
    }
    $candlesAtT = QuantMarket::candles($db, 'BTCUSDT', '1m', $now);
    $record($baseTime, 60, 155500, $baseTime);
    check(QuantMarket::candles($db, 'BTCUSDT', '1m', $now) === $candlesAtT,
        'Open candle with future high/low/close cannot change features at T');
    $addQuote = $db->prepare('INSERT INTO quant_spot_quotes
        (symbol,requested_at_utc,ingested_at_utc,bid,ask,bid_qty,ask_qty) VALUES (?,?,?,?,?,1,1)');
    $quote = static function (DateTimeImmutable $request, int $price) use ($addQuote): void {
        $addQuote->execute(['BTCUSDT', QuantClock::sql($request), QuantClock::sql($request->modify('+100 milliseconds')), $price, $price + 2]);
    };
    $quote($now->modify('-2 seconds'), 100000);
    $result = $tournament->tick('BTCUSDT', $now);
    check($result['accounts'][$account] === 'ACTIVE' && (int)$db->query('SELECT COUNT(*) FROM quant_trade_intents')->fetchColumn() === 1,
        'Technical strategy schedules a buy without any predictions');
    check($db->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(d.detail_json,'$.horizon')) FROM quant_decisions d
        JOIN quant_tournament_accounts a ON a.id=d.account_id WHERE a.strategy_key='SWING_4H' LIMIT 1")->fetchColumn() === '4h',
        'Swing scheduler preserves 4h target');
    $quote($now->modify('+1 second'), 100000);
    $tournament->tick('BTCUSDT', $now->modify('+1200 milliseconds'));
    check((int)$db->query('SELECT COUNT(*) FROM quant_spot_fills')->fetchColumn() === 0, 'Quote at latency boundary cannot fill');
    $quote($now->modify('+2 seconds'), 100000);
    $db->exec("CREATE TRIGGER quant_fail_fixture BEFORE INSERT ON quant_spot_fills FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='induced rollback'");
    $failed = $tournament->tick('BTCUSDT', $now->modify('+3 seconds'));
    $db->exec('DROP TRIGGER quant_fail_fixture');
    check($failed['accounts'][$account] === 'ERROR' && count(array_filter($failed['accounts'],
        static fn($status): bool => $status === 'ERROR')) === 1
        && $db->query('SELECT status FROM quant_trade_intents')->fetchColumn() === 'PENDING',
        'Fill failure rolls back only its own account');
    $command = [PHP_BINARY, __FILE__, 'tick', $schema, $now->modify('+3 seconds')->format(DATE_ATOM)];
    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        check(proc_close($process) === 0 && (json_decode($output, true)['status'] ?? null) === 'COMPLETED',
            'Concurrent worker completed ' . $error);
    }
    check((int)$db->query('SELECT COUNT(*) FROM quant_spot_fills')->fetchColumn() === 1, 'Concurrent retry produces one fill');
    $quote($now->modify('+130 seconds'), 95000);
    $tournament->tick('BTCUSDT', $now->modify('+131 seconds'));
    check((int)$db->query("SELECT COUNT(*) FROM quant_decisions WHERE reason_code='STOP_LOSS'")->fetchColumn() === 1,
        'Risk tick runs before next technical decision and without predictions');
    check((int)$db->query("SELECT COUNT(*) FROM quant_decisions WHERE reason_code='QUOTE_GAP_WITH_EXPOSURE'")->fetchColumn() === 1,
        'Gap with exposure is visible in the decision audit');
    $quote($now->modify('+134 seconds'), 94900);
    $db->exec("CREATE TRIGGER quant_fail_account_fixture BEFORE UPDATE ON quant_tournament_accounts FOR EACH ROW
        BEGIN IF NEW.id=$account AND NEW.quantity=0 AND OLD.quantity>0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='induced reconciliation error'; END IF; END");
    $exitAt = $now->modify('+135 seconds');
    $tournament->tick('BTCUSDT', $exitAt);
    $db->exec('DROP TRIGGER quant_fail_account_fixture');
    check((int)$db->query('SELECT COUNT(*) FROM quant_spot_fills')->fetchColumn() === 1,
        'Account update failure after the fill statement rolls the fill back');
    $tournament->tick('BTCUSDT', $exitAt);
    for ($i = 3; $i <= 102; $i++) $tournament->tick('BTCUSDT', $now->modify('+' . $i . ' minutes'));
    $ledger = $db->query("SELECT 100+SUM(cash_delta) cash,SUM(quantity_delta) qty,SUM(realized_pnl) pnl
        FROM quant_spot_fills f JOIN quant_trade_intents i ON i.decision_id=f.decision_id WHERE i.account_id=$account")->fetch();
    $state = $db->query("SELECT cash,quantity,realized_pnl FROM quant_tournament_accounts WHERE id=$account")->fetch();
    check($ledger['cash'] === $state['cash'] && $ledger['qty'] === $state['quantity'] && $ledger['pnl'] === $state['realized_pnl']
        && (float)$state['quantity'] === 0.0 && (float)$state['cash'] < 100.0
        && (int)$db->query('SELECT COUNT(*) FROM quant_spot_fills')->fetchColumn() === 2,
        '100 ticks preserve ledger reconciliation and never replay old fills');
    check((float)$db->query("SELECT SUM(cash) FROM quant_tournament_accounts WHERE season_id=$second")->fetchColumn() === 1500.0
        && (float)$db->query('SELECT cash FROM paper_trading_v2_accounts')->fetchColumn() === 98.11,
        'Second season and legacy balances are isolated');

    $model = ['feature_schema_version' => 1, 'market_type' => 'SPOT', 'symbol' => 'BTCUSDT', 'horizon' => '15m',
        'dataset_kind' => 'RECORDED_AS_OF', 'dataset_sha256' => str_repeat('a', 64), 'features' => QuantFeatures::NAMES,
        'training_cutoff_utc' => '2026-06-01T00:00:00Z', 'calibration_cutoff_utc' => '2026-08-01T00:00:00Z',
        'intercept' => 0.25, 'platt_a' => 1.0, 'platt_b' => 0.0];
    foreach (['impute', 'mean', 'weights', 'missing_weights'] as $name) $model[$name] = array_fill(0, 6, 0.0);
    $model['scale'] = array_fill(0, 6, 1.0);
    $model['magnitude_baseline'] = ['up' => ['mean_bps' => 20, 'deciles_bps' => range(1, 11)],
        'down' => ['mean_bps' => -20, 'deciles_bps' => range(-11, -1)]];
    $shadow = new QuantShadowService($db);
    $modelId = $shadow->register(json_encode($model, JSON_THROW_ON_ERROR), $now);
    $shadow->register(json_encode($model, JSON_THROW_ON_ERROR), $now->modify('+1 second'));
    $out = $shadow->predict('BTCUSDT', $now);
    $shadow->predict('BTCUSDT', $now->modify('+3 seconds'));
    $forecast = $out['predictions']['15m'][$modelId];
    check(abs($forecast['probability_up'] - 0.5621765008857981) < 1e-13
        && abs($forecast['expected_return_bps'] - 2.487060035431924) < 1e-12
        && $forecast['return_quantiles_bps']['q10'] < $forecast['return_quantiles_bps']['q50']
        && $forecast['return_quantiles_bps']['q50'] < $forecast['return_quantiles_bps']['q90']
        && (int)$db->query('SELECT COUNT(*) FROM quant_shadow_predictions WHERE quantiles_json IS NOT NULL')->fetchColumn() === 1,
        'PHP shadow probability and snapshot are reproducible and idempotent');
    $record($baseTime->modify('+16 minutes'), 60, 100500);
    $shadow->evaluate($now->modify('+24 minutes'));
    check((int)$db->query('SELECT COUNT(*) FROM quant_shadow_labels')->fetchColumn() === 0, 'Immature label is excluded');
    $shadow->evaluate($now->modify('+26 minutes'));
    $shadow->evaluate($now->modify('+27 minutes'));
    check($db->query('SELECT direction FROM quant_shadow_labels')->fetchColumn() === 'UP'
        && (int)$db->query('SELECT COUNT(*) FROM quant_shadow_labels')->fetchColumn() === 1,
        'Mature label records target lineage exactly once');
    $shadow->retire($modelId, $now->modify('+28 minutes'));
    check($shadow->predict('BTCUSDT', $now->modify('+29 minutes'))['status'] === 'NO_REGISTERED_MODEL'
        && (int)$db->query('SELECT COUNT(*) FROM quant_model_events')->fetchColumn() === 2,
        'Shadow retirement is point-in-time and leaves model history intact');
    $later = $now->modify('+2 hours');
    $laterBase = $baseTime->modify('+2 hours');
    foreach ([60 => 30, 900 => 6] as $duration => $count) {
        for ($i = $count; $i >= 1; $i--) {
            $record($laterBase->modify('-' . ($i * $duration) . ' seconds'), $duration, 100000 - (($i - 1) * 50));
        }
    }
    $resumed = $tournament->start('disposable-c', 'BTCUSDT', $later, $evidence);
    $lateAccount = $db->query("SELECT id FROM quant_tournament_accounts WHERE season_id=$resumed AND strategy_key='MOMENTUM'")->fetchColumn();
    $quote($later->modify('+2 seconds'), 100000);
    $tournament->tick('BTCUSDT', $later->modify('+3 seconds'));
    check($db->query("SELECT status FROM quant_trade_intents WHERE account_id=$lateAccount")->fetchColumn() === 'PENDING',
        'Restart fixture has an entry intent');
    $quote($later->modify('+10 seconds'), 100000);
    $quote($later->modify('+6 minutes'), 100000);
    $tournament->tick('BTCUSDT', $later->modify('+6 minutes 1 second'));
    check($db->query("SELECT status FROM quant_trade_intents WHERE account_id=$lateAccount")->fetchColumn() === 'EXPIRED'
        && (float)$db->query("SELECT cash FROM quant_tournament_accounts WHERE id=$lateAccount")->fetchColumn() === 100.0,
        'Restart expires an entry rather than executing it against an old quote');
    $connection = new ReflectionProperty(Database::class, 'pdo');
    $connection->setAccessible(true);
    $connection->setValue(null, $db);
    $_GET['season'] = (string)$first;
    ob_start();
    require PROMETHEUS_ROOT . '/admin/quant_tournament.php';
    $page = ob_get_clean();
    check(strpos($page, 'disposable-a') !== false && strpos($page, 'MOMENTUM') !== false
        && strpos($page, 'Dados V3 indisponíveis') === false, 'Dashboard renders the disposable season and ranking');
    echo "MariaDB " . $db->query('SELECT VERSION()')->fetchColumn() . " integration complete.\n";
} finally {
    // Only the generated disposable schema can be removed by this test.
    $db->exec("DROP DATABASE `$schema`");
}
