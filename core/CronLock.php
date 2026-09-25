<?php
declare(strict_types=1);

namespace Prometheus\core;

final class CronLock
{
    private string $name;
    private string $file;
    private $handle = null;

    public function __construct(string $name)
    {
        $this->name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) ?: 'default';
        $dir = PROMETHEUS_ROOT . '/storage/locks';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->file = $dir . '/' . $this->name . '.lock';
    }

    public function acquire(): bool
    {
        $this->handle = @fopen($this->file, 'c+');
        if (!$this->handle) {
            Logger::error('cron_lock_open_failed', ['lock' => $this->name]);
            return false;
        }
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            return false;
        }
        ftruncate($this->handle, 0);
        fwrite($this->handle, json_encode([
            'pid' => getmypid(),
            'started_at' => date('c'),
            'lock' => $this->name,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        fflush($this->handle);
        return true;
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }
}
