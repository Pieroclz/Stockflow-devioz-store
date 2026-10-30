<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
header('Cache-Control: no-store, no-cache, must-revalidate, private');

if (is_admin()) {
    redirect('admin/index.php');
}

$error = null;
$flashMessage = pull_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $fingerprint = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . mb_strtolower($username));
    db()->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            fingerprint CHAR(64) PRIMARY KEY,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB'
    );
    db()->exec("DELETE FROM login_attempts WHERE updated_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    db()->exec("DELETE FROM login_attempts WHERE locked_until IS NOT NULL AND locked_until < NOW()");
    $attemptQuery = db()->prepare('SELECT attempts, locked_until FROM login_attempts WHERE fingerprint = ? LIMIT 1');
    $attemptQuery->execute([$fingerprint]);
    $attemptRecord = $attemptQuery->fetch() ?: ['attempts' => 0, 'locked_until' => null];
    $isLocked = !empty($attemptRecord['locked_until']) && strtotime((string) $attemptRecord['locked_until']) > time();

    if ($username === '' || $password === '') {
        $error = 'Completa el usuario y la contraseña.';
    } elseif ($isLocked) {
        $error = 'Demasiados intentos. Espera 15 minutos antes de volver a probar.';
    } else {
        $statement = db()->prepare('SELECT id, name, username, password, role FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $user['id'];
            $_SESSION['admin_name'] = $user['name'];
            $_SESSION['admin_role'] = $user['role'] ?? 'admin';
            db()->prepare('DELETE FROM login_attempts WHERE fingerprint = ?')->execute([$fingerprint]);
            flash('success', 'Bienvenido al panel de Devioz Store.');
            redirect('admin/index.php');
        }

        $registerAttempt = db()->prepare(
            'INSERT INTO login_attempts (fingerprint, attempts, locked_until) VALUES (?, 1, NULL)
             ON DUPLICATE KEY UPDATE
                attempts = attempts + 1,
                locked_until = IF(attempts >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until)'
        );
        $registerAttempt->execute([$fingerprint]);
        $error = 'El usuario o la contraseña no son correctos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Acceso administrativo | Devioz Store</title>
    <link rel="icon" href="<?= url('assets/img/favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= url('assets/css/styles.css?v=' . (string) @filemtime(__DIR__ . '/assets/css/styles.css')) ?>">
</head>
<body class="login-body">
    <main class="login-layout">
        <section class="login-showcase">
            <a class="brand brand-light devioz-brand" href="<?= url('index.php') ?>">
                <img class="devioz-logo devioz-logo-login" src="<?= url('assets/img/logo-devioz-store.png') ?>" alt="">
                <span><strong>Devioz Store</strong><small>ZONA PRIVADA</small></span>
            </a>
            <div class="login-message">
                <span class="eyebrow eyebrow-dark"><i></i> PANEL DE CONTROL</span>
                <h1>Tu inventario,<br><span>bajo control.</span></h1>
                <p>Administra productos, categorías, combos, compras y ventas con información clara.</p>
                <div class="login-features">
                    <span>✓ Productos y combos</span>
                    <span>✓ Historial financiero</span>
                    <span>✓ Acceso protegido</span>
                </div>
            </div>
            <small>Devioz Store · Gestión de inventario y ventas</small>
        </section>

        <section class="login-form-side">
            <form class="login-card" method="post" novalidate>
                <?= csrf_field() ?>
                <a class="back-link" href="<?= url('index.php') ?>">← Volver al catálogo</a>
                <span class="login-icon">▥</span>
                <h2>Bienvenido</h2>
                <p>Ingresa tus credenciales para continuar.</p>

                <?php if ($flashMessage): ?>
                    <div class="alert alert-<?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= e($error) ?></div>
                <?php endif; ?>

                <label class="form-group">
                    <span>Usuario</span>
                    <div class="input-icon"><i>◎</i><input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" placeholder="Ingresa tu usuario" required autofocus></div>
                </label>
                <label class="form-group">
                    <span>Contraseña</span>
                    <div class="input-icon"><i>⌾</i><input id="loginPassword" type="password" name="password" placeholder="Ingresa tu contraseña" required><button class="password-toggle" type="button" data-password-toggle="loginPassword">Ver</button></div>
                </label>

                <button class="btn btn-primary btn-block btn-lg" type="submit">Iniciar sesión <span>→</span></button>
            </form>
        </section>
    </main>
    <script>window.STOCKFLOW_BASE_URL = <?= json_encode(BASE_URL, JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="<?= url('assets/js/app.js?v=' . (string) @filemtime(__DIR__ . '/assets/js/app.js')) ?>" defer></script>
</body>
</html>
