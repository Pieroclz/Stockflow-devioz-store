<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
$driver = driver_require();
$driverId = (int)$driver['id'];
// El filtro por driver_id evita mostrar las entregas de otros repartidores.
$q = db()->prepare("SELECT o.id,o.order_code,o.customer_name,o.delivery_address,o.delivery_reference,o.created_at,o.fulfillment_status,a.claimed_at,a.finished_at,
 (SELECT COALESCE(SUM(i.quantity),0) FROM yape_order_items i WHERE i.order_id=o.id) AS units,
 (SELECT COUNT(*) FROM yape_order_items i WHERE i.order_id=o.id) AS item_lines
 FROM delivery_assignments a INNER JOIN yape_orders o ON o.id=a.order_id
 WHERE a.driver_id=? AND (o.fulfillment_status='entregado' OR a.delivery_status='delivered')
 ORDER BY COALESCE(a.finished_at,o.created_at) DESC,o.id DESC LIMIT 200");
try {
 $sql="SELECT o.id,o.order_code,o.customer_name,o.delivery_address,o.delivery_reference,o.created_at,o.fulfillment_status,a.claimed_at,a.finished_at,
 (SELECT COALESCE(SUM(i.quantity),0) FROM yape_order_items i WHERE i.order_id=o.id) AS units,
 (SELECT COUNT(*) FROM yape_order_items i WHERE i.order_id=o.id) AS item_lines
 FROM delivery_assignments a JOIN yape_orders o ON o.id=a.order_id WHERE a.driver_id=? AND a.delivery_status='delivered'
 UNION ALL
 SELECT o.id,o.order_code,o.customer_name,o.delivery_address,o.delivery_reference,o.created_at,o.fulfillment_status,a.claimed_at,a.finished_at,
 (SELECT COALESCE(SUM(i.quantity),0) FROM yape_order_items i WHERE i.order_id=o.id),
 (SELECT COUNT(*) FROM yape_order_items i WHERE i.order_id=o.id)
 FROM delivery_extra_assignments a JOIN yape_orders o ON o.id=a.order_id WHERE a.driver_id=? AND a.delivery_status='delivered'
 ORDER BY finished_at DESC LIMIT 200";
 $q=db()->prepare($sql);$q->execute([$driverId,$driverId]);$orders=$q->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $ignored) { $q->execute([$driverId]);$orders=$q->fetchAll(PDO::FETCH_ASSOC); }
$deliveredCount=count($orders);
function history_date($date): string {
 if (!$date) return 'Hora de entrega no registrada';
 $time=strtotime((string)$date);
 return $time===false?'Hora de entrega no registrada':date('d/m/Y · H:i',$time);
}
driver_page_start('Mis entregas anteriores');
?>
<style>
.history-back{display:inline-flex;align-items:center;gap:9px;margin:0 0 20px;padding:12px 18px;background:#ffe3c8;color:#9c4d17;border:1px solid #f6bf8d;border-radius:11px;font-size:14px;font-weight:800;text-decoration:none;transition:background .2s,transform .2s}.history-back:hover,.history-back:focus-visible{background:#ffcb99;transform:translateX(-3px)}
.history-hero{background:linear-gradient(110deg,#a44c1c,#f4a563);color:#fff;border-radius:20px;padding:25px;margin-bottom:18px;display:flex;justify-content:space-between;gap:15px;align-items:center}
.history-hero h2{margin:5px 0;font-size:23px}.history-hero p{margin:0;color:#fff2e6}.history-total{background:#ffffff29;border:1px solid #ffffff55;border-radius:15px;padding:13px 20px;text-align:center;min-width:120px}.history-total strong{display:block;font-size:32px}
.history-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.history-list article{margin:0;min-width:0}.history-list p{margin:9px 0;overflow-wrap:anywhere}.history-meta{color:#835337;font-size:13px}.history-tag{background:#e8f8ed;color:#157348;border-radius:100px;padding:7px 11px;font-weight:800;font-size:11px}.history-details{display:inline-block;background:#ffb36d;color:#552909;border-radius:10px;padding:12px 16px;font-weight:800;margin-top:10px}
@media(max-width:700px){.history-list{grid-template-columns:1fr}.history-hero{align-items:flex-start;flex-direction:column}}
</style>
<a class="history-back" href="index.php" aria-label="Volver a mis pedidos">← Volver a mis pedidos</a>
<section class="history-hero"><div><span style="font-size:11px;letter-spacing:2px;font-weight:800">DEVIOZ · DELIVERY</span><h2>Tu historial de repartos 🛵</h2><p>Revisa los pedidos que ya entregaste.</p></div><div class="history-total"><strong><?= $deliveredCount ?></strong><span>entregas mostradas</span></div></section>
<p class="muted">Se muestran hasta las últimas 200 entregas registradas en tu cuenta.</p>
<?php if (!$orders): ?>
<section class="empty"><div class="symbol">📦</div><h2>Todavía no tienes entregas completadas</h2><p>Cuando finalices un pedido, aparecerá aquí.</p><a class="btn" href="index.php">Volver a mis pedidos</a></section>
<?php else: ?><section class="history-list">
<?php foreach ($orders as $o): ?>
<article class="card">
 <div class="card-top"><span class="order-id">PEDIDO #<?= (int)$o['id'] ?></span><span class="history-tag">✓ Finalizado</span></div>
 <h2><?= e($o['order_code'] ?: 'Pedido sin código') ?></h2>
 <p class="history-meta">🕒 <?= e(history_date($o['finished_at'])) ?></p>
 <p><strong>Cliente:</strong> <?= e($o['customer_name'] ?: 'Sin nombre') ?></p>
 <p><strong>Dirección:</strong> <?= e($o['delivery_address'] ?: 'No registrada') ?></p>
 <?php if (trim((string)$o['delivery_reference']) !== ''): ?><p><strong>Referencia:</strong> <?= e($o['delivery_reference']) ?></p><?php endif; ?>
 <p><strong>Productos:</strong> <?= (int)$o['units'] ?> unidad(es) en <?= (int)$o['item_lines'] ?> línea(s)</p>
 <a class="history-details" href="historial_detalle.php?id=<?= (int)$o['id'] ?>">Ver detalles →</a>
</article>
<?php endforeach; ?></section><?php endif; ?>
<?php driver_page_end(); ?>
