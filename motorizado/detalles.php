<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
$driver = driver_require();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(404); exit('Pedido no encontrado.'); }
$q = db()->prepare("SELECT o.*, z.district AS delivery_district, t.destination_lat, t.destination_lng,
    a.driver_id AS assigned_driver, a.finished_at AS assignment_finished, a.delivery_status AS delivery_status, a.delivery_notes AS delivery_notes
    FROM yape_orders o
    LEFT JOIN delivery_zones z ON z.id=o.delivery_zone_id
    LEFT JOIN delivery_tracking t ON t.order_id=o.id
    LEFT JOIN delivery_assignments a ON a.order_id=o.id
    WHERE o.id=? LIMIT 1");
$q->execute([$id]);
$o = $q->fetch(PDO::FETCH_ASSOC);
$isExtra=false;
if ($o && (int)($o['assigned_driver']??0)!==(int)$driver['id']) {
    try {
        $extra=db()->prepare('SELECT cargo_description,delivery_status,finished_at FROM delivery_extra_assignments WHERE order_id=? AND driver_id=?');
        $extra->execute([$id,(int)$driver['id']]);$extraInfo=$extra->fetch(PDO::FETCH_ASSOC);
        if($extraInfo) { $isExtra=true;$o['assigned_driver']=(int)$driver['id'];$o['assignment_finished']=$extraInfo['finished_at'];$o['delivery_status']=$extraInfo['delivery_status'];$o['cargo_description']=$extraInfo['cargo_description']; }
    } catch (PDOException $ignored) {}
}
if (!$o || $o['status'] !== 'aprobado' ||
    (int)($o['assigned_driver'] ?? 0) !== (int)$driver['id'] ||
    $o['assignment_finished'] !== null ||
    !in_array((string)$o['fulfillment_status'], ['listo','recibido','en_preparacion'], true)) {
    http_response_code(403);
    exit('Este pedido no está disponible para tu cuenta.');
}
$items = db()->prepare('SELECT item_name, quantity, subtotal FROM yape_order_items WHERE order_id=? ORDER BY id');
$items->execute([$id]);
$products = $items->fetchAll(PDO::FETCH_ASSOC);
$address = trim((string)($o['delivery_address'] ?? ''));
$reference = trim((string)($o['delivery_reference'] ?? ''));
$phone = trim((string)($o['customer_phone'] ?? ''));
$phoneDigits = preg_replace('/[^0-9+]/', '', $phone);
$lat = $o['destination_lat'] ?? null;
$lng = $o['destination_lng'] ?? null;
$hasCoordinates = is_numeric($lat) && is_numeric($lng) && abs((float)$lat)<=90 && abs((float)$lng)<=180;
$mapQuery = $hasCoordinates ? ((string)$lat . ',' . (string)$lng) : $address;
$mapUrl = $mapQuery !== '' ? 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($mapQuery) . '&travelmode=driving' : '';
$method = (string)($o['metodo_pago'] ?? '');
$district = trim((string)($o['delivery_district'] ?? ''));
$deliveryFee = (float)($o['delivery_fee'] ?? 0);
$subtotalAmount = (float)($o['subtotal_amount'] ?? 0);
$discountAmount = (float)($o['discount_amount'] ?? 0);
$expectedAmount = (float)($o['expected_amount'] ?? 0);
$couponCode = trim((string)($o['coupon_code'] ?? ''));

driver_page_start('Detalles del pedido #' . $id);
?>
<style>
.driver-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:18px 0}
.driver-details article{margin:0;padding:20px;min-width:0}
.driver-details .wide{grid-column:1/-1}
.driver-details h2{font-size:17px;margin:0 0 12px}
.driver-details p{overflow-wrap:anywhere;line-height:1.6}
.driver-details ul{list-style:none;padding:0;margin:0}
.driver-details li{display:flex;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid #e5e7eb}
.driver-details .action-link{display:inline-block;padding:11px 15px;border-radius:10px;background:#19cda0;color:#082b24;font-weight:700;text-decoration:none;margin:8px 8px 0 0}
.payment-breakdown{margin-top:14px;border-top:1px solid #e5e7eb;padding-top:8px}.payment-breakdown p{display:flex;justify-content:space-between;gap:12px;margin:8px 0}.payment-breakdown .payment-total{border-top:1px solid #e5e7eb;padding-top:12px;font-weight:700}
@media(max-width:680px){.driver-details{grid-template-columns:1fr}}
</style>
<p><a href="index.php">← Volver a mis pedidos</a></p>
<section class="driver-details">
<article><h2>👤 Cliente</h2>
<p><strong><?= e($o['customer_name'] ?? 'Cliente') ?></strong></p>
<p>Teléfono: <?php if($phone !== ''): ?><?= e($phone) ?><?php if($phoneDigits !== ''): ?> · <a href="tel:<?= e($phoneDigits) ?>">Llamar</a><?php endif; ?><?php else: ?>No registrado<?php endif; ?></p>
<p>Pedido: <?= e($o['order_code'] ?? '') ?></p>
</article>
<article><h2>💳 Pago</h2>
<p>Método: <strong><?= e($method !== '' ? payment_label($method) : 'No registrado') ?></strong></p>
<p>Estado: <?= e($o['status'] ?? '') ?></p>
<div class="payment-breakdown">
<p>Productos: <strong><?= money($subtotalAmount) ?></strong></p>
<p>Delivery: <strong><?= money($deliveryFee) ?></strong></p>
<?php if ($discountAmount > 0): ?><p>Descuento<?= $couponCode !== '' ? ' (' . e($couponCode) . ')' : '' ?>: <strong>− <?= money($discountAmount) ?></strong></p><?php endif; ?>
<p class="payment-total">Total pagado: <strong><?= money($expectedAmount) ?></strong></p>
</div>
</article>
<article class="wide"><h2>📍 Dirección de entrega</h2>
<p>Zona de delivery: <strong><?= e($district !== '' ? $district : 'No registrada') ?></strong></p>
<p>Tarifa de la zona: <strong><?= money($deliveryFee) ?></strong></p>
<p><?= e($address !== '' ? $address : 'Dirección no registrada') ?></p>
<?php if($reference !== ''): ?><p>Referencia: <?= e($reference) ?></p><?php endif; ?>
<?php if($mapUrl !== ''): ?><a class="action-link" href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer">Navegar con Google Maps ↗</a><?php endif; ?>
</article>
<article class="wide"><h2>🛵 Estado logístico</h2><p>Estado actual: <strong><?= e((string)($o['delivery_status'] ?? 'assigned')) ?></strong></p><?php if (!empty($o['delivery_notes'])): ?><p>Incidencia: <?= e((string)$o['delivery_notes']) ?></p><?php endif; ?><p>La entrega debe avanzar desde el panel principal siguiendo el orden: asignado, recogido en almacén, en camino y entregado.</p></article>
<article class="wide"><h2>📦 Tu parte del pedido</h2><p><?= e((string)($o['cargo_description'] ?? 'Consulta con almacén qué paquetes te corresponden.')) ?></p></article>
<article class="wide"><h2>📦 Productos del pedido completo (referencia)</h2><p>Puede haber otros repartidores asignados; no recojas todos los productos sin verificar tu parte con el almacén.</p>
<?php if($products): ?><ul>
<?php foreach($products as $item): ?><li><span><?= (int)$item['quantity'] ?> × <?= e($item['item_name']) ?></span><strong><?= money((float)$item['subtotal']) ?></strong></li><?php endforeach; ?>
</ul><?php else: ?><p>No hay productos registrados.</p><?php endif; ?>
</article>
</section>
<?php driver_page_end(); ?>
