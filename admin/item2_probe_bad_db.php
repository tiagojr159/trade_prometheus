<?php
declare(strict_types=1);
// Probe: banco propositalmente inválido via env ANTES do bootstrap.
// A config/database.php lê getenv() no carregamento, então DB_PORT=1
// faz a conexão falhar rápido (porta recusada, sem timeout longo).
putenv('DB_PORT=1');
putenv('DB_HOST=127.0.0.1');
chdir(dirname(__DIR__));
require 'bootstrap.php';

$memStart = memory_get_usage();
try {
    Prometheus\core\Database::fetch('SELECT 1 AS one');
    echo 'NO_ERROR';
} catch (Throwable $e) {
    echo 'CAUGHT:' . substr(md5($e->getMessage()), 0, 8);
    // TESTE D real: 30 ciclos Database-falha + Logger com o banco MORTO.
    // Se houver recursão/exhaustion, o subprocesso morre antes do MEM final.
    $peakStart = memory_get_peak_usage();
    for ($i = 0; $i < 30; $i++) {
        try {
            Prometheus\core\Database::fetch('SELECT 1');
        } catch (Throwable $ignored) {
            // esperado: banco indisponível
        }
        Prometheus\core\Logger::error('probe_loop_iteration', ['i' => $i]);
    }
    $growth = memory_get_usage() - $memStart;
    $peakGrowth = memory_get_peak_usage() - $peakStart;
    echo '|LOOP30_OK|MEM_GROWTH:' . $growth . '|PEAK_GROWTH:' . $peakGrowth;
}
