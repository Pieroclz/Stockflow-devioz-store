<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_admin();
if ((string)($_SESSION['admin_role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Solo la cuenta administradora puede gestionar motorizados.');
}
$pageTitle = 'Gestión de motorizados';
$pageSubtitle = 'Registro y cuentas de repartidores';
$activePage = 'drivers';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'toggle_driver') {
        $driverId = filter_var($_POST['driver_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $target = (string)($_POST['target_active'] ?? '');
        if (!$driverId || !in_array($target, ['0','1'], true)) {
            $error = 'Solicitud de cambio de estado inválida.';
        } else {
            try {
                $pdo = db();
                $pdo->beginTransaction();
                $q = $pdo->prepare('SELECT id, name, active FROM delivery_drivers WHERE id=? FOR UPDATE');
                $q->execute([$driverId]);
                $row = $q->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    throw new RuntimeException('El motorizado ya no existe.');
                }
                if ($target === '0') {
                    $check = $pdo->prepare("SELECT COUNT(*) FROM delivery_assignments a JOIN yape_orders o ON o.id=a.order_id WHERE a.driver_id=? AND a.finished_at IS NULL AND a.delivery_status IN ('assigned','picked_up','in_transit') AND o.status='aprobado' AND o.fulfillment_status NOT IN ('entregado','cancelado')");
                    $check->execute([$driverId]);
                    $activeCount=(int)$check->fetchColumn();
                    try { $extraCount=$pdo->prepare("SELECT COUNT(*) FROM delivery_extra_assignments a JOIN yape_orders o ON o.id=a.order_id WHERE a.driver_id=? AND a.finished_at IS NULL AND a.delivery_status IN ('assigned','picked_up','in_transit') AND o.status='aprobado' AND o.fulfillment_status NOT IN ('entregado','cancelado')");$extraCount->execute([$driverId]);$activeCount+=(int)$extraCount->fetchColumn(); } catch(PDOException $ignored){}
                    if ($activeCount > 0) {
                        throw new RuntimeException('No puedes inhabilitar este motorizado porque tiene entregas activas. Reasígnalas o finalízalas primero.');
                    }
                }
                $pdo->prepare('UPDATE delivery_drivers SET active=? WHERE id=?')->execute([(int)$target, $driverId]);
                $pdo->commit();
                flash('success', $target === '1' ? 'Motorizado habilitado correctamente.' : 'Motorizado inhabilitado. Ya no podrá iniciar ni mantener su sesión.');
                redirect('admin/motorizados.php');
            } catch (Throwable $ex) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'No se pudo cambiar el estado del motorizado. Verifica las tablas y vuelve a intentarlo.';
            }
        }
    } elseif (($_POST['action'] ?? 'create_driver') === 'create_driver') {
    $name = trim((string)($_POST['name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $error = 'El nombre debe tener entre 2 y 120 caracteres.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/D', $username)) {
        $error = 'El usuario debe tener entre 3 y 60 caracteres y usar solo letras, números, punto, guion o guion bajo.';
    } elseif (strlen($password) < 12) {
        $error = 'La contraseña debe tener al menos 12 caracteres.';
    } else {
        try {
            $q=db()->prepare('INSERT INTO delivery_drivers(name, username, password_hash, active) VALUES (?, ?, ?, 1)');
            $q->execute([$name,$username,password_hash($password,PASSWORD_DEFAULT)]);
            flash('success','Motorizado creado correctamente. Ya puede entrar en el portal de repartidores.');
            redirect('admin/motorizados.php');
        } catch (PDOException $ex) {
            $error = ($ex->getCode() === '23000') ? 'Ese nombre de usuario ya existe. Elige otro.' : 'No se pudo crear la cuenta. Revisa la conexión y la tabla delivery_drivers.';
        }
    }
    }
}
$drivers=[];
try {
    $drivers=db()->query("SELECT d.id,d.name,d.username,d.active,d.created_at,
        (SELECT COUNT(*) FROM delivery_assignments a JOIN yape_orders o ON o.id=a.order_id WHERE a.driver_id=d.id AND a.finished_at IS NULL AND a.delivery_status IN ('assigned','picked_up','in_transit') AND o.status='aprobado' AND o.fulfillment_status NOT IN ('entregado','cancelado')) AS active_deliveries
        FROM delivery_drivers d ORDER BY d.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    try { $extraRows=db()->query("SELECT a.driver_id,COUNT(*) AS qty FROM delivery_extra_assignments a JOIN yape_orders o ON o.id=a.order_id WHERE a.finished_at IS NULL AND a.delivery_status IN ('assigned','picked_up','in_transit') AND o.status='aprobado' AND o.fulfillment_status NOT IN ('entregado','cancelado') GROUP BY a.driver_id")->fetchAll(PDO::FETCH_KEY_PAIR);foreach($drivers as &$dr) $dr['active_deliveries']+=(int)($extraRows[$dr['id']]??0);unset($dr); } catch(PDOException $ignored){}
} catch (Throwable $ex) {
    $error = 'No se pudo consultar la tabla delivery_drivers. Comprueba que exista en tu base de datos.';
}
require __DIR__.'/../includes/admin_header.php';
?>
<style>.driver-state{display:inline-block;padding:7px 12px;border-radius:999px;font-size:13px;font-weight:750}.driver-state-on{background:#dff9ef;color:#076b50}.driver-state-off{background:#fff0ed;color:#a23830}.driver-btn-disable{background:#fff0ed!important;color:#ab3b32!important;border:1px solid #f0b7b1!important}.driver-btn-enable{background:#ddf8ee!important;color:#08765b!important}.driver-busy{display:inline-block;font-size:12px;margin-right:10px;color:#8e5e20}.driver-btn-disable:disabled{opacity:.52;cursor:not-allowed}.data-table td{vertical-align:middle}</style>
<div class="catalog-dashboard">
  <section class="dashboard-welcome"><div><span class="dashboard-eyebrow">LOGÍSTICA · PERSONAL</span><h2>Motorizados</h2><p>Crea cuentas y consulta los repartidores registrados sin salir del dashboard.</p></div><div class="dashboard-welcome-actions"><a class="btn btn-secondary" href="<?= url('admin/index.php') ?>">Volver al dashboard</a></div></section>
  <?php if ($error !== ''): ?><div class="alert alert-warning" role="alert"><?= e($error) ?></div><?php endif; ?>
  <section class="panel" style="padding:24px;margin:22px 0;max-width:760px">
    <div class="panel-heading"><div><h2>Nuevo motorizado</h2><p>La cuenta podrá ingresar desde el portal de repartidores.</p></div></div>
    <form method="post" autocomplete="off" style="display:grid;gap:15px;margin-top:18px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_driver">
      <label>Nombre completo<br><input type="text" name="name" required maxlength="120" minlength="2" value="<?= e((string)($_POST['name'] ?? '')) ?>" style="width:100%;padding:11px;margin-top:6px"></label>
      <label>Usuario<br><input type="text" name="username" required minlength="3" maxlength="60" pattern="[A-Za-z0-9_.-]+" value="<?= e((string)($_POST['username'] ?? '')) ?>" style="width:100%;padding:11px;margin-top:6px"></label>
      <label>Contraseña (mínimo 12 caracteres)<br><input type="password" name="password" required minlength="12" autocomplete="new-password" style="width:100%;padding:11px;margin-top:6px"></label>
      <div><button class="btn btn-primary" type="submit">Crear motorizado</button></div>
    </form>
  </section>
  <section class="panel" style="padding:24px;margin:22px 0">
    <div class="panel-heading"><div><h2>Motorizados registrados</h2><p><?= count($drivers) ?> cuenta(s) en el sistema.</p></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Nombre</th><th>Usuario</th><th>Estado</th><th>Fecha de registro</th><th>Acciones</th></tr></thead><tbody>
      <?php foreach($drivers as $driver): ?><tr><td>#<?= (int)$driver['id'] ?></td><td><?= e($driver['name']) ?></td><td><?= e($driver['username']) ?></td><td><span class="driver-state <?= (int)$driver['active']===1 ? 'driver-state-on' : 'driver-state-off' ?>"><?= (int)$driver['active']===1 ? 'Activo' : 'Inhabilitado' ?></span></td><td><?= e(date('d/m/Y',strtotime($driver['created_at']))) ?></td><td><?php $active = ((int)$driver['active']===1); $busy = ((int)$driver['active_deliveries']>0); ?>
        <?php if ($active && $busy): ?><span class="driver-busy" title="Reasigna o finaliza sus entregas activas antes de inhabilitar"><?= (int)$driver['active_deliveries'] ?> entrega(s) activas</span><?php endif; ?>
        <form method="post" style="display:inline" onsubmit="return confirm(<?= e(json_encode($active ? '¿Inhabilitar a este motorizado? No podrá acceder al portal.' : '¿Habilitar nuevamente a este motorizado?', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>)">
          <?= csrf_field() ?><input type="hidden" name="action" value="toggle_driver"><input type="hidden" name="driver_id" value="<?= (int)$driver['id'] ?>"><input type="hidden" name="target_active" value="<?= $active ? '0' : '1' ?>">
          <button type="submit" class="btn <?= $active ? 'driver-btn-disable' : 'driver-btn-enable' ?>" <?= $active && $busy ? 'disabled title="Tiene entregas activas"' : '' ?>><?= $active ? 'Inhabilitar' : 'Habilitar' ?></button>
        </form></td></tr><?php endforeach; ?>
      <?php if (!$drivers): ?><tr><td colspan="6">Todavía no hay motorizados registrados.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>
<?php require __DIR__.'/../includes/admin_footer.php'; ?>
