<?php
declare(strict_types=1);

namespace Prometheus\core;

/**
 * LOGGER (Item 2) — logging resiliente, sem dependência circular com o banco.
 *
 * Fluxo garantido:
 *
 *   Database funciona  →  Logger pode usar o banco normalmente
 *   Database falha     →  Logger detecta a impossibilidade
 *                      →  grava em storage/logs/prometheus.log
 *                      →  encerra (NUNCA Database → Logger → Database → ...)
 *
 * Proteções implementadas:
 *  1. ARQUIVO PRIMEIRO: todo log é gravado em arquivo ANTES de qualquer
 *     tentativa de persistência no banco. O arquivo é o caminho primário;
 *     o banco é um complemento best-effort.
 *  2. REENTRÂNCIA: enquanto uma tentativa de log-em-banco estiver em curso,
 *     qualquer chamada aninhada ao Logger pula o banco (só arquivo) —
 *     impossível reconstruir o ciclo Database → Logger → Database.
 *  3. COOLDOWN: após uma falha de log-em-banco, novas tentativas ficam
 *     suspensas por DB_COOLDOWN_SECONDS. Falhas consecutivas não martelam
 *     o banco (que pode estar caindo por timeout lento) e mantêm a memória
 *     estável.
 *  4. NUNCA LANÇA: nenhuma falha de logging (arquivo ou banco) propaga
 *     exceção — uma falha de log não pode provocar outra falha no sistema.
 *  5. O ERRO ORIGINAL NÃO É SILENCIADO: quando o banco falha, o próprio
 *     Database registra o motivo em arquivo via databaseError() (que é
 *     independente de banco). O diagnóstico completo permanece disponível.
 *  6. Segredos (password/token/api_key/secret/authorization) são redacionados
 *     por sanitizeContext() antes de ir para arquivo ou banco.
 *
 * Compatível com PHP 7.4.
 */
final class Logger
{
    /** Janela em que o log-em-banco fica suspenso após uma falha. */
    private const DB_COOLDOWN_SECONDS = 30;

    /** Guard de reentrância: true enquanto uma tentativa de log em banco está em curso. */
    private static bool $writingToDatabase = false;

    /** Timestamp (time()) até o qual o log-em-banco fica suspenso. Null = habilitado. */
    private static ?int $dbDisabledUntil = null;

    public static function info(string $event, array $context = []): void
    {
        self::write('INFO', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::write('ERROR', $event, $context);
    }

    public static function warning(string $event, array $context = []): void
    {
        self::write('WARNING', $event, $context);
    }

    /**
     * Caminho usado PELO DATABASE para reportar suas próprias falhas.
     * É INDEPENDENTE de banco por definição: grava somente em arquivo.
     * Database nunca depende de um logger que precise do banco.
     */
    public static function databaseError(string $event, array $context = []): void
    {
        self::writeFile('ERROR', $event, $context);
    }

    private static function write(string $level, string $event, array $context): void
    {
        // 1. Arquivo primeiro: logging nunca depende do banco.
        self::writeFile($level, $event, $context);

        // 2. Reentrância: chamada aninhada durante tentativa de log-em-banco
        //    pula o banco (o arquivo já foi gravado acima).
        if (self::$writingToDatabase) {
            return;
        }

        // 3. Cooldown pós-falha: banco recém-indisponível não é re-martelado.
        //    (Cada tentativa com o banco caindo por timeout é lenta e soma memória.)
        if (self::$dbDisabledUntil !== null && time() < self::$dbDisabledUntil) {
            return;
        }

        self::$writingToDatabase = true;
        try {
            Database::execute(
                'INSERT INTO system_logs (level, event, context, created_at) VALUES (?, ?, ?, NOW())',
                [$level, $event, json_encode(self::sanitizeContext($context), JSON_UNESCAPED_UNICODE)]
            );
            // Sucesso: reabilita o banco para os próximos logs.
            self::$dbDisabledUntil = null;
        } catch (\Throwable $e) {
            // Banco indisponível: suspende tentativas pelo cooldown.
            // O erro original do banco JÁ foi registrado em arquivo por
            // Database::logOperationFailure → databaseError (não silenciamos).
            // Aqui registramos também o motivo da falha de log-em-banco (file-only,
            // sem qualquer chance de recursão).
            self::$dbDisabledUntil = time() + self::DB_COOLDOWN_SECONDS;
            self::writeFile('WARNING', 'log_to_database_failed', ['message' => $e->getMessage()]);
        } finally {
            self::$writingToDatabase = false;
        }
    }

    /** Gravação em arquivo: nunca lança, nunca chama o Logger recursivamente. */
    private static function writeFile(string $level, string $event, array $context): void
    {
        $line = json_encode([
            'time' => date('c'),
            'level' => $level,
            'event' => $event,
            'context' => self::sanitizeContext($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

        try {
            if (!is_dir(PROMETHEUS_LOG_DIR)) {
                @mkdir(PROMETHEUS_LOG_DIR, 0775, true);
            }
            @file_put_contents(PROMETHEUS_LOG_DIR . '/prometheus.log', $line, FILE_APPEND);
            @file_put_contents(PROMETHEUS_LOG_DIR . '/prometheus-' . date('Y-m-d') . '.log', $line, FILE_APPEND);
        } catch (\Throwable $ignored) {
            // Falha de disco não pode derrubar a aplicação (regra 5/4).
        }
    }

    /** Remove credenciais/tokens/chaves antes de qualquer persistência. */
    private static function sanitizeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string)$key);
            if (
                strpos($lower, 'password') !== false ||
                strpos($lower, 'passwd') !== false ||
                strpos($lower, 'secret') !== false ||
                strpos($lower, 'token') !== false ||
                strpos($lower, 'api_key') !== false ||
                strpos($lower, 'authorization') !== false
            ) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = self::sanitizeContext($value);
                continue;
            }
            $safe[$key] = $value;
        }
        return $safe;
    }
}
