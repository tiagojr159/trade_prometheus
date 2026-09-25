<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\AsOfTime;
use Prometheus\core\TemporalAvailability;

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$check('A FRED future exact release is excluded', !TemporalAvailability::isEligible('2026-09-01 00:00:00', '2026-09-15 15:30:00', '2026-09-15 15:31:00', '2026-09-15 12:00:00', 'EXACT'));
$check('B FRED known release and ingestion before AsOf are eligible', TemporalAvailability::isEligible('2026-09-01 00:00:00', '2026-09-15 14:30:00', '2026-09-15 14:31:00', '2026-09-15 12:00:00', 'EXACT'));
$check('published-time proxy later than AsOf is excluded even with ingestion-only quality', !TemporalAvailability::isEligible('2026-09-15 10:00:00', '2026-09-15 13:30:00', '2026-09-15 13:02:00', '2026-09-15 10:03:00', 'INGESTION_ONLY'));
$check('snapshot ingested before stated candle close is not final/eligible', !TemporalAvailability::isEligible('2026-09-15 10:00:00', '2026-09-15 13:00:00', '2026-09-15 12:59:00', '2026-09-15 10:03:00', 'INGESTION_ONLY'));
$check('C future ingestion is excluded when availability is unknown', !TemporalAvailability::isEligible('2026-09-01 00:00:00', null, '2026-09-15 15:01:00', '2026-09-15 12:00:00', 'INGESTION_ONLY'));
$check('D news published 10:00 and ingested 10:02 is invisible at 09:59', !TemporalAvailability::isEligible('2026-09-15 10:00:00', '2026-09-15 13:00:00', '2026-09-15 13:02:00', '2026-09-15 09:59:00', 'INGESTION_ONLY'));
$check('D news published 10:00 and ingested 10:02 is eligible at 10:03', TemporalAvailability::isEligible('2026-09-15 10:00:00', '2026-09-15 13:00:00', '2026-09-15 13:02:00', '2026-09-15 10:03:00', 'INGESTION_ONLY'));
$check('E Coin Metrics observation from D ingested D+1 is excluded on D', !TemporalAvailability::isEligible('2026-09-15 00:00:00', null, '2026-09-16 00:02:00', '2026-09-15 10:00:00', 'INGESTION_ONLY'));
$check('F live ingestion-only row is eligible after it was received', TemporalAvailability::isEligible('2026-09-15 10:00:00', null, '2026-09-15 10:02:00', '2026-09-15 10:03:00', 'INGESTION_ONLY', true));
$check('G historical UNKNOWN row is always excluded', !TemporalAvailability::isEligible('2026-09-15 09:00:00', null, null, '2026-09-15 10:00:00', 'UNKNOWN'));

$schema = file_get_contents(dirname(__DIR__) . '/sql/prometheus.sql') ?: '';
$check('fresh schema backtest evaluation_version defaults to 2', (bool)preg_match('/CREATE TABLE IF NOT EXISTS backtest_results[\s\S]*?evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 2/', $schema));
$migration = file_get_contents(dirname(__DIR__) . '/sql/migrations/008_temporal_lineage.sql') ?: '';
$check('temporal migration does not backfill with NOW or observation time', !preg_match('/UPDATE\s+(macro_data|news|onchain_data|derivatives_data|market_data)[\s\S]*?(NOW\(\)|observed_at|published_at)/i', $migration));
$check('AsOfTime emits UTC lineage cutoff in live mode', AsOfTime::sqlUtcUpperBound('ingested_at') === 'ingested_at <= UTC_TIMESTAMP()');
AsOfTime::set('2026-09-15 12:00:00');
$historyCutoff = AsOfTime::sqlUtcUpperBound('ingested_at');
AsOfTime::clear();
$check('AsOfTime converts the historical application cutoff to UTC', $historyCutoff === "ingested_at <= '2026-09-15 15:00:00'");

echo "Temporal hardening: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
