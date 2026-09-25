<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/sql/prometheus.sql') ?: '';
$v2 = file_get_contents($root . '/evaluation/HistoricalPipelineBacktester.php') ?: '';
$wf = file_get_contents($root . '/evaluation/WalkForwardBacktester.php') ?: '';
$metric = file_get_contents($root . '/evaluation/OutOfSampleEvaluator.php') ?: '';
$checks = [
    'fresh install DEFAULT 2' => (bool)preg_match('/CREATE TABLE IF NOT EXISTS backtest_results[\s\S]*?evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 2/', $schema),
    'older results migration default 2' => (bool)preg_match('/evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 2/', file_get_contents($root . '/sql/migrations/002_backtest_results.sql') ?: ''),
    'upgrade migration 009 sets future default 2 only' => (bool)preg_match('/MODIFY evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 2/', file_get_contents($root . '/sql/migrations/009_backtest_evaluation_default_v2.sql') ?: '') && !preg_match('/UPDATE\s+backtest_results/i', file_get_contents($root . '/sql/migrations/009_backtest_evaluation_default_v2.sql') ?: ''),
    'official historical INSERT explicitly stores v2' => (bool)preg_match('/INSERT INTO backtest_results[\s\S]*?VALUES[^;]*?,\s*2,\s*NOW\(\)/', $v2),
    'walk-forward INSERT explicitly stores v2' => (bool)preg_match('/INSERT INTO backtest_results[\s\S]*?VALUES[^;]*?,\s*2,\s*NOW\(\)/', $wf),
    'official metrics explicitly scope evaluation_version=2' => strpos($metric, 'r.evaluation_version = 2') !== false,
    'legacy rows are not globally upgraded by migration 007' => !preg_match('/UPDATE\s+backtest_results\s+SET\s+evaluation_version\s*=\s*2/i', file_get_contents($root . '/sql/migrations/007_direction_policy_v2_hardening.sql') ?: ''),
    'migration 007 adds its column to unversioned legacy rows as v1' => (bool)preg_match('/ADD COLUMN IF NOT EXISTS evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 1/', file_get_contents($root . '/sql/migrations/007_direction_policy_v2_hardening.sql') ?: ''),
    'migration 007 keeps SIDEWAYS rows at v1' => (bool)preg_match('/UPDATE\s+backtest_results\s+SET\s+evaluation_version\s*=\s*1\s+WHERE\s+predicted_direction\s*=\s*\'SIDEWAYS\'\s+OR\s+actual_direction\s*=\s*\'SIDEWAYS\'/i', file_get_contents($root . '/sql/migrations/007_direction_policy_v2_hardening.sql') ?: ''),
];
$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$ok) $failed++;
}
echo 'Evaluation schema checks: ' . (count($checks) - $failed) . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
