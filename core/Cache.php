<?php
declare(strict_types=1);

namespace Prometheus\core;

final class Cache
{
    public static function get(string $key)
    {
        $file = self::file($key);
        if (!is_file($file)) {
            return null;
        }
        $payload = json_decode((string)file_get_contents($file), true);
        if (!$payload || ($payload['expires_at'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }
        return $payload['value'] ?? null;
    }

    public static function set(string $key, $value, int $ttl): void
    {
        file_put_contents(self::file($key), json_encode([
            'expires_at' => time() + $ttl,
            'value' => $value,
        ], JSON_UNESCAPED_UNICODE));
    }

    private static function file(string $key): string
    {
        return PROMETHEUS_CACHE_DIR . '/' . hash('sha256', $key) . '.json';
    }
}
