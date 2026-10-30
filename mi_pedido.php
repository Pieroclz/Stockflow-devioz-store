<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$requestedOrderId = max(0, (int)($_GET['id'] ?? 0));
$orderId = $requestedOrderId > 0 && is_customer()
    ? $requestedOrderId
    : (int) ($_SESSION['customer_order_id'] ?? 0);
if ($orderId <= 0) redirect('pedido_login.php');

$statement = $requestedOrderId > 0
    ? db()->prepare('SELECT * FROM yape_orders WHERE id = ? AND customer_id = ? LIMIT 1')
    : db()->prepare('SELECT * FROM yape_orders WHERE id = ? LIMIT 1');
$statement->execute($requestedOrderId > 0 ? [$orderId, customer_id()] : [$orderId]);
$order = $statement->fetch();
if (!$order) {
    unset($_SESSION['customer_order_id']);
    redirect('pedido_login.php');
}

// Reclamos: un envio no cambia el estado del pedido ni sanciona a nadie.
$complaintError = '';
$complaintSuccess = isset($_GET['queja_enviada']);
$myComplaints = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_complaint') {
    $inputToken = (string)($_POST['csrf_token'] ?? '');
    $description = trim((string)($_POST['complaint_description'] ?? ''));
    $category = (string)($_POST['complaint_category'] ?? 'entrega');
    $driverId = (int)($_POST['complaint_driver'] ?? 0);
    $validCategories = ['entrega','producto','trato','otro'];
    if (!hash_equals(csrf_token(), $inputToken)) $complaintError = 'La sesión caducó. Actualiza la página e inténtalo nuevamente.';
    elseif (!in_array($category, $validCategories, true)) $complaintError = 'Selecciona un tipo de problema válido.';
    elseif (mb_strlen($description) < 15 || mb_strlen($description) > 2000) $complaintError = 'Describe el problema entre 15 y 2000 caracteres.';
    else {
        try {
            // No se puede acusar a cualquier motorizado: debe figurar en el historial de este pedido.
            if ($driverId > 0) {
                $assigned = db()->prepare("SELECT driver_id FROM delivery_assignments WHERE order_id=? AND driver_id=? UNION SELECT driver_id FROM delivery_extra_assignments WHERE order_id=? AND driver_id=? LIMIT 1");
                $assigned->execute([$orderId,$driverId,$orderId,$driverId]);
                if (!$assigned->fetchColumn()) throw new RuntimeException('Selecciona un motorizado vinculado a esta compra.');
            }
            // Limitar mensajes repetidos de una misma compra (maximo 3 en 24 horas).
            $rate=db()->prepare('SELECT COUNT(*) FROM customer_complaints WHERE order_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)');
            $rate->execute([$orderId]);
            if ((int)$rate->fetchColumn() >= 3) throw new RuntimeException('Alcanzaste el límite de tres reclamos en 24 horas para esta compra.');
            $photoData=null;$photoMime=null;
            if (isset($_FILES['complaint_photo']) && (int)($_FILES['complaint_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $f=$_FILES['complaint_photo'];
                if ((int)$f['error'] !== UPLOAD_ERR_OK || (int)$f['size'] > 4*1024*1024 || (int)$f['size'] <= 0 || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('La foto no se pudo subir o supera los 4 MB.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                if (!in_array($mime,['image/jpeg','image/png','image/webp'],true)) throw new RuntimeException('Solo se permiten fotografías JPG, PNG o WebP.');
                $photoData=file_get_contents($f['tmp_name']);$photoMime=$mime;
                if ($photoData===false) throw new RuntimeException('No fue posible leer la fotografía.');
            }
            $insert=db()->prepare('INSERT INTO customer_complaints(order_id,driver_id,category,description,photo_data,photo_mime) VALUES(?,?,?,?,?,?)');
            $insert->bindValue(1,$orderId,PDO::PARAM_INT);
            $insert->bindValue(2,$driverId>0?$driverId:null,$driverId>0?PDO::PARAM_INT:PDO::PARAM_NULL);
            $insert->bindValue(3,$category);$insert->bindValue(4,$description);
            $insert->bindValue(5,$photoData,$photoData===null?PDO::PARAM_NULL:PDO::PARAM_LOB);
            $insert->bindValue(6,$photoMime,$photoMime===null?PDO::PARAM_NULL:PDO::PARAM_STR);
            $insert->execute();
            header('Location: '.url('mi_pedido.php'.($requestedOrderId>0?'?id='.$orderId.'&queja_enviada=1':'?queja_enviada=1')));exit;
        } catch (RuntimeException $ex) {$complaintError=$ex->getMessage();}
          catch (Throwable $ex) {error_log('StockFlow complaint: '.$ex->getMessage());$complaintError='No se pudo guardar el reclamo. Comprueba que se haya ejecutado la migración de quejas.';}
    }
}
try {
    $cq=db()->prepare('SELECT id,category,description,status,created_at FROM customer_complaints WHERE order_id=? ORDER BY id DESC LIMIT 15');
    $cq->execute([$orderId]);$myComplaints=$cq->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {error_log('StockFlow complaints unavailable: '.$ex->getMessage());}

$itemsStatement = db()->prepare('SELECT item_name, quantity, presentation, units_per_item, unit_price, subtotal FROM yape_order_items WHERE order_id = ? ORDER BY id');
$itemsStatement->execute([$orderId]);
$items = $itemsStatement->fetchAll();

// Consultar la asignacion con los mismos campos que utiliza mi_cuenta.php.
// Los campos adicionales son opcionales para admitir instalaciones anteriores.
$assignedDriver = null;
try {
    $driverQuery = db()->prepare(
        'SELECT a.driver_id, d.name, d.avatar_path
         FROM delivery_assignments a
         INNER JOIN delivery_drivers d ON d.id = a.driver_id
         WHERE a.order_id = ? AND a.finished_at IS NULL
         ORDER BY a.claimed_at DESC LIMIT 1'
    );
    $driverQuery->execute([$orderId]);
    $assignedDriver = $driverQuery->fetch() ?: null;
    if (!$assignedDriver) {
        error_log('Devioz: no se encontro motorizado vinculado al pedido interno ' . $orderId . ' (' . (string)($order['order_code'] ?? '') . ')');
    }
    if ($assignedDriver) {
        $avatar = (string) ($assignedDriver['avatar_path'] ?? '');
        $assignedDriver['avatar_path'] = preg_match('~^uploads/driver_avatars/[a-f0-9]{36}\.(?:jpg|png|webp)$~', $avatar) ? $avatar : '';
        $assignedDriver['phone'] = '';
        $assignedDriver['claimed_at'] = null;
        // Los esquemas antiguos pueden no tener telefono o fecha de asignacion.
        try {
            $phoneQuery = db()->prepare('SELECT phone FROM delivery_drivers WHERE id = ? LIMIT 1');
            $phoneQuery->execute([(int) $assignedDriver['driver_id']]);
            $assignedDriver['phone'] = (string) ($phoneQuery->fetchColumn() ?: '');
        } catch (Throwable $ignored) {
            // Mantener nombre y foto aunque no exista la columna phone.
        }
        try {
            $dateQuery = db()->prepare('SELECT claimed_at FROM delivery_assignments WHERE order_id = ? LIMIT 1');
            $dateQuery->execute([$orderId]);
            $assignedDriver['claimed_at'] = $dateQuery->fetchColumn() ?: null;
        } catch (Throwable $ignored) {
            // Mantener la tarjeta aunque no exista claimed_at.
        }
    }
} catch (Throwable $exception) {
    error_log('Devioz: no se pudo consultar el motorizado del pedido ' . $orderId . ': ' . $exception->getMessage());
    $assignedDriver = null;
}

// Los despachos adicionales se consultan separadamente para conservar pedidos anteriores.
$allDrivers=[];
try {
    $sq=db()->prepare("SELECT d.id,d.name,d.phone,a.delivery_status,a.finished_at,COALESCE(pc.cargo_description,'Carga principal') AS cargo_description
        FROM delivery_assignments a JOIN delivery_drivers d ON d.id=a.driver_id LEFT JOIN delivery_primary_cargo pc ON pc.order_id=a.order_id WHERE a.order_id=?
        UNION ALL SELECT d.id,d.name,d.phone,a.delivery_status,a.finished_at,a.cargo_description
        FROM delivery_extra_assignments a JOIN delivery_drivers d ON d.id=a.driver_id WHERE a.order_id=?");
    $sq->execute([$orderId,$orderId]);$allDrivers=$sq->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ignored) { if ($assignedDriver) $allDrivers=[['id'=>$assignedDriver['driver_id'],'name'=>$assignedDriver['name'],'phone'=>$assignedDriver['phone']??'','delivery_status'=>'assigned','cargo_description'=>'Entrega asignada','finished_at'=>null]]; }
$shipmentTotal=count($allDrivers);$shipmentDelivered=0;
foreach ($allDrivers as $shipment) if (($shipment['delivery_status'] ?? '')==='delivered') $shipmentDelivered++;
$fulfillment = (string) ($order['fulfillment_status'] ?? 'recibido');
$isExpressOrder = (string) ($order['tipo_despacho'] ?? 'preparacion') === 'entrega_rapida';
$paymentStatus = (string) $order['status'];
// Una cancelacion de despacho no equivale a un rechazo del pago.
$cancellation = null;
if ($fulfillment === 'cancelado') {
    try {
        $cancelQuery = db()->prepare('SELECT reason_type, reason_detail, created_at FROM admin_order_cancellations WHERE order_id = ? ORDER BY id DESC LIMIT 1');
        $cancelQuery->execute([$orderId]);
        $cancellation = $cancelQuery->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $exception) {
        error_log('StockFlow: no se pudo leer el motivo de cancelacion del pedido ' . $orderId . ': ' . $exception->getMessage());
    }
}
$cancelReasonLabels = [
    'solicitud_cliente' => 'Solicitud del comprador',
    'sin_stock' => 'Falta de existencias',
    'problema_pago' => 'Problema con el pago',
    'problema_entrega' => 'Incidencia con la entrega',
    'otro' => 'Otro motivo administrativo',
];
$cancelReasonType = (string) ($cancellation['reason_type'] ?? '');
$cancelReasonLabel = $cancelReasonLabels[$cancelReasonType] ?? 'Cancelacion administrativa';
$cancelReasonDetail = trim((string) ($cancellation['reason_detail'] ?? ''));
$statusLabels = [
    'recibido' => ['Pedido recibido', 'Recibimos tu comprobante y pedido.'],
    'en_preparacion' => ['En preparación', 'Estamos preparando tus productos.'],
    'listo' => ['Pedido listo', 'Tu pedido está listo para ser entregado.'],
    'entregado' => ['Pedido entregado', 'Tu compra fue entregada correctamente.'],
    'cancelado' => ['Pedido cancelado', 'El pedido no continuará.'],
];
if ($fulfillment === 'cancelado') {
    $current = ['Pedido cancelado', $cancelReasonDetail !== '' ? $cancelReasonDetail : 'El pedido fue cancelado. Comunicate con la tienda si necesitas mas informacion.'];
} elseif ($paymentStatus === 'pendiente') $current = ['Pago en revisión', 'Estamos verificando el comprobante que enviaste.'];
elseif ($paymentStatus === 'rechazado') $current = ['Pago no aprobado', 'Comunícate con la tienda si necesitas ayuda.'];
else $current = $statusLabels[$fulfillment] ?? $statusLabels['recibido'];

$steps = $isExpressOrder
    ? ['recibido', 'listo', 'entregado']
    : ['recibido', 'en_preparacion', 'listo', 'entregado'];
$currentIndex = array_search($fulfillment, $steps, true);
$currentIndex = $currentIndex === false ? 0 : $currentIndex;

$canReview = $paymentStatus === 'aprobado' && $fulfillment === 'entregado';
$feedbackError = '';
$feedbackSaved = isset($_GET['opinion_guardada']);
$existingFeedback = null;
if ($canReview) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_feedback') {
        $token = (string) ($_POST['csrf_token'] ?? '');
        $rating = (int) ($_POST['rating'] ?? 0);
        $comment = trim((string) ($_POST['comment'] ?? ''));
        if (!hash_equals(csrf_token(), $token)) $feedbackError = 'Sesión expirada. Actualiza la página.';
        elseif ($rating < 1 || $rating > 5) $feedbackError = 'Selecciona entre 1 y 5 estrellas.';
        elseif (mb_strlen($comment) > 1000) $feedbackError = 'El comentario supera los 1000 caracteres.';
        else {
            $save = db()->prepare('INSERT INTO customer_feedback (order_id, rating, comment) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rating=VALUES(rating), comment=VALUES(comment), updated_at=CURRENT_TIMESTAMP');
            $save->execute([$orderId, $rating, $comment]);
            header('Location: ' . url('mi_pedido.php' . ($requestedOrderId > 0 ? '?id=' . $orderId . '&opinion_guardada=1' : '?opinion_guardada=1')));
            exit;
        }
    }
    $feedbackQuery = db()->prepare('SELECT rating, comment FROM customer_feedback WHERE order_id=? LIMIT 1');
    $feedbackQuery->execute([$orderId]);
    $existingFeedback = $feedbackQuery->fetch() ?: null;
}

$pageTitle = 'Estado de mi pedido';
require __DIR__ . '/includes/public_header.php';
?>
<section class="order-tracking-section">
    <div class="container order-tracking-shell">
        <header class="order-tracking-head">
            <div><span>SEGUIMIENTO DEL PEDIDO</span><h1><?= e($current[0]) ?></h1><p><?= e($current[1]) ?></p></div>
            <a href="<?= url('pedido_salir.php') ?>">Salir</a>
        </header>
        <?php if ($fulfillment === 'cancelado'): ?>
          <section role="status" style="margin:16px 0;padding:18px 20px;border:1px solid #fecaca;background:#fff7f7;border-radius:16px;color:#7f1d1d;">
            <strong style="display:block;font-size:16px;margin-bottom:6px;">Motivo de la cancelación: <?= e($cancelReasonLabel) ?></strong>
            <p style="margin:0;white-space:pre-wrap;overflow-wrap:anywhere;"><?= e($cancelReasonDetail !== '' ? $cancelReasonDetail : 'La tienda canceló tu pedido. Comunícate con el establecimiento para conocer el motivo.') ?></p>
            <?php if (!empty($cancellation['created_at'])): ?><small style="display:block;margin-top:9px;opacity:.8;">Registrado el <?= e((string) $cancellation['created_at']) ?></small><?php endif; ?>
            <small style="display:block;margin-top:8px;opacity:.8;">Si ya realizaste un pago, contacta a la tienda para coordinar su revisión o devolución.</small>
          </section>
        <?php endif; ?>


        <?php if (($paymentStatus !== 'rechazado' && $fulfillment !== 'cancelado')): ?>
            <div class="order-progress" aria-label="Progreso del pedido">
                <?php foreach ($steps as $index => $step): ?>
                    <article class="<?= $paymentStatus === 'aprobado' && $index <= $currentIndex ? 'is-complete' : ($index === 0 ? 'is-current' : '') ?>">
                        <i><?= $index < $currentIndex && $paymentStatus === 'aprobado' ? '✓' : $index + 1 ?></i>
                        <span><?= e($statusLabels[$step][0]) ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (($paymentStatus !== 'rechazado' && $fulfillment !== 'cancelado')): ?>
          <div id="mapa-entrega" class="sf-map-customer" style="width:100%;clear:both;margin:20px 0 26px">
            <?php $trackingOrderId=(int)$order['id'];require __DIR__.'/includes/delivery_map_widget.php'; ?>
          </div>
        <?php endif; ?>



                 <style>
         .tracking-driver-mini{margin-top:18px;padding:15px;border-radius:15px;background:#fff6ec;border:1px solid #f6d7b5;text-align:left}
         .tracking-driver-label{display:block;font-size:11px;font-weight:800;letter-spacing:1px;color:#a75816;margin-bottom:11px}
         .tracking-driver-person{display:flex;align-items:center;gap:11px;min-width:0}
         .tracking-driver-person img,.tracking-driver-initial{width:54px;height:54px;flex:0 0 54px;object-fit:cover;border-radius:50%;background:#ffe2c3;border:2px solid #ffc98d}
         .tracking-driver-initial{display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#9a4a16}
         .tracking-driver-person strong{display:block;font-size:16px;color:#432b1c;overflow-wrap:anywhere}
         .tracking-driver-person small,.tracking-driver-muted{display:block;color:#87674d;font-size:12px;line-height:1.5}
         .tracking-driver-call{display:inline-block;margin-top:12px;padding:9px 12px;border-radius:10px;background:#f6ad55;color:#3b220c!important;font-size:13px;font-weight:800;text-decoration:none}
         </style>
         <div class="order-customer-grid">
            <section class="order-customer-card">
                <div class="order-code-line"><span><?= e($order['order_code']) ?></span><b class="status-chip status-<?= e($fulfillment === 'cancelado' ? 'rechazado' : $paymentStatus) ?>"><?= e($fulfillment === 'cancelado' ? 'Cancelado' : ucfirst($paymentStatus)) ?></b></div>
                <h2>Productos de tu pedido</h2>
                <div class="customer-order-items">
                    <?php foreach ($items as $item): ?>
                        <article><div><strong><?= e($item['item_name']) ?></strong><small><?= (int) $item['quantity'] ?> × <?= money($item['unit_price']) ?><?= $item['presentation'] === 'paquete' ? ' · pack de ' . (int) $item['units_per_item'] : '' ?></small></div><b><?= money($item['subtotal']) ?></b></article>
                    <?php endforeach; ?>
                </div>
                <div class="order-price-breakdown">
                    <span>Subtotal <b><?= money($order['subtotal_amount'] ?? $order['expected_amount']) ?></b></span>
                    <?php if((float)($order['delivery_fee'] ?? 0)>0): ?><span>Delivery <b><?= money($order['delivery_fee']) ?></b></span><?php endif ?>
                    <?php if((float)($order['discount_amount'] ?? 0)>0): ?><span>Descuento<?= !empty($order['coupon_code'])?' · '.e($order['coupon_code']):'' ?> <b>-<?= money($order['discount_amount']) ?></b></span><?php endif ?>
                </div>
                <footer><span>Total pagado</span><strong><?= money($order['expected_amount']) ?></strong></footer>
            </section>
            <aside class="order-customer-summary">
                <span class="order-status-icon"><?= $fulfillment === 'entregado' ? '✓' : '⌛' ?></span>
                <small>ESTADO ACTUAL</small><strong><?= e($current[0]) ?></strong><p><?= e($current[1]) ?></p>
                <dl><div><dt>Pedido</dt><dd><?= e($order['order_code']) ?></dd></div><div><dt>Fecha</dt><dd><?= e(date('d/m/Y H:i', strtotime($order['created_at']))) ?></dd></div><?php if(!empty($order['delivery_address'])): ?><div><dt>Entrega</dt><dd><?= e($order['delivery_address']) ?></dd></div><?php endif ?></dl>
                                 <?php if ($fulfillment !== 'cancelado' && ($paymentStatus !== 'rechazado' && $fulfillment !== 'cancelado')): ?>
                 <?php if ($shipmentTotal > 1): ?>
                 <section style="background:#eff7ff;border:1px solid #b9d8fa;border-radius:14px;padding:15px;margin:12px 0;color:#193a60">
                 <strong style="font-size:17px">🛵 Tu compra llegará con <?= $shipmentTotal ?> motorizados</strong>
                 <p style="font-size:14px;margin:8px 0"><?= $shipmentDelivered ?> de <?= $shipmentTotal ?> entregas completadas. La compra se completa cuando todas las partes sean entregadas. El mapa actual sigue al motorizado principal.</p>
                 <?php foreach ($allDrivers as $j=>$shipment): ?><div style="border-top:1px solid #c5d9ec;padding:10px 0;font-size:14px">
                 <b><?= ($j+1) ?>. <?= e((string)$shipment['name']) ?></b> — <?= e($shipment['delivery_status']==='delivered'?'Entregado':($shipment['delivery_status']==='in_transit'?'En camino':($shipment['delivery_status']==='picked_up'?'Recogido':'Asignado'))) ?><br>
                 <span><?= e((string)$shipment['cargo_description']) ?></span>
                 <?php if (!empty($shipment['phone'])): ?><br><a href="tel:<?= e(preg_replace('/[^0-9+]/','',(string)$shipment['phone'])) ?>">Contactar motorizado</a><?php endif; ?>
                 </div><?php endforeach; ?></section>
                 <?php endif; ?>
                 <?php if ($shipmentTotal <= 1): ?><div class="tracking-driver-mini" aria-label="Repartidor de este pedido">
                     <span class="tracking-driver-label">🛵 TU MOTORIZADO</span>
                     <?php if ($assignedDriver): ?>
                       <div class="tracking-driver-person">
                         <?php if (!empty($assignedDriver['avatar_path'])): ?>
                           <img src="<?= e(url((string)$assignedDriver['avatar_path'])) ?>" alt="Foto de <?= e((string)$assignedDriver['name']) ?>">
                         <?php else: ?>
                           <span class="tracking-driver-initial"><?= e(mb_strtoupper(mb_substr((string)$assignedDriver['name'], 0, 1))) ?></span>
                         <?php endif; ?>
                         <div><strong><?= e((string)$assignedDriver['name']) ?></strong><small><?= $fulfillment === 'entregado' ? 'Realizó tu entrega' : 'Encargado de tu delivery' ?></small></div>
                       </div>
                       <?php if (trim((string)($assignedDriver['phone'] ?? '')) !== ''): ?>
                         <a class="tracking-driver-call" href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)$assignedDriver['phone'])) ?>">☎ Contactar: <?= e((string)$assignedDriver['phone']) ?></a>
                         <small class="tracking-driver-muted" style="margin-top:8px">Si el motorizado no se comunica contigo en un tiempo prudente, puedes contactarlo desde aquí.</small>
                       <?php else: ?><small class="tracking-driver-muted">Sin celular registrado</small><?php endif; ?>
                     <?php else: ?>
                       <p class="tracking-driver-muted">Estamos esperando la asignación de un repartidor.</p>
                     <?php endif; ?>
                 </div><?php endif; ?>
                 <?php endif; ?>
                 <em>La página se actualiza automáticamente.</em>

            </aside>
        </div>

        <?php if ($canReview): ?>
        <style>
        .customer-feedback-layout{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(260px,.8fr);gap:18px;align-items:stretch;margin-top:22px}
        .customer-feedback-layout .customer-receipt-ready{display:block;margin:0;min-width:0}
        .assigned-driver-card{background:#fff;border:1px solid #d9eae4;border-radius:16px;padding:22px;box-shadow:0 8px 24px rgba(14,74,62,.06);min-width:0}
        .assigned-driver-card h2{font-size:20px;margin:0 0 6px;color:#153d3c}
        .assigned-driver-card .driver-kicker{font-size:11px;font-weight:800;letter-spacing:1.4px;color:#0b9879}
        .assigned-driver-profile{display:flex;align-items:center;gap:14px;margin:18px 0}
        .assigned-driver-avatar{width:82px;height:82px;flex:0 0 82px;border-radius:50%;object-fit:cover;border:3px solid #c7f4e5;background:#e9faf3}
        .assigned-driver-placeholder{display:flex;align-items:center;justify-content:center;color:#087d66;font-size:32px;font-weight:800}
        .assigned-driver-name{font-size:18px;font-weight:800;color:#173b3a;overflow-wrap:anywhere}
        .assigned-driver-meta{font-size:13px;color:#58736f;line-height:1.6;margin-top:4px}
        .assigned-driver-phone{display:inline-flex;align-items:center;justify-content:center;margin-top:12px;padding:11px 14px;border-radius:10px;background:#d9f8ec;color:#08765f;font-weight:800;text-decoration:none}
        .assigned-driver-note{font-size:12px;color:#6b817c;line-height:1.5;margin:12px 0 0}
        @media(max-width:760px){.customer-feedback-layout{grid-template-columns:1fr}.assigned-driver-card{padding:18px}}
        </style>
        <div class="customer-feedback-layout">
          <section class="customer-receipt-ready">
              <h2>¿Qué te pareció tu compra?</h2>
              <p>Valora tu experiencia y déjanos un comentario. Tu opinión nos ayuda a mejorar.</p>
              <?php if ($feedbackSaved): ?><p style="color:#137b56;font-weight:700">¡Gracias! Tu opinión se guardó correctamente.</p><?php endif; ?>
              <?php if ($feedbackError !== ''): ?><p role="alert" style="color:#b42318"><?= e($feedbackError) ?></p><?php endif; ?>
              <form method="post" style="display:grid;gap:12px;max-width:600px;margin-top:14px">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="save_feedback">
                  <label for="rating">Tu valoración</label>
                  <select name="rating" id="rating" required style="padding:12px;border:1px solid #ccc;border-radius:8px">
                      <option value="">Selecciona una valoración</option>
                      <?php for ($stars=5;$stars>=1;$stars--): ?>
                      <option value="<?= $stars ?>" <?= (int)($existingFeedback['rating']??0)===$stars?'selected':'' ?>><?= str_repeat('★', $stars) ?> (<?= $stars ?>/5)</option>
                      <?php endfor; ?>
                  </select>
                  <label for="feedback_comment">Comentario (opcional)</label>
                  <textarea name="comment" id="feedback_comment" maxlength="1000" rows="4" placeholder="Cuéntanos tu experiencia" style="padding:12px;border:1px solid #ccc;border-radius:8px"><?= e($existingFeedback['comment'] ?? '') ?></textarea>
                  <button type="submit" class="btn btn-primary"><?= $existingFeedback ? 'Actualizar mi opinión' : 'Enviar mi opinión' ?></button>
              </form>
          </section>
          <aside class="assigned-driver-card" aria-label="Información del motorizado">
            <span class="driver-kicker">TU ENTREGA DEVIOZ</span>
            <h2><?= $shipmentTotal > 1 ? "Tus ".$shipmentTotal." motorizados" : "Tu motorizado" ?></h2>
            <?php if ($shipmentTotal > 1): ?>
              <div style="display:grid;gap:12px;margin-top:12px">
              <?php foreach ($allDrivers as $k=>$d): ?>
                <div style="padding:12px;border:1px solid #dbe4ef;border-radius:12px">
                  <b style="font-size:15px"><?= ($k+1) ?>. <?= e((string)$d['name']) ?></b>
                  <div style="font-size:13px;margin-top:5px"><?= e((string)$d['cargo_description']) ?></div>
                  <div style="font-size:13px;margin-top:6px;color:#345b88"><?= e($d['delivery_status']==='delivered'?'✓ Parte entregada':($d['delivery_status']==='in_transit'?'En camino':($d['delivery_status']==='picked_up'?'Recogida':'Asignada'))) ?></div>
                </div>
              <?php endforeach; ?>
              </div>
            <?php elseif ($assignedDriver): ?>
              <div class="assigned-driver-profile">
                <?php if (!empty($assignedDriver['avatar_path'])): ?>
                  <img class="assigned-driver-avatar" src="<?= e(url((string)$assignedDriver['avatar_path'])) ?>" alt="Foto de <?= e($assignedDriver['name']) ?>">
                <?php else: ?>
                  <div class="assigned-driver-avatar assigned-driver-placeholder" aria-label="Sin foto de perfil"><?= e(mb_strtoupper(mb_substr((string)$assignedDriver['name'], 0, 1))) ?></div>
                <?php endif; ?>
                <div><div class="assigned-driver-name"><?= e($assignedDriver['name']) ?></div><div class="assigned-driver-meta">Motorizado asignado a tu pedido</div></div>
              </div>
              <?php if (trim((string)($assignedDriver['phone'] ?? '')) !== ''): ?>
                <div class="assigned-driver-meta">Celular: <?= e($assignedDriver['phone']) ?></div>
              <?php else: ?>
                <div class="assigned-driver-meta">El motorizado no registró un celular de contacto.</div>
              <?php endif; ?>
              <?php if (!empty($assignedDriver['claimed_at'])): ?><p class="assigned-driver-note">Pedido aceptado: <?= e(date('d/m/Y H:i', strtotime((string)$assignedDriver['claimed_at']))) ?></p><?php endif; ?>
            <?php else: ?>
              <div class="assigned-driver-profile"><div class="assigned-driver-avatar assigned-driver-placeholder">🛵</div><div><div class="assigned-driver-name">Aún no asignado</div><div class="assigned-driver-meta">Todavía no se ha registrado un motorizado para este pedido.</div></div></div>
            <?php endif; ?>
            <p class="assigned-driver-note">Tus datos de entrega se comparten únicamente con el personal encargado de atender tu pedido.</p>
          </aside>
        </div>
        <?php endif; ?>
        <?php if ($paymentStatus === 'aprobado' && $fulfillment === 'entregado'): ?>
            <?php
                $secureReceiptUrl = receipt_url((int) $order['id']);
                $telegramShareUrl = 'https://t.me/share/url?url=' . rawurlencode($secureReceiptUrl)
                    . '&text=' . rawurlencode('Mi recibo de Devioz Store · ' . receipt_number($order));
            ?>
            <section class="customer-receipt-ready">
                <span class="receipt-ready-icon">✓</span>
                <div><small>RECIBO DISPONIBLE</small><h2><?= e(receipt_number($order)) ?></h2><p>Puedes abrirlo, imprimirlo o compartirlo desde tu celular.</p></div>
                <div class="receipt-ready-actions">
                    <a class="btn btn-primary" href="<?= e($secureReceiptUrl) ?>">Ver recibo</a>
                    <a class="btn btn-secondary" href="<?= e($secureReceiptUrl) ?>" target="_blank" rel="noopener">Guardar / compartir</a>
                    <a class="btn btn-secondary" href="<?= e($telegramShareUrl) ?>" target="_blank" rel="noopener">Enviar por Telegram</a>
                </div>
            </section>
        <?php endif; ?>
        <section id="mis-reclamos" style="background:#fff;border:1px solid #dae4ef;border-radius:20px;padding:24px;margin:26px 0;box-shadow:0 12px 32px rgba(17,31,55,.06)">
          <div style="display:flex;gap:12px;align-items:center;margin-bottom:14px"><span style="background:#fff1e7;border-radius:12px;padding:11px;font-size:24px">📋</span><div><h2 style="margin:0;font-size:23px;color:#14243b">¿Tuviste un problema con tu pedido?</h2><p style="margin:5px 0 0;color:#52667e;font-size:14px">Cuéntanos qué ocurrió. Puedes adjuntar una fotografía. El administrador revisará tu caso.</p></div></div>
          <?php if($complaintSuccess): ?><p role="status" style="color:#08694e;font-weight:700">Reclamo registrado. Se encuentra pendiente de revisión.</p><?php endif; ?>
          <?php if($complaintError!==''): ?><p role="alert" style="color:#b42318"><?= e($complaintError) ?></p><?php endif; ?>
          <form method="post" enctype="multipart/form-data" style="display:grid;gap:13px;max-width:720px">
            <?= csrf_field() ?><input type="hidden" name="action" value="submit_complaint">
            <label style="font-size:14px;font-weight:700">Tipo de problema
              <select name="complaint_category" required style="display:block;width:100%;padding:12px;border-radius:10px;border:1px solid #cbd5e1;margin-top:7px"><option value="entrega">Problema con la entrega</option><option value="producto">Producto dañado o faltante</option><option value="trato">Trato recibido</option><option value="otro">Otro problema</option></select></label>
            <label style="font-size:14px;font-weight:700">¿Con qué motorizado ocurrió? (opcional)
              <select name="complaint_driver" style="display:block;width:100%;padding:12px;border-radius:10px;border:1px solid #cbd5e1;margin-top:7px"><option value="0">No está relacionado con un motorizado / No lo sé</option>
              <?php $seenDriverIds=[];foreach($allDrivers as $drv): $dId=(int)$drv['id'];if(isset($seenDriverIds[$dId]))continue;$seenDriverIds[$dId]=true; ?><option value="<?= $dId ?>"><?= e((string)$drv['name']) ?></option><?php endforeach; ?>
              </select></label>
            <label style="font-size:14px;font-weight:700">Describe el problema
              <textarea name="complaint_description" required minlength="15" maxlength="2000" rows="4" placeholder="Ejemplo: uno de los productos llegó dañado..." style="display:block;width:100%;padding:13px;border-radius:10px;border:1px solid #cbd5e1;margin-top:7px"><?= e((string)($_POST['complaint_description']??'')) ?></textarea></label>
            <label style="font-size:14px;font-weight:700">Adjuntar fotografía (opcional, máximo 4 MB)
              <input type="file" name="complaint_photo" accept="image/jpeg,image/png,image/webp" style="display:block;margin-top:7px;font-size:14px"></label>
            <button class="btn btn-primary" type="submit" style="max-width:240px;min-height:44px">Enviar reclamo</button>
          </form>
          <?php if($myComplaints): ?><div style="margin-top:24px;border-top:1px solid #e2e8f0;padding-top:16px"><h3 style="font-size:17px;margin:0 0 12px">Mis reclamos registrados</h3>
          <?php foreach($myComplaints as $c): ?><div style="padding:13px;border:1px solid #e2e8f0;border-radius:12px;margin-bottom:9px"><strong>Caso #<?= (int)$c['id'] ?></strong> · <?= e(['pending'=>'Pendiente de revisión','valid'=>'Validado','rejected'=>'No procedente'][$c['status']]??'En revisión') ?> <small style="color:#64748b"><?= e((string)$c['created_at']) ?></small><p style="margin:6px 0 0;overflow-wrap:anywhere"><?= e((string)$c['description']) ?></p></div><?php endforeach; ?></div><?php endif; ?>
        </section>
    </div>
</section>
<?php if(!in_array($fulfillment,['entregado','cancelado'],true) && $paymentStatus!=='rechazado'): ?><script>window.setInterval(() => {if(!document.hidden)window.location.reload();}, 15000);</script><?php endif ?>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
