<?php
declare(strict_types=1);

/**
 * PROMETHEUS bootstrap — idempotente.
 * Pode ser requerido múltiplas vezes (require ou require_once, em qualquer ordem),
 * inclusive via entrypoints web e CLI, sem erros, sem registrar autoloader
 * duplicado e sem recarregar configuração.
 */

// Guarda primária via constante (cobre require_once e require repetidos no mesmo processo).
if (defined('PROMETHEUS_BOOTSTRAPPED')) {
    return;
}
define('PROMETHEUS_BOOTSTRAPPED', true);

// Etapa 01 — guarda de ambiente: falha CLARA em versão/extensão incompatível.
if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://stderr', 'w'), "PROMETHEUS requer PHP >= 7.4 (encontrado: " . PHP_VERSION . ")\n");
    exit(1);
}
foreach (['pdo_mysql', 'curl', 'openssl', 'mbstring', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://stderr', 'w'), "PROMETHEUS requer extensão PHP '$ext' (não carregada)\n");
        exit(1);
    }
}

// Guarda secundária via função (protege contra include de cópias do arquivo em caminhos diferentes).
if (function_exists('prometheus_bootstrap')) {
    return;
}

if (!function_exists('prometheus_bootstrap')) {
    /**
     * Carrega config/config.php uma única vez e armazena em $GLOBALS.
     * O guard function_exists() acima já garante execução única; este bloco
     * revalida porque algumas builds PHP/Windows relatam falso-positivo de
     * "redeclare" por stat cache ao incluir o MESMO arquivo duas vezes.
     */
    function prometheus_bootstrap(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $config = require __DIR__ . '/config/config.php';
        $GLOBALS['PROMETHEUS_CONFIG'] = is_array($config) ? $config : [];

        spl_autoload_register(static function (string $class): void {
            $prefix = 'Prometheus\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        }, true, false);

        foreach ([PROMETHEUS_CACHE_DIR, PROMETHEUS_LOG_DIR, PROMETHEUS_ROOT . '/storage/locks'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }
}

if (!function_exists('prometheus_config')) {
    /**
     * Acesso de leitura centralizado à configuração.
     * Nunca recarrega config/config.php: se o bootstrap ainda não rodou,
     * retorna o default. Suporta notação pontilhada ("collector.api_timeout").
     */
    function prometheus_config(string $key = null, $default = null)
    {
        $config = $GLOBALS['PROMETHEUS_CONFIG'] ?? [];
        if ($key === null) {
            return $config;
        }
        $value = $config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}

prometheus_bootstrap();

return true;
