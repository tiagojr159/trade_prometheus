<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

use Prometheus\admin\AdminGuard;

AdminGuard::secureHeaders();
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

if (!empty($_SESSION['prometheus_admin_ok'])) {
    header('Location: ../index.php', true, 302);
    exit;
}

$csrf = bin2hex(random_bytes(32));
$_SESSION['login_csrf'] = $csrf;
$error = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
$credentials = AdminGuard::credentials();
$credentialsConfigured = $credentials[0] !== '' && $credentials[1] !== '';
$legacyConfigured = (getenv('PROMETHEUS_ADMIN_TOKEN') ?: '') !== '';
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Entrar - PROMETHEUS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container" style="max-width:420px">
  <div class="card mt-5 shadow-sm"><div class="card-body p-4">
    <h1 class="h4 mb-3">Acesso ao PROMETHEUS</h1>
    <?php if ($error): ?><div class="alert alert-danger py-2" role="alert"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if (!$credentialsConfigured && !$legacyConfigured): ?>
      <div class="alert alert-warning" role="alert">O administrador ainda não configurou as credenciais de acesso. Entre em contato com quem administra o servidor.</div>
    <?php else: ?>
      <form method="post" action="../index.php" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($credentialsConfigured): ?>
          <div class="mb-3">
            <label for="admin_user" class="form-label">Usuário</label>
            <input type="text" class="form-control" id="admin_user" name="admin_user" autocomplete="username" required autofocus>
          </div>
          <div class="mb-3">
            <label for="admin_password" class="form-label">Senha</label>
            <input type="password" class="form-control" id="admin_password" name="admin_password" autocomplete="current-password" required>
          </div>
        <?php else: ?>
          <div class="mb-3">
            <label for="admin_token" class="form-label">Token de administrador</label>
            <input type="password" class="form-control" id="admin_token" name="admin_token" autocomplete="current-password" required autofocus>
          </div>
        <?php endif; ?>
        <button class="btn btn-primary w-100" type="submit">Entrar</button>
      </form>
    <?php endif; ?>
  </div></div>
</main>
</body>
</html>
