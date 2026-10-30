<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
$driver = driver_require();
$orderId = filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if (!$orderId || $orderId < 1) {http_response_code(404);exit('Pedido no encontrado.');}
$q=db()->prepare("SELECT o.id,o.order_code,o.customer_name,o.delivery_address,o.delivery_reference,o.created_at,o.fulfillment_status,a.claimed_at,a.finished_at
 FROM delivery_assignments a INNER JOIN yape_orders o ON o.id=a.order_id
 WHERE a.driver_id=? AND a.order_id=? AND (o.fulfillment_status='entregado' OR a.delivery_status='delivered') LIMIT 1");
$q->execute([(int)$driver['id'],$orderId]);$order=$q->fetch(PDO::FETCH_ASSOC);
if (!$order){http_response_code(404);exit('Esta entrega no está disponible en tu historial.');}
$items=db()->prepare('SELECT item_name,quantity,subtotal FROM yape_order_items WHERE order_id=? ORDER BY id');
$items->execute([$orderId]);$products=$items->fetchAll(PDO::FETCH_ASSOC);
function history_detail_date($value):string{
 if (!$value)return 'No registrada';$t=strtotime((string)$value);return $t?date('d/m/Y · H:i',$t):'No registrada';
}
driver_page_start('Entrega completada #' . $orderId);
?>
<style>.history-info{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.history-info article{margin:0}.history-info .full{grid-column:1/-1}.history-products{list-style:none;margin:0;padding:0}.history-products li{display:flex;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #f1dcc9}.history-products li:last-child{border:0}@media(max-width:700px){.history-info{grid-template-columns:1fr}.history-info .full{grid-column:auto}}</style>
<p><a href="historial.php">← Volver a mis entregas</a></p>
<div class="notice">✓ Esta entrega figura como finalizada.</div>
<section class="history-info">
 <article class="card"><h2>📦 Pedido</h2><p><strong><?= e($order['order_code'] ?: 'Sin código') ?></strong></p><p>Pedido #<?= (int)$order['id'] ?></p><p>Solicitado: <?= e(history_detail_date($order['created_at'])) ?></p><p>Aceptado: <?= e(history_detail_date($order['claimed_at'])) ?></p><p>Entregado: <strong><?= e(history_detail_date($order['finished_at'])) ?></strong></p></article>
 <article class="card"><h2>📍 Destino</h2><p><strong>Cliente:</strong> <?= e($order['customer_name'] ?: 'No registrado') ?></p><p><strong>Dirección:</strong> <?= e($order['delivery_address'] ?: 'No registrada') ?></p><?php if(trim((string)$order['delivery_reference'])!==''): ?><p><strong>Referencia:</strong> <?= e($order['delivery_reference']) ?></p><?php endif; ?></article>
 <article class="card full"><h2>🛍️ Productos entregados</h2><?php if ($products): ?><ul class="history-products"><?php foreach ($products as $item): ?><li><span><?= (int)$item['quantity'] ?> × <?= e($item['item_name']) ?></span><strong><?= money((float)$item['subtotal']) ?></strong></li><?php endforeach; ?></ul><?php else: ?><p>Este pedido no tiene productos detallados.</p><?php endif; ?></article>
</section>
<?php driver_page_end(); ?>
