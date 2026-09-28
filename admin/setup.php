<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

use Prometheus\admin\AdminGuard;
use Prometheus\core\Database;

AdminGuard::secureHeaders();
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

$isPost = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
$csrf = $isPost
    ? (string) ($_SESSION['admin_setup_csrf'] ?? '')
    : bin2hex(random_bytes(32));
if (!$isPost) {
    $_SESSION['admin_setup_csrf'] = $csrf;
}
$message = null;
$error = null;

if ($isPost) {
    $csrfOk = isset($_POST['csrf'], $_SESSION['admin_setup_csrf'])
        && hash_equals((string) $_SESSION['admin_setup_csrf'], (string) $_POST['csrf']);
    $setupKey = (string) ($_POST['setup_key'] ?? '');
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if (!$csrfOk) {
        $error = 'A página expirou. Atualize e tente novamente.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.@-]{1,64}$/', $username)) {
        $error = 'Usuário inválido. Use até 64 letras, números, ponto, hífen, sublinhado ou @.';
    } elseif (strlen($password) < 6) {
        $error = 'A senha precisa ter pelo menos 6 caracteres.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'As senhas não conferem.';
    } elseif ($setupKey === '') {
        $error = 'Informe a chave de ativação.';
    } else {
        $pdo = null;
        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            $stmt = $pdo->query('SELECT COUNT(*) FROM admin_users WHERE active = 1');
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('Já existe um administrador ativo.');
            }
            $tokenStmt = $pdo->prepare('SELECT token_hash FROM admin_setup_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE');
            $tokenStmt->execute([hash('sha256', $setupKey)]);
            if (!$tokenStmt->fetchColumn()) {
                throw new RuntimeException('Chave inválida, expirada ou já utilizada.');
            }
            $insert = $pdo->prepare('INSERT INTO admin_users (username, password_hash, active) VALUES (?, ?, 1)');
            $insert->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $consume = $pdo->prepare('UPDATE admin_setup_tokens SET used_at = NOW() WHERE token_hash = ? AND used_at IS NULL');
            $consume->execute([hash('sha256', $setupKey)]);
            $pdo->commit();
            unset($_SESSION['admin_setup_csrf']);
            $message = 'Administrador criado. A chave de ativação não poderá ser reutilizada. Entre pela página de login.';
        } catch (Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível criar o usuário. Confirme que o SQL foi importado no banco do site.';
        }
    }
    // Rotate after checking the submitted token, so failed submissions can be retried safely.
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['admin_setup_csrf'] = $csrf;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Configurar administrador - PROMETHEUS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container" style="max-width:480px">
  <div class="card mt-5 shadow-sm"><div class="card-body p-4">
    <h1 class="h4 mb-3">Criar administrador</h1>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($message): ?>
      <div class="alert alert-success" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
      <a class="btn btn-primary w-100" href="login.php">Ir para o login</a>
    <?php else: ?>
      <p class="text-muted">Use a chave de ativação definida no SQL. Ela funciona uma única vez e expira em 24 horas.</p>
      <form method="post" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="mb-3"><label for="setup_key" class="form-label">Chave de ativação</label><input type="password" class="form-control" id="setup_key" name="setup_key" required autocomplete="off"></div>
        <div class="mb-3"><label for="username" class="form-label">Usuário</label><input type="text" class="form-control" id="username" name="username" required maxlength="64" autocomplete="username"></div>
        <div class="mb-3"><label for="password" class="form-label">Senha (mínimo 6 caracteres)</label><input type="password" class="form-control" id="password" name="password" required minlength="6" autocomplete="new-password"></div>
        <div class="mb-3"><label for="password_confirm" class="form-label">Confirme a senha</label><input type="password" class="form-control" id="password_confirm" name="password_confirm" required minlength="6" autocomplete="new-password"></div>
        <button class="btn btn-primary w-100" type="submit">Criar administrador</button>
      </form>
    <?php endif; ?>
  </div></div>
</main>
</body>
</html>
