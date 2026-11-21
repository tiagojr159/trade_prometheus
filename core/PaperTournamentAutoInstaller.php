<?php
declare(strict_types=1);

namespace Prometheus\core;

use PDO;
use RuntimeException;

/** Applies tournament upgrades once, on the first authenticated visit to its admin screen. */
final class PaperTournamentAutoInstaller
{
    private const LOCK_NAME = 'prometheus_paper_tournament_install';
    private const MIGRATIONS = [
        '013_paper_strategy_tournament.sql',
        '014_paper_tournament_allow_sample_orders.sql',
        '015_paper_trading_twelve_strategies.sql',
        '016_paper_heartbeat_waiting_data.sql',
        '017_paper_swing_4h.sql',
        '018_leveraged_strategies_10_27.sql',
    ];

    public static function ensureInstalled(): bool
    {
        $pdo = Database::connection();
        if (self::isInstalled($pdo)) return false;
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 15)');
        $lock->execute([self::LOCK_NAME]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Instalacao automatica do torneio ocupada; tente atualizar a pagina em instantes.');
        try {
            if (self::isInstalled($pdo)) return false;
            self::assertPrerequisites($pdo);
            self::backup($pdo);
            foreach (self::MIGRATIONS as $file) self::applyMigration($pdo, $file);
            if (!self::isInstalled($pdo)) throw new RuntimeException('As migrations foram executadas, mas a verificacao das contas do torneio falhou.');
            return true;
        } finally {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote(self::LOCK_NAME) . ')');
        }
    }

    private static function isInstalled(PDO $pdo): bool
    {
        $tables = self::tables($pdo);
        foreach (['paper_trading_config','paper_trading_v2_accounts','paper_trading_decisions','paper_trading_v2_orders','paper_trading_v2_trades','paper_trading_equity','paper_trading_heartbeat'] as $table) {
            if (!isset($tables[$table])) return false;
        }
        $column = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='paper_trading_config' AND COLUMN_NAME='leverage'")->fetchColumn();
        if ((int)$column !== 1) return false;
        $expected = array_keys(PaperStrategyEngine::modes());
        $modes = $pdo->query('SELECT mode FROM paper_trading_config')->fetchAll(PDO::FETCH_COLUMN);
        if (array_diff($expected, $modes)) return false;
        $accounts = $pdo->query('SELECT mode FROM paper_trading_v2_accounts')->fetchAll(PDO::FETCH_COLUMN);
        $heartbeats = $pdo->query('SELECT mode FROM paper_trading_heartbeat')->fetchAll(PDO::FETCH_COLUMN);
        return !array_diff($expected, $accounts) && !array_diff($expected, $heartbeats);
    }

    private static function assertPrerequisites(PDO $pdo): void
    {
        $tables = self::tables($pdo);
        $required = ['paper_trading_config','paper_trading_v2_accounts','paper_trading_decisions','paper_trading_v2_orders','paper_trading_v2_trades','paper_trading_equity','paper_trading_heartbeat'];
        $missing = array_values(array_diff($required, array_keys($tables)));
        if ($missing) throw new RuntimeException('Instalacao automatica nao aplicada: faltam tabelas-base (' . implode(', ', $missing) . '). Aplique primeiro as migrations-base do simulador.');
    }

    private static function tables(PDO $pdo): array
    {
        return array_fill_keys($pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN), true);
    }

    private static function backup(PDO $pdo): void
    {
        $tables = ['paper_trading_config','paper_trading_v2_accounts','paper_trading_decisions','paper_trading_v2_orders','paper_trading_v2_trades','paper_trading_equity','paper_trading_heartbeat'];
        $backup = ['created_at'=>date(DATE_ATOM),'database'=>(string)$pdo->query('SELECT DATABASE()')->fetchColumn(),'tables'=>[]];
        foreach ($tables as $table) {
            $backup['tables'][$table]['schema'] = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
            if (in_array($table, ['paper_trading_config','paper_trading_v2_accounts','paper_trading_heartbeat'], true)) {
                $backup['tables'][$table]['rows'] = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $backup['tables'][$table]['row_count'] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            }
        }
        $dir = PROMETHEUS_ROOT . '/storage/backups';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Nao foi possivel criar storage/backups; torneio nao alterado.');
        $path = $dir . '/paper_tournament_auto_' . date('Ymd_His') . '.json';
        $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) === false) throw new RuntimeException('Backup automatico falhou; torneio nao alterado.');
    }

    private static function applyMigration(PDO $pdo, string $file): void
    {
        $path = PROMETHEUS_ROOT . '/sql/migrations/' . $file;
        $sql = file_get_contents($path);
        if ($sql === false) throw new RuntimeException('Migration ausente: ' . $file);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $statement) $pdo->exec($statement);
    }
}
