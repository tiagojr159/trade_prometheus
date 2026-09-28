<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$username = trim((string) ($argv[1] ?? ''));
if ($username === '' || strlen($username) > 64 || preg_match('/[^a-zA-Z0-9_.@-]/', $username)) {
    fwrite(STDERR, "Uso: php admin/create_admin.php <usuario>\nUse letras, números, ponto, hífen, sublinhado ou @ (máximo 64 caracteres).\n");
    exit(1);
}

if (function_exists('readline')) {
    $password = readline('Nova senha (mínimo 14 caracteres; entrada ficará visível): ');
    $confirmation = readline('Confirme a senha: ');
} else {
    $password = getenv('PROMETHEUS_ADMIN_SETUP_PASSWORD') ?: '';
    $confirmation = getenv('PROMETHEUS_ADMIN_SETUP_PASSWORD_CONFIRM') ?: '';
    if ($password === '' || $confirmation === '') {
        fwrite(STDERR, "Defina temporariamente PROMETHEUS_ADMIN_SETUP_PASSWORD e PROMETHEUS_ADMIN_SETUP_PASSWORD_CONFIRM no ambiente do PHP e execute novamente.\n");
        exit(1);
    }
}
if (!is_string($password) || strlen($password) < 14 || !hash_equals($password, (string) $confirmation)) {
    fwrite(STDERR, "Senha inválida ou confirmação diferente. Nenhuma alteração foi feita.\n");
    exit(1);
}

$credentialsPath = dirname(__DIR__) . '/config/admin_credentials.php';
$payload = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export([
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
], true) . ";\n";
if (file_put_contents($credentialsPath, $payload, LOCK_EX) === false) {
    fwrite(STDERR, "Não foi possível gravar as credenciais em config/admin_credentials.php.\n");
    exit(1);
}
@chmod($credentialsPath, 0600);
fwrite(STDOUT, "Administrador configurado. O arquivo contém somente o hash da senha.\n");
