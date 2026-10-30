<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/delivery_incidents.php';
require_admin();
$pageTitle = 'Incidencias de entrega';
$pageSubtitle = 'Historial de incidencias reportadas por motorizados';
$activePage = 'incidents';
$pdo = db();
$error = '';
try {
    delivery_incidents_initialize($pdo);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if ((string)($_SESSION['admin_role'] ?? '') !== 'admin') throw new RuntimeException('Solo administración puede decidir sobre una incidencia.');
        $incidentId = (int)($_POST['incident_id'] ?? 0);
        $action = (string)($_POST['incident_action'] ?? '');
        $notes = trim((string)($_POST['resolution_notes'] ?? ''));
        if ($incidentId < 1 || !in_array($action,['review','reject','reassign'],true) || mb_strlen($notes)>1000) throw new RuntimeException('Acción no válida.');
        if (in_array($action,['reject','reassign'],true) && mb_strlen($notes)<5) throw new RuntimeException('Explica la decisión (mínimo 5 caracteres).');
        $pdo->beginTransaction();
        $stmt=$pdo->prepare("SELECT * FROM delivery_incidents WHERE id=? FOR UPDATE");
        $stmt->execute([$incidentId]);$incident=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$incident || !in_array((string)$incident['status'],['pendiente','en_revision'],true)) throw new RuntimeException('Esta incidencia ya fue decidida o no existe.');
        if ($action === 'review') {
            $pdo->prepare("UPDATE delivery_incidents SET status='en_revision',resolution_notes=? WHERE id=?")->execute([$notes ?: null,$incidentId]);
        } else {
            $orderId=(int)$incident['order_id'];$oldDriver=(int)$incident['driver_id'];
            $o=$pdo->prepare('SELECT status,fulfillment_status FROM yape_orders WHERE id=? FOR UPDATE');$o->execute([$orderId]);$order=$o->fetch(PDO::FETCH_ASSOC);
            if (!$order || $order['status']!=='aprobado' || in_array($order['fulfillment_status'],['cancelado','entregado'],true)) throw new RuntimeException('El pedido ya no está activo.');
            $a=$pdo->prepare('SELECT driver_id,finished_at,delivery_status FROM delivery_assignments WHERE order_id=? AND driver_id=? FOR UPDATE');$a->execute([$orderId,$oldDriver]);$assignment=$a->fetch(PDO::FETCH_ASSOC);
            $assignmentTable='delivery_assignments';
            if (!$assignment) {
                $a=$pdo->prepare('SELECT driver_id,finished_at,delivery_status FROM delivery_extra_assignments WHERE order_id=? AND driver_id=? FOR UPDATE');$a->execute([$orderId,$oldDriver]);$assignment=$a->fetch(PDO::FETCH_ASSOC);
                $assignmentTable='delivery_extra_assignments';
            }
            if (!$assignment || !in_array($assignment['delivery_status'],['assigned','picked_up','in_transit','failed'],true) || ($assignment['finished_at']!==null && $assignment['delivery_status']!=='failed')) throw new RuntimeException('El despacho cambió. Verifica la asignación antes de decidir.');
            if ($action === 'reassign') {
                $newDriver=(int)($_POST['driver_id'] ?? 0);
                if ($newDriver<1 || $newDriver===$oldDriver) throw new RuntimeException('Selecciona otro motorizado.');
                $d=$pdo->prepare('SELECT id FROM delivery_drivers WHERE id=? AND active=1 FOR UPDATE');$d->execute([$newDriver]);
                if (!$d->fetchColumn()) throw new RuntimeException('El nuevo motorizado no está activo.');
                $checkPrimary=$pdo->prepare('SELECT COUNT(*) FROM delivery_assignments WHERE order_id=? AND driver_id=?');$checkPrimary->execute([$orderId,$newDriver]);
                $checkExtra=$pdo->prepare('SELECT COUNT(*) FROM delivery_extra_assignments WHERE order_id=? AND driver_id=?');$checkExtra->execute([$orderId,$newDriver]);
                if ((int)$checkPrimary->fetchColumn()+(int)$checkExtra->fetchColumn()>0) throw new RuntimeException('El nuevo motorizado ya está asignado a otra parte de esta compra.');
                $pdo->prepare("UPDATE {$assignmentTable} SET driver_id=?,delivery_status='assigned',claimed_at=NOW(),finished_at=NULL,picked_up_at=NULL,delivered_at=NULL,delivery_notes=NULL WHERE order_id=? AND driver_id=?")->execute([$newDriver,$orderId,$oldDriver]);
                // El enlace del conductor principal se invalida cuando se reasigna el despacho principal.
                if ($assignmentTable==='delivery_assignments') {
                    $token=hash('sha256',bin2hex(random_bytes(32)));
                    $pdo->prepare("INSERT INTO delivery_tracking (order_id,driver_token_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE driver_token_hash=VALUES(driver_token_hash),driver_lat=NULL,driver_lng=NULL,accuracy_m=NULL,updated_at=NOW()")->execute([$orderId,$token]);
                }
                $decision='reasignada';
            } else {
                $pdo->prepare("UPDATE {$assignmentTable} SET delivery_notes=NULL, delivery_status=CASE WHEN delivery_status='failed' THEN 'assigned' ELSE delivery_status END, finished_at=NULL WHERE order_id=? AND driver_id=?")->execute([$orderId,$oldDriver]);
                $decision='rechazada';
            }
            $pdo->prepare("UPDATE delivery_incidents SET status='resuelta',decision=?,decided_at=NOW(),resolved_at=NOW(),resolution_notes=? WHERE id=?")->execute([$decision,$notes,$incidentId]);
        }
        $pdo->commit();
        flash('success',$action==='review'?'Incidencia marcada en revisión.':($action==='reassign'?'Incidencia aprobada: entrega reasignada al nuevo motorizado.':'Reporte rechazado: el pedido sigue con su motorizado original.'));
        redirect('admin/incidencias.php');
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo acceder al historial de incidencias. Comprueba los permisos de MySQL.';
}
$filter = (string)($_GET['estado'] ?? 'todas');
if (!in_array($filter,['todas','pendiente','en_revision','resuelta'],true)) $filter='todas';
$page = max(1,min(100000,(int)($_GET['pagina'] ?? 1)));
$perPage=25;
$total=0;
$incidents=[];
$activeDrivers=[];
try {$activeDrivers=$pdo->query("SELECT id,name FROM delivery_drivers WHERE active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $ignored){}
if ($error==='') {
    try {
        $where = $filter==='todas' ? '' : ' WHERE i.status = :status';
        $count = $pdo->prepare('SELECT COUNT(*) FROM delivery_incidents i'.$where);
        if ($filter!=='todas') $count->bindValue(':status',$filter);
        $count->execute();$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
        $query=$pdo->prepare('SELECT i.*,o.order_code,o.customer_name,d.name AS driver_name FROM delivery_incidents i LEFT JOIN yape_orders o ON o.id=i.order_id LEFT JOIN delivery_drivers d ON d.id=i.driver_id'.$where.' ORDER BY i.reported_at DESC,i.id DESC LIMIT :lim OFFSET :off');
        if ($filter!=='todas') $query->bindValue(':status',$filter);
        $query->bindValue(':lim',$perPage,PDO::PARAM_INT);
        $query->bindValue(':off',($page-1)*$perPage,PDO::PARAM_INT);
        $query->execute();$incidents=$query->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {$error='No se pudo cargar el listado de incidencias.';}
}
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="catalog-dashboard">
    <section class="dashboard-welcome"><div><span class="dashboard-eyebrow">SEGUIMIENTO DE REPARTOS</span><h2>Incidencias registradas</h2><p>Un reporte no cancela una compra. Administración debe rechazarlo, reasignarlo o confirmar expresamente la cancelación.</p></div><div class="dashboard-welcome-actions"><a class="btn btn-secondary" href="<?= url('admin/index.php') ?>">Volver al dashboard</a></div></section>
    <?php if ($error!==''): ?><div class="alert alert-warning"><?= e($error) ?></div><?php endif; ?>
    <section class="panel incidents-panel">
        <nav class="incidents-filter" aria-label="Filtrar incidencias">
            <?php foreach (['todas'=>'Todas','pendiente'=>'Por decidir','en_revision'=>'En revisión','resuelta'=>'Decididas'] as $value=>$label): ?>
                <a class="btn <?= $filter===$value ? 'btn-primary' : 'btn-secondary' ?>" href="<?= url('admin/incidencias.php?estado='.$value) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <p class="incidents-count"><?= number_format($total) ?> incidencia(s) <?= $filter==='todas' ? 'en total' : 'con este estado' ?></p>
        <div class="table-wrap"><table class="data-table incidents-table"><thead><tr><th>Fecha</th><th>Pedido / Cliente</th><th>Motorizado</th><th>Motivo reportado</th><th>Seguimiento</th></tr></thead><tbody>
            <?php foreach($incidents as $incident): ?><tr>
                <td><?= e(date('d/m/Y H:i',strtotime((string)$incident['reported_at']))) ?></td>
                <td><strong><?= e($incident['order_code'] ?: '#'.$incident['order_id']) ?></strong><br><small><?= e($incident['customer_name'] ?: 'Cliente no disponible') ?></small><br><a href="<?= url('admin/pedidos_yape.php') ?>">Ver pedidos</a></td>
                <td><?= e($incident['driver_name'] ?: 'Motorizado no disponible') ?></td>
                <td class="incident-description"><?= e($incident['description']) ?></td>
                <td class="incident-followup">
                    <span class="incident-state <?= $incident['status'] === 'pendiente' ? 'is-open' : ($incident['status'] === 'en_revision' ? 'is-review' : 'is-closed') ?>"><?= e($incident['status'] === 'pendiente' ? 'Por decidir' : ($incident['status'] === 'en_revision' ? 'En revisión' : 'Resuelta')) ?></span>
                    <?php if (!empty($incident['decision'])): ?><span class="incident-decision">Decisión: <?= e((string)$incident['decision']) ?></span><?php endif; ?>
                    <?php if (!empty($incident['resolution_notes'])): ?><p class="incident-notes"><?= e($incident['resolution_notes']) ?></p><?php endif; ?>
                    <?php if ((string)($_SESSION['admin_role']??'')==='admin' && in_array((string)$incident['status'],['pendiente','en_revision'],true)): ?>
                    <form method="post" class="incident-actions"><?= csrf_field() ?><input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>">
                      <label>Justificación administrativa<input name="resolution_notes" maxlength="1000" placeholder="Motivo de la decisión" required minlength="5" style="width:100%" value="<?= e($incident['resolution_notes']??'') ?>"></label>
                      <button class="btn btn-secondary" type="submit" name="incident_action" value="review" formnovalidate>Marcar en revisión</button>
                      <button class="btn btn-secondary" type="submit" name="incident_action" value="reject" onclick="return confirm('¿Rechazar el reporte y mantener la entrega con este motorizado?')">Rechazar incidencia · continuar entrega</button>
                      <label>Reasignar a otro motorizado<select name="driver_id" style="display:block;width:100%"><option value="">Seleccionar...</option><?php foreach($activeDrivers as $candidate): if ((int)$candidate['id']===(int)$incident['driver_id']) continue; ?><option value="<?= (int)$candidate['id'] ?>"><?= e($candidate['name']) ?></option><?php endforeach; ?></select></label>
                      <button class="btn btn-primary" type="submit" name="incident_action" value="reassign" onclick="return confirm('¿Aprobar incidencia y transferir la entrega al motorizado elegido?')">Aprobar y reasignar</button>
                    </form>
                    <form method="post" action="<?= url('admin/pedidos_yape.php') ?>" class="incident-actions"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="cancel_purchase"><input type="hidden" name="id" value="<?= (int)$incident['order_id'] ?>"><input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>">
                      <input type="hidden" name="cancel_reason" value="problema_entrega">
                      <label>Motivo de cancelación<input type="text" name="cancel_detail" minlength="5" maxlength="1000" required placeholder="Explica la razón para cancelar" style="width:100%"></label>
                      <button class="btn btn-secondary incident-cancel-btn" type="submit" onclick="return confirm('¿CANCELAR LA COMPRA? Esto devolverá el stock, cerrará el despacho y podría requerir un reembolso.')">Aprobar incidencia y cancelar compra</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr><?php endforeach; ?>
            <?php if(!$incidents): ?><tr><td colspan="5"><div class="empty-mini">No hay incidencias para mostrar.</div></td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if($total>$perPage): ?><div style="display:flex;gap:12px;margin-top:18px;align-items:center"><a class="btn btn-secondary" href="<?= url('admin/incidencias.php?estado='.$filter.'&pagina='.max(1,$page-1)) ?>">Anterior</a><span>Página <?= $page ?> de <?= max(1,(int)ceil($total/$perPage)) ?></span><a class="btn btn-secondary" href="<?= url('admin/incidencias.php?estado='.$filter.'&pagina='.min((int)ceil($total/$perPage),$page+1)) ?>">Siguiente</a></div><?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
