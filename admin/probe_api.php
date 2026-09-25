<?php
declare(strict_types=1);
// Probe CLI parametrizado de endpoints da API: php probe_api.php <endpoint> [limit]
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = 'probe-' . bin2hex(random_bytes(4));
chdir(dirname(__DIR__));
$ep = basename($argv[1] ?? 'signals');
$_GET['limit'] = max(1, min(50, (int)($argv[2] ?? 5)));
$file = __DIR__ . '/../api/' . $ep . '.php';
if (!is_file($file)) {
    echo json_encode(['error' => 'unknown_endpoint']);
    exit(1);
}
require $file;
