<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['new_password_confirmation'] ?? '');
    $errors = [];

    $statement = db()->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $statement->execute([(int) $_SESSION['admin_id']]);
    $currentHash = (string) ($statement->fetchColumn() ?: '');

    if (!password_verify($currentPassword, $currentHash)) $errors[] = 'La contraseña actual no es correcta.';
    if (strlen($newPassword) < 10) $errors[] = 'La nueva contraseña debe tener al menos 10 caracteres.';
    if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
        $errors[] = 'Incluye mayúsculas, minúsculas y números.';
    }
    if ($newPassword !== $confirmation) $errors[] = 'La confirmación no coincide.';
    if ($newPassword !== '' && password_verify($newPassword, $currentHash)) $errors[] = 'La nueva contraseña debe ser diferente.';

    if (!$errors) {
        $update = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
        $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $_SESSION['admin_id']]);
        session_regenerate_id(true);
        flash('success', 'Contraseña actualizada correctamente.');
        redirect('admin/seguridad.php');
    }
    flash('danger', implode(' ', $errors));
    redirect('admin/seguridad.php');
}

$passwordQuery = db()->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
$passwordQuery->execute([(int) $_SESSION['admin_id']]);
$usesDemoPassword = password_verify('password', (string) ($passwordQuery->fetchColumn() ?: ''));
$forwardedProtocol = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$httpsActive = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProtocol === 'https';
$uploadsDirectory = dirname(__DIR__) . '/assets/uploads';
$uploadsReady = is_dir($uploadsDirectory) && is_writable($uploadsDirectory);
$productionMode = APP_ENV === 'production' && !APP_DEBUG;

$pageTitle = 'Seguridad de la cuenta';
$pageSubtitle = 'Protege el acceso al panel administrativo';
$activePage = 'security';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="security-layout">
    <article class="panel security-card">
        <div class="panel-heading"><div><h2>Cambiar contraseña</h2><p>Usa una contraseña exclusiva para esta tienda.</p></div></div>
        <?php if ($usesDemoPassword): ?><div class="alert alert-warning"><span>Estás usando la contraseña de demostración. Debes cambiarla antes de publicar la tienda.</span></div><?php endif; ?>
        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <label class="form-group"><span>Contraseña actual</span><input type="password" name="current_password" required autocomplete="current-password"></label>
            <label class="form-group"><span>Nueva contraseña</span><input type="password" name="new_password" required minlength="10" autocomplete="new-password"><small>Mínimo 10 caracteres, con mayúsculas, minúsculas y números.</small></label>
            <label class="form-group"><span>Confirmar contraseña</span><input type="password" name="new_password_confirmation" required minlength="10" autocomplete="new-password"></label>
            <button class="btn btn-primary btn-block" type="submit">Actualizar contraseña</button>
        </form>
    </article>
    <aside class="panel security-checklist">
        <span class="section-kicker">LISTA DE SEGURIDAD</span><h2>Antes de publicar</h2>
        <ul>
            <li class="<?= $httpsActive ? 'is-ready' : 'needs-action' ?>"><i><?= $httpsActive ? '✓' : '!' ?></i><span><strong>HTTPS <?= $httpsActive ? 'activo' : 'pendiente' ?></strong><small>Protege contraseñas y comprobantes.</small></span></li>
            <li class="<?= !$usesDemoPassword ? 'is-ready' : 'needs-action' ?>"><i><?= !$usesDemoPassword ? '✓' : '!' ?></i><span><strong>Contraseña <?= !$usesDemoPassword ? 'privada' : 'de demostración' ?></strong><small><?= !$usesDemoPassword ? 'La credencial inicial ya fue reemplazada.' : 'Cámbiala antes de publicar.' ?></small></span></li>
            <li class="<?= $uploadsReady ? 'is-ready' : 'needs-action' ?>"><i><?= $uploadsReady ? '✓' : '!' ?></i><span><strong>Carpeta de imágenes</strong><small><?= $uploadsReady ? 'Lista para recibir archivos.' : 'Se usará el respaldo en MySQL.' ?></small></span></li>
            <li class="<?= $productionMode ? 'is-ready' : 'needs-action' ?>"><i><?= $productionMode ? '✓' : '!' ?></i><span><strong>Modo <?= $productionMode ? 'producción' : 'local' ?></strong><small><?= $productionMode ? 'Los errores técnicos están ocultos.' : 'Configura APP_ENV=production y APP_DEBUG=0.' ?></small></span></li>
        </ul>
    </aside>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
