<?php
declare(strict_types=1);

namespace Prometheus\core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    /** Backoff de reconexão: evita martelar servidor offline a cada operação. */
    private const CONN_RETRY_SECONDS = 5;
    private static ?int $lastConnFailure = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        // Falha recente: não re-tenta conectar agora (falha rápida, controlada).
        if (self::$lastConnFailure !== null && (time() - self::$lastConnFailure) < self::CONN_RETRY_SECONDS) {
            throw new PDOException('database unavailable (connection failed recently; retry suppressed for ' . self::CONN_RETRY_SECONDS . 's)');
        }
        $cfg = require PROMETHEUS_ROOT . '/config/database.php';
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']);
        try {
            self::$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 3, // falha rápida se o servidor estiver offline
            ]);
            self::$lastConnFailure = null;
            return self::$pdo;
        } catch (PDOException $e) {
            self::$lastConnFailure = time();
            Logger::databaseError('database_connection_failed', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        try {
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row === false ? null : $row;
        } catch (\Throwable $e) {
            self::logOperationFailure('database_fetch_failed', $sql, $e);
            throw $e;
        }
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        try {
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            self::logOperationFailure('database_fetch_all_failed', $sql, $e);
            throw $e;
        }
    }

    public static function execute(string $sql, array $params = []): int
    {
        try {
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            self::logOperationFailure('database_execute_failed', $sql, $e);
            throw $e;
        }
    }

    public static function insert(string $sql, array $params = []): int
    {
        try {
            $stmt = self::connection()->prepare($sql);
            $stmt->execute($params);
            return (int)self::connection()->lastInsertId();
        } catch (\Throwable $e) {
            self::logOperationFailure('database_insert_failed', $sql, $e);
            throw $e;
        }
    }

    private static function logOperationFailure(string $event, string $sql, \Throwable $e): void
    {
        Logger::databaseError($event, [
            'message' => $e->getMessage(),
            'sql_hash' => hash('sha256', $sql),
        ]);
    }
}
