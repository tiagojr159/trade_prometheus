<?php
declare(strict_types=1);

namespace Prometheus\admin;

use Prometheus\core\Logger;
use Prometheus\core\Database;

/** Protects administrative pages with a configured credential or admin session. */
final class AdminGuard
{
    public static function requireAdmin(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        self::secureHeaders();
        self::hardenSession();

        if (!empty($_SESSION['prometheus_admin_ok'])) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $csrfOk = isset($_POST['csrf'], $_SESSION['login_csrf'])
                && hash_equals((string) $_SESSION['login_csrf'], (string) $_POST['csrf']);
            $postedUser = trim((string) ($_POST['admin_user'] ?? ''));
            $postedPassword = (string) ($_POST['admin_password'] ?? '');
            $databaseValid = false;
            if ($postedUser !== '' && $postedPassword !== '') {
                try {
                    $admin = Database::fetch('SELECT username, password_hash FROM admin_users WHERE username = ? AND active = 1 LIMIT 1', [$postedUser]);
                    $databaseValid = $admin !== null && password_verify($postedPassword, (string) $admin['password_hash']);
                } catch (\Throwable $e) {
                    // Missing table or unavailable DB denies login; legacy credentials remain available during migration.
                    Logger::warning('admin_database_auth_unavailable', ['message' => $e->getMessage()]);
                }
            }

            [$user, $passwordHash] = self::credentials();

            // Retain support for deployments that still use the legacy token.
            $legacyToken = getenv('PROMETHEUS_ADMIN_TOKEN') ?: '';
            $postedToken = (string) ($_POST['admin_token'] ?? '');
            $legacyValid = $legacyToken !== '' && $postedToken !== '' && hash_equals($legacyToken, $postedToken);
            $credentialsValid = $user !== '' && $passwordHash !== ''
                && hash_equals($user, $postedUser)
                && password_verify($postedPassword, $passwordHash);

            if ($csrfOk && ($databaseValid || $credentialsValid || $legacyValid)) {
                session_regenerate_id(true);
                $_SESSION['prometheus_admin_ok'] = true;
                $_SESSION['prometheus_admin_user'] = $databaseValid ? $postedUser : ($credentialsValid ? $user : 'admin');
                unset($_SESSION['login_csrf']);
                return;
            }

            Logger::warning('admin_login_failed', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            $_SESSION['login_error'] = 'Usuário ou senha inválidos.';
            header('Location: login.php', true, 303);
            exit;
        }

        header('Location: ' . self::loginUrl(), true, 302);
        exit;
    }

    public static function secureHeaders(): void
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: no-referrer');
            header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
            header('Cache-Control: no-store, private');
        }
    }

    public static function loginUrl(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $adminPosition = strpos($script, '/admin/');
        $base = $adminPosition === false
            ? rtrim(dirname($script), '/.')
            : substr($script, 0, $adminPosition);
        return ($base === '' ? '' : $base) . '/admin/login.php';
    }

    /** @return array{0:string,1:string} */
    public static function credentials(): array
    {
        $user = getenv('PROMETHEUS_ADMIN_USER') ?: '';
        $passwordHash = getenv('PROMETHEUS_ADMIN_PASSWORD_HASH') ?: '';
        if ($user !== '' && $passwordHash !== '') {
            return [$user, $passwordHash];
        }

        $file = dirname(__DIR__) . '/config/admin_credentials.php';
        if (is_file($file)) {
            $stored = require $file;
            if (is_array($stored)) {
                return [(string) ($stored['username'] ?? ''), (string) ($stored['password_hash'] ?? '')];
            }
        }
        return ['', ''];
    }

    public static function databaseAdminConfigured(): bool
    {
        try {
            return (int) (Database::fetch('SELECT COUNT(*) AS total FROM admin_users WHERE active = 1')['total'] ?? 0) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function hardenSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            ]);
            session_start();
        }
    }
}
