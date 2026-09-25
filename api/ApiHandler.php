<?php
declare(strict_types=1);

namespace Prometheus\api;

use Prometheus\core\Database;
use Prometheus\core\Logger;

/**
 * Handler comum da API (Itens 23/24).
 * - Rate limiting por IP (janela fixa de 1 min, via banco);
 * - Respostas JSON com código HTTP correto;
 * - NUNCA expõe stack trace, SQL, credenciais ou paths internos;
 * - Validação básica de entrada (limit, symbol, horizonte).
 */
final class ApiHandler
{
    private const RATE_LIMIT_PER_MIN = 60;

    public static function handle(callable $fn, array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            self::json(405, ['error' => 'method_not_allowed']);
            return;
        }

        if (!self::rateLimitOk()) {
            header('Retry-After: 60');
            self::json(429, ['error' => 'rate_limited', 'message' => 'Limite de 60 requisições por minuto excedido.']);
            return;
        }

        try {
            $data = $fn($params);
            self::json(200, $data);
        } catch (\InvalidArgumentException $e) {
            self::json(400, ['error' => 'invalid_input', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Logger::error('api_internal_error', ['message' => $e->getMessage(), 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
            // Mensagem genérica: nenhum detalhe interno vaza para o cliente.
            self::json(500, ['error' => 'internal_error', 'message' => 'Erro interno. Consulte os logs do sistema.']);
        }
    }

    /** Limit de paginação saneado: inteiro em [1, 500]. */
    public static function sanitizeLimit($raw, int $default = 50, int $max = 500): int
    {
        $n = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['default' => $default, 'min_range' => 1, 'max_range' => $max]]);
        return $n === false ? $default : (int)$n;
    }

    /** Symbol validado contra whitelist de formato (ex.: BTCUSDT). */
    public static function sanitizeSymbol($raw, string $default = 'BTCUSDT'): string
    {
        $s = is_string($raw) ? strtoupper(trim($raw)) : $default;
        if (!preg_match('/^[A-Z0-9]{4,20}$/', $s)) {
            throw new \InvalidArgumentException('invalid symbol format');
        }
        return $s;
    }

    private static function rateLimitOk(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        try {
            Database::execute('DELETE FROM api_rate_limit WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
            $row = Database::fetch('SELECT hits FROM api_rate_limit WHERE ip = ?', [$ip]);
            if (!$row) {
                Database::execute('INSERT INTO api_rate_limit (ip, hits, window_start) VALUES (?, 1, NOW())', [$ip]);
                return true;
            }
            if ((int)$row['hits'] >= self::RATE_LIMIT_PER_MIN) {
                return false;
            }
            Database::execute('UPDATE api_rate_limit SET hits = hits + 1 WHERE ip = ?', [$ip]);
            return true;
        } catch (\Throwable $e) {
            // Se a tabela não existir, não bloqueia o tráfego — mas loga.
            Logger::warning('rate_limit_storage_unavailable', ['message' => $e->getMessage()]);
            return true;
        }
    }

    private static function json(int $status, array $payload): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
