<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_admin();
if ((string)($_SESSION['admin_role']??'') !== 'admin') {http_response_code(403);exit('Acceso reservado al administrador.');}
$pageTitle='Quejas de compradores';$pageSubtitle='Revisión de reclamos y evidencias';$activePage='complaints';
$message='';$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $id=filter_var($_POST['complaint_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $action=(string)($_POST['decision']??'');
    $note=trim((string)($_POST['admin_note']??''));
    if (!$id || !in_array($action,['valid','rejected'],true) || mb_strlen($note)<8 || mb_strlen($note)>1000) $error='Elige una decisión y escribe un motivo de 8 a 1000 caracteres.';
    else {
        try {
            $pdo=db();$pdo->beginTransaction();
            $q=$pdo->prepare('SELECT id,status,driver_id FROM customer_complaints WHERE id=? FOR UPDATE');$q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);
            if (!$c || $c['status']!=='pending') throw new RuntimeException('Este reclamo ya fue revisado o no existe.');
            $q=$pdo->prepare("UPDATE customer_complaints SET status=?,admin_note=?,reviewed_at=NOW() WHERE id=? AND status='pending'");
            $q->execute([$action,$note,$id]);
            $pdo->commit();
            flash('success',$action==='valid'?'Reclamo validado. No se aplicó ninguna sanción automática.':'Reclamo desestimado con motivo registrado.');
            redirect('admin/quejas.php');
        } catch(Throwable $ex) {if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$error=$ex instanceof RuntimeException?$ex->getMessage():'No se pudo guardar la revisión.';}
    }
}
try {
    $rows=db()->query("SELECT c.id,c.order_id,c.driver_id,c.category,c.description,c.status,c.admin_note,c.photo_mime,c.created_at,c.reviewed_at,d.name AS driver_name,o.order_code FROM customer_complaints c LEFT JOIN delivery_drivers d ON d.id=c.driver_id LEFT JOIN yape_orders o ON o.id=c.order_id ORDER BY (c.status='pending') DESC,c.id DESC LIMIT 250")->fetchAll(PDO::FETCH_ASSOC);
    $sanctions=db()->query("SELECT d.id,d.name,d.active,COUNT(DISTINCT c.order_id) AS validated FROM delivery_drivers d JOIN customer_complaints c ON c.driver_id=d.id AND c.status='valid' GROUP BY d.id,d.name,d.active HAVING COUNT(DISTINCT c.order_id)>=3 ORDER BY validated DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $ex) {$rows=[];$sanctions=[];$error='No existe la tabla de reclamos. Ejecuta database/migracion_quejas_clientes.sql en phpMyAdmin.';error_log($ex->getMessage());}
require __DIR__.'/../includes/admin_header.php';
?>
<style>.qc-shell{max-width:1220px;margin:24px auto;padding:0 16px}.qc-card{background:white;border:1px solid #dde5ee;border-radius:18px;padding:22px;margin-bottom:17px;box-shadow:0 8px 26px rgba(12,30,55,.045)}.qc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:15px}.qc-tag{display:inline-block;border-radius:999px;padding:7px 11px;font-weight:750;font-size:13px;background:#edf3ff;color:#2155a8}.qc-warning{background:#fff0d9;color:#8c4f0e}.qc-good{background:#e1f8ed;color:#126848}.qc-bad{background:#f9eeee;color:#a33a32}.qc-text{font-size:15px;line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere}.qc-card textarea{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px}.qc-card button{border:0;border-radius:10px;padding:12px 15px;font-weight:750;cursor:pointer}.qc-card p,.qc-card small{color:#576982;font-size:14px}.qc-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}</style>
<div class="qc-shell">
 <div class="qc-head"><div><span style="font-size:12px;font-weight:800;color:#0b9885;letter-spacing:.1em">ATENCIÓN AL CLIENTE</span><h1 style="font-size:29px;margin:6px 0">Quejas y reclamos</h1><p>Revisa mensajes y fotografías antes de validar una queja. Ningún reporte cancela pedidos por sí solo.</p></div><a class="btn btn-secondary" href="<?= url('admin/motorizados.php') ?>">Gestionar motorizados</a></div>
 <?php if($error!==''): ?><p role="alert" style="color:#b42318;font-weight:750"><?= e($error) ?></p><?php endif; ?>
 <?php if($sanctions): ?><section class="qc-card" style="border-left:5px solid #f59e0b"><h2 style="font-size:21px">Umbral de revisión: 3 pedidos distintos con quejas validadas</h2><p>Estos motorizados requieren revisión administrativa. Para inhabilitarlos, usa Gestión de motorizados. Si tienen entregas activas, primero reasígnalas.</p><?php foreach($sanctions as $d): ?><p><strong><?= e($d['name']) ?></strong> — <?= (int)$d['validated'] ?> pedidos con quejas validadas · <?= (int)$d['active']===1?'Activo':'Inhabilitado' ?></p><?php endforeach; ?></section><?php endif; ?>
 <div class="qc-card"><strong style="font-size:17px"><?= count($rows) ?> reclamos recientes</strong> · <span class="qc-tag qc-warning"><?= count(array_filter($rows,fn($r)=>$r['status']==='pending')) ?> pendientes</span></div>
 <div class="qc-grid">
 <?php foreach($rows as $c): ?><article class="qc-card"><div class="qc-head"><strong style="font-size:18px">Reclamo #<?= (int)$c['id'] ?></strong><span class="qc-tag <?= $c['status']==='pending'?'qc-warning':($c['status']==='valid'?'qc-good':'qc-bad') ?>"><?= e(['pending'=>'Pendiente','valid'=>'Validada','rejected'=>'No procedente'][$c['status']]??'') ?></span></div>
 <p><strong>Pedido:</strong> <?= e($c['order_code']??'#'.$c['order_id']) ?><br><strong>Motorizado:</strong> <?= e($c['driver_name']??'No indicado') ?><br><strong>Categoría:</strong> <?= e($c['category']) ?><br><strong>Enviado:</strong> <?= e($c['created_at']) ?></p>
 <div class="qc-text"><?= e($c['description']) ?></div>
 <?php if($c['photo_mime']): ?><p><a href="<?= url('admin/queja_foto.php?id='.(int)$c['id']) ?>" target="_blank" rel="noopener">Ver fotografía adjunta</a></p><?php endif; ?>
 <?php if($c['status']==='pending'): ?><form method="post" style="margin-top:18px;display:grid;gap:9px"><?= csrf_field() ?><input type="hidden" name="complaint_id" value="<?= (int)$c['id'] ?>"><label style="font-size:14px;font-weight:750">Justificación administrativa<textarea name="admin_note" required minlength="8" maxlength="1000" rows="3" placeholder="Indica la razón de tu decisión"></textarea></label><div style="display:flex;gap:8px;flex-wrap:wrap"><button type="submit" name="decision" value="valid" style="background:#0ea987;color:white" onclick="return confirm('¿Validar esta queja? Se contabilizará para la revisión del motorizado.')">Validar queja</button><button type="submit" name="decision" value="rejected" style="background:#e9eef5;color:#293a52" onclick="return confirm('¿Marcar esta queja como no procedente?')">No procede</button></div></form>
 <?php else: ?><p><strong>Decisión administrativa:</strong> <?= e($c['admin_note']??'') ?><br><small><?= e($c['reviewed_at']??'') ?></small></p><?php endif; ?></article><?php endforeach; ?>
 </div><?php if(!$rows): ?><div class="qc-card">No hay reclamos registrados por compradores.</div><?php endif; ?>
</div>
<?php require __DIR__.'/../includes/admin_footer.php'; ?>
