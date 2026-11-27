<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use Prometheus\core\Database;
use Prometheus\core\Arbitrage\PortfolioLedger;

$db = Database::connection();
$db->beginTransaction();
try {
    $suffix = strtolower(bin2hex(random_bytes(6)));
    $now = gmdate('Y-m-d H:i:s');
    $openedAt = gmdate('Y-m-d H:i:s', time() - 60);
    $details = json_encode(['execution' => ['status' => 'HEDGED']], JSON_THROW_ON_ERROR);
    $q = $db->prepare('INSERT INTO arbitrage_opportunities
        (strategy_key,symbol,action,status,observed_at_utc,gross_profit,costs,net_profit,capital_pct,
         exchange_exposure_pct,data_quality,rejection_reasons_json,details_json)
        VALUES (?,?,?,?,?,0,0,0,0,0,1,?,?)');
    $q->execute(['FUNDING_RATE','BTCUSDT','PAPER_PAIR','OBSERVED',$openedAt,'[]',$details]);
    $opportunityId = (int)$db->lastInsertId();
    $q = $db->prepare('INSERT INTO arbitrage_strategy_portfolios
        (strategy_key,portfolio_key,initial_equity,cash,equity,peak_equity,started_at_utc)
        VALUES (?,?,10000,10000,10000,10000,?)');
    $q->execute(['FUNDING_RATE','integration_'.$suffix,$openedAt]);
    $portfolioId = (int)$db->lastInsertId();
    $ledger = new PortfolioLedger($db);

    $failed = false;
    try {
        $ledger->openSimulatedPositionGroup($portfolioId,$opportunityId,'rollback_'.$suffix,[
            ['leg'=>'spot','venue'=>'VENUE_A','instrument_type'=>'SPOT','side'=>'LONG','quantity'=>0.25,'entry_price'=>1000],
            ['leg'=>'perp','venue'=>'VENUE_B','instrument_type'=>'INVALID','side'=>'SHORT','quantity'=>0.25,'entry_price'=>1000],
        ]);
    } catch (InvalidArgumentException $e) { $failed = true; }
    $q = $db->prepare('SELECT COUNT(*) FROM arbitrage_strategy_positions WHERE portfolio_id=?');
    $q->execute([$portfolioId]);
    if (!$failed || (int)$q->fetchColumn() !== 0) throw new RuntimeException('GROUP_OPEN_DID_NOT_ROLL_BACK');

    $legs = [
        ['leg'=>'spot','venue'=>'VENUE_A','instrument_type'=>'SPOT','side'=>'LONG','quantity'=>0.25,'entry_price'=>1000],
        ['leg'=>'perp','venue'=>'VENUE_B','instrument_type'=>'PERP','side'=>'SHORT','quantity'=>0.25,'entry_price'=>1000],
    ];
    $opened = $ledger->openSimulatedPositionGroup($portfolioId,$opportunityId,'pair_'.$suffix,$legs);
    if ($opened['status'] !== 'OPENED_SIMULATED_GROUP' || count($opened['position_ids']) !== 2) throw new RuntimeException('GROUP_OPEN_FAILED');
    $duplicateOpen = $ledger->openSimulatedPositionGroup($portfolioId,$opportunityId,'pair_'.$suffix,$legs);
    if ($duplicateOpen['status'] !== 'DUPLICATE') throw new RuntimeException('GROUP_OPEN_NOT_IDEMPOTENT');

    $spotId = (int)$opened['position_ids']['spot'];
    $perpId = (int)$opened['position_ids']['perp'];
    $failedPartialClose = false;
    try {
        $ledger->closeSimulatedPositionGroup([
            ['position_id'=>$spotId,'trade_key'=>'close:spot:'.$suffix,'close_price'=>1010,'close_quantity'=>0.1],
            ['position_id'=>$perpId,'trade_key'=>'close:perp:'.$suffix,'close_price'=>990,'close_quantity'=>0.25],
        ],0.001,0,$now);
    } catch (RuntimeException $e) { $failedPartialClose = $e->getMessage()==='POSITION_GROUP_CLOSE_MUST_CLOSE_EACH_LEG'; }
    $q = $db->prepare("SELECT COUNT(*) FROM arbitrage_strategy_positions WHERE portfolio_id=? AND status='OPEN'");
    $q->execute([$portfolioId]);
    if (!$failedPartialClose || (int)$q->fetchColumn() !== 2) throw new RuntimeException('PARTIAL_GROUP_CLOSE_NOT_REJECTED');

    $closures = [
        ['position_id'=>$spotId,'trade_key'=>'close:spot:'.$suffix,'close_price'=>1010,'close_quantity'=>0.25],
        ['position_id'=>$perpId,'trade_key'=>'close:perp:'.$suffix,'close_price'=>990,'close_quantity'=>0.25],
    ];
    $closed = $ledger->closeSimulatedPositionGroup($closures,0.001,0,$now);
    if ($closed['status'] !== 'CLOSED_SIMULATED_GROUP' || count($closed['trades']) !== 2) throw new RuntimeException('GROUP_CLOSE_FAILED');
    $duplicateClose = $ledger->closeSimulatedPositionGroup($closures,0.001,0,$now);
    if ($duplicateClose['status'] !== 'DUPLICATE_GROUP') throw new RuntimeException('GROUP_CLOSE_NOT_IDEMPOTENT');
    $q = $db->prepare("SELECT cash,equity,(SELECT COUNT(*) FROM arbitrage_strategy_positions WHERE portfolio_id=? AND status='OPEN') open_positions
        FROM arbitrage_strategy_portfolios WHERE id=?");
    $q->execute([$portfolioId,$portfolioId]);
    $accounting = $q->fetch(PDO::FETCH_ASSOC);
    if (!$accounting || (int)$accounting['open_positions'] !== 0 || abs((float)$accounting['cash']-(float)$accounting['equity']) > 1e-6)
        throw new RuntimeException('GROUP_LEDGER_EQUITY_DID_NOT_RECONCILE');

    echo "PASS group open rollback, idempotency, all-leg close, duplicate close, and flat equity accounting\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if (isset($portfolioId)) {
        $q = $db->prepare('SELECT COUNT(*) FROM arbitrage_strategy_portfolios WHERE id=?');
        $q->execute([$portfolioId]);
        if ((int)$q->fetchColumn() !== 0) throw new RuntimeException('INTEGRATION_FIXTURE_NOT_ROLLED_BACK');
    }
}
