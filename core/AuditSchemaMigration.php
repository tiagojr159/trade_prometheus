<?php
declare(strict_types=1);
namespace Prometheus\core;

use PDO;

/** Additive, restartable repair for installations created before the shadow extensions. */
final class AuditSchemaMigration
{
    public static function apply(PDO $db): array
    {
        $changes = [];
        $columns = [
            'athena50_shadow_predictions' => [
                'regime' => 'VARCHAR(32) NULL',
                'group_ablation_predictions' => 'JSON NULL', 'group_ablation_results' => 'JSON NULL',
                'candidate_predictions' => 'JSON NULL', 'candidate_results' => 'JSON NULL',
            ],
            'paper_trading_v2_orders' => ['risk_event_id' => 'BIGINT UNSIGNED NULL'],
            'prediction_results' => ['target_market_data_id' => 'BIGINT UNSIGNED NULL', 'target_delay_seconds' => 'INT UNSIGNED NULL'],
            'market_data' => ['last_seen_at' => 'DATETIME NULL'],
        ];
        foreach ($columns as $table => $definitions) {
            foreach ($definitions as $column => $definition) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
                $stmt->execute([$table, $column]);
                if (!(int)$stmt->fetchColumn()) {
                    $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                    $changes[] = "$table.$column";
                }
            }
        }
        $db->exec('CREATE TABLE IF NOT EXISTS paper_trading_risk_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, mode VARCHAR(32) NOT NULL,
            market_data_id BIGINT UNSIGNED NOT NULL, entry_prediction_id BIGINT UNSIGNED NOT NULL,
            entry_decision_id BIGINT UNSIGNED NOT NULL, action VARCHAR(24) NOT NULL,
            reason TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_risk_position_candle (mode,market_data_id,entry_prediction_id)
        ) ENGINE=InnoDB');
        $db->exec('CREATE TABLE IF NOT EXISTS prediction_evaluation_gaps (
            prediction_id BIGINT UNSIGNED PRIMARY KEY, target_time DATETIME NOT NULL,
            reason VARCHAR(64) NOT NULL, checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            retry_after DATETIME NOT NULL, KEY idx_gap_retry (retry_after)
        ) ENGINE=InnoDB');
        $db->exec('CREATE TABLE IF NOT EXISTS market_data_revisions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, market_data_id BIGINT UNSIGNED NOT NULL,
            content_hash CHAR(64) NOT NULL, observation_json JSON NOT NULL,
            observed_at DATETIME NOT NULL, UNIQUE KEY uniq_market_revision (market_data_id,content_hash)
        ) ENGINE=InnoDB');
        return $changes;
    }
}
