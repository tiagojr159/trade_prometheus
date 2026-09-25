<?php
declare(strict_types=1);

namespace Prometheus\core;

/**
 * Executa uma lista de tasks de cron isolando falhas:
 * uma task que lança exceção NÃO interrompe as demais.
 */
final class CronRunner
{
    /**
     * @param string[] $tasks ordem de execução garantida
     * @param callable(string $task): mixed $runner
     * @return array<string, mixed> resultado por task ('error' em caso de falha)
     */
    public static function runAll(array $tasks, callable $runner): array
    {
        $results = [];
        foreach ($tasks as $task) {
            $started = microtime(true);
            try {
                $results[$task] = $runner($task);
                Logger::info('cron_task_completed', [
                    'task' => $task,
                    'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                    'result' => $results[$task],
                ]);
            } catch (\Throwable $e) {
                $results[$task] = ['error' => $e->getMessage()];
                Logger::error('cron_task_failed', [
                    'task' => $task,
                    'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                    'message' => $e->getMessage(),
                ]);
            }
        }
        return $results;
    }
}
