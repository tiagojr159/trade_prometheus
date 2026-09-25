<?php
declare(strict_types=1);
// Probe CLI do settings.php: renderiza página para verificar vazamento de segredos.
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
chdir(dirname(__DIR__));
ob_start();
require 'admin/settings.php';
$html = ob_get_clean();
echo $html;
