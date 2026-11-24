<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/bootstrap.php';

use Prometheus\core\CronLock;
use Prometheus\core\Database;
use Prometheus\core\QuantClock;
use Prometheus\core\QuantTournament;
use Prometheus\prediction\QuantShadowService;

$command = $argv[1] ?? '';
if (!in_array($command, ['schema', 'start-season', 'register-shadow', 'retire-shadow'], true)) {
    fwrite(STDERR, "Uso: php deploy/quant_v3.php schema | start-season TEMPORADA [EVIDENCIA.json] | register-shadow MODELO.json | retire-shadow ID\n");
    exit(2);
}
$lock = new CronLock('cron_global');
if (!$lock->acquire()) {
    fwrite(STDERR, "Cron ou manutenção em execução.\n");
    exit(1);
}
$exitCode = 0;
try {
    $db = Database::connection();
    if ($command === 'schema') {
        $lockName = 'quant_v3_schema:' . sha1((string)$db->query('SELECT DATABASE()')->fetchColumn());
        $stmt = $db->prepare('SELECT GET_LOCK(?,5)');
        $stmt->execute([$lockName]);
        if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('SCHEMA_LOCK_UNAVAILABLE');
        try {
            foreach (['019_quant_v3_tournament.sql', '020_quant_v3_shadow.sql'] as $name) {
                $sql = file_get_contents(PROMETHEUS_ROOT . '/sql/migrations/' . $name);
                if ($sql === false) throw new RuntimeException('MIGRATION_UNREADABLE');
                foreach (explode(';', $sql) as $statement) {
                    if (trim($statement) !== '') $db->exec($statement);
                }
            }
        } finally {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
        echo "Schema V3 disponível.\n";
    } elseif ($command === 'start-season') {
        $evidence = isset($argv[3]) ? json_decode(readQuantInput($argv[3]), true, 16, JSON_THROW_ON_ERROR) : [];
        $id = (new QuantTournament($db))->start($argv[2] ?? '', 'BTCUSDT', QuantClock::now(), $evidence);
        echo "Temporada V3 #$id disponível.\n";
    } elseif ($command === 'register-shadow') {
        $id = (new QuantShadowService($db))->register(readQuantInput($argv[2] ?? ''), QuantClock::now());
        echo "Modelo shadow $id disponível.\n";
    } else {
        (new QuantShadowService($db))->retire($argv[2] ?? '', QuantClock::now());
        echo "Retirada de shadow solicitada.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Falha V3 (' . get_class($error) . "). Verifique o schema e os argumentos.\n");
    $exitCode = 1;
} finally {
    $lock->release();
}
exit($exitCode);

function readQuantInput(string $path): string
{
    if (!is_file($path) || filesize($path) > 65536) throw new InvalidArgumentException('INVALID_INPUT_FILE');
    $value = file_get_contents($path);
    if ($value === false) throw new RuntimeException('INPUT_UNREADABLE');
    return $value;
}
