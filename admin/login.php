<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
use Prometheus\admin\AdminGuard;

AdminGuard::secureHeaders();
AdminGuard::requireAdmin(); // já autenticado? redireciona ao dashboard via fluxo normal

// Se chegou aqui, não está autenticado: mostra formulário de login (token).
$csrf = bin2hex(random_bytes(16));
$_SESSION['login_csrf'] = $csrf;
$error = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST['admin_token']) ? 'Token obrigatório.' : null;
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Login - PROMETHEUS</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container" style="max-width:420px"><div class="card mt-5"><div class="card-body">
<h1 class="h4 mb-3">Acesso administrativo</h1>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
  <div class="mb-3">
    <label for="admin_token" class="form-label">Token de administrador</label>
    <input type="password" class="form-control" id="admin_token" name="admin_token" autofocus>
  </div>
  <button class="btn btn-primary w-100">Entrar</button>
</form>
<p class="text-muted small mt-3">O token é definido na variável de ambiente <code>PROMETHEUS_ADMIN_TOKEN</code>.</p>
</div></div></main></body></html>
