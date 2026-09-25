<?php
declare(strict_types=1);
// Probe CLI do dashboard (Item 25): renderiza e valida.
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
chdir(dirname(__DIR__));
ob_start();
require 'admin/dashboard.php';
$html = ob_get_clean();
echo strlen($html) . '|' . (strpos($html, 'PROMETHEUS') !== false && strpos($html, 'indispon') !== false ? 'OK' : 'MISSING');
