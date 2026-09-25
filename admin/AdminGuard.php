<?php
declare(strict_types=1);

namespace Prometheus\admin;

use Prometheus\core\Logger;

/**
 * ADMIN GUARD (Item 24).
 *
 * As páginas administrativas (dashboard, settings, etc.) só são acessíveis
 * com o token de ambiente PROMETHEUS_ADMIN_TOKEN configurado e fornecido
 * via cookie de sessão autenticado (login em admin/login.php).
 *
 * Se PROMETHEUS_ADMIN_TOKEN NÃO estiver configurado:
 *  - em ambiente de desenvolvimento (PROMETHEUS_ENV=development) o acesso
 *    é permitido APENAS de loopback (127.0.0.1 / ::1) — padrão XAMPP local;
 *  - em produção o acesso é negado (403).
 */
final class AdminGuard
{
    public static function requireAdmin(): void
    {
        // CLI (probes de teste, tarefas agendadas): sem sessão HTTP, passa direto.
        if (PHP_SAPI === 'cli') {
            return;
        }
        self::secureHeaders();
        self::hardenSession();

        $token = getenv('PROMETHEUS_ADMIN_TOKEN') ?: '';
        $env = PROMETHEUS_ENV;

        // Já autenticado nesta sessão?
        if (!empty($_SESSION['prometheus_admin_ok'])) {
            return;
        }

// Login via token (POST) — proteção CSRF + comparação timing-safe.
if ($token !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $posted = (string)($_POST['admin_token'] ?? '');
    $csrfOk = isset($_POST['csrf'], $_SESSION['login_csrf']) && hash_equals((string)$_SESSION['login_csrf'], (string)$_POST['csrf']);
    if ($csrfOk && $posted !== '' && hash_equals($token, $posted)) {
        $_SESSION['prometheus_admin_ok'] = true;
        unset($_SESSION['login_csrf']);
        session_regenerate_id(true);
        return;
    }
    Logger::warning('admin_login_failed', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
}

        // Dev local sem token configurado: permite apenas loopback.
        if ($token === '' && $env === 'development') {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if (in_array($ip, ['127.0.0.1', '::1'], true)) {
                return;
            }
        }

        http_response_code(403);
        $loginPath = __DIR__ . '/login.php';
        if ($token !== '' && is_file($loginPath)) {
            header('Location: login.php');
            exit;
        }
        exit('Acesso restrito.');
    }

    public static function secureHeaders(): void
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: no-referrer');
            header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        }
    }

    private static function hardenSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'),
            ]);
            session_start();
        }
    }
}
