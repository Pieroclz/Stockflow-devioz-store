<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/../includes/delivery_incidents.php';
$driver = driver_require();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método no permitido.'); }
verify_csrf();
$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT) ?: 0;
$action = (string)($_POST['delivery_action'] ?? '');
$note = trim((string)($_POST['delivery_notes'] ?? ''));
$transitions = ['pickup'=>['from'=>'assigned','to'=>'picked_up'], 'start_route'=>['from'=>'picked_up','to'=>'in_transit'], 'delivered'=>['from'=>'in_transit','to'=>'delivered'], 'failed'=>['from'=>['assigned','picked_up','in_transit'],'to'=>'failed']];
if ($orderId < 1 || !isset($transitions[$action])) { $_SESSION['driver_notice']='La acción solicitada no es válida.'; header('Location: index.php'); exit; }
if ($action === 'failed' && mb_strlen($note) < 5) { $_SESSION['driver_notice']='Describe la incidencia con al menos 5 caracteres.'; header('Location: detalles.php?id='.$orderId); exit; }
$db = db();
try {
    if ($action === 'failed') delivery_incidents_initialize($db);
    $db->beginTransaction();
    // Serializar todas las confirmaciones del mismo pedido para impedir cierres parciales.
    $orderLock=$db->prepare('SELECT id FROM yape_orders WHERE id=? FOR UPDATE');$orderLock->execute([$orderId]);
    $q = $db->prepare('SELECT a.delivery_status, a.finished_at, o.status AS order_status, o.fulfillment_status FROM delivery_assignments a JOIN yape_orders o ON o.id=a.order_id WHERE a.order_id=? AND a.driver_id=? FOR UPDATE');
    $q->execute([$orderId, (int)$driver['id']]); $row = $q->fetch(PDO::FETCH_ASSOC);
    $table='delivery_assignments';
    if (!$row) {
        try {
            $q=$db->prepare('SELECT a.delivery_status,a.finished_at,o.status AS order_status,o.fulfillment_status FROM delivery_extra_assignments a JOIN yape_orders o ON o.id=a.order_id WHERE a.order_id=? AND a.driver_id=? FOR UPDATE');
            $q->execute([$orderId,(int)$driver['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);$table='delivery_extra_assignments';
        } catch (PDOException $ignored) { /* Tabla adicional no instalada */ }
    }
    if (!$row || $row['finished_at'] !== null || $row['order_status'] !== 'aprobado' || in_array((string)$row['fulfillment_status'], ['entregado','cancelado'], true)) throw new RuntimeException('El pedido no está asignado a tu cuenta o ya finalizó.');
    $rule = $transitions[$action]; $current = (string)$row['delivery_status']; $allowed = is_array($rule['from']) ? $rule['from'] : [$rule['from']];
    if (!in_array($current, $allowed, true)) throw new RuntimeException('Esta acción no corresponde al estado actual de la entrega.');
    // An unresolved report freezes further delivery actions until administration decides.
    $pending = $db->prepare("SELECT COUNT(*) FROM delivery_incidents WHERE order_id=? AND driver_id=? AND status IN ('pendiente','en_revision')");
    $pending->execute([$orderId,(int)$driver['id']]);
    if ((int)$pending->fetchColumn() > 0) throw new RuntimeException('Existe una incidencia pendiente. Espera la decisión del administrador.');
    if ($action === 'pickup') {
        $db->prepare("UPDATE {$table} SET delivery_status='picked_up', picked_up_at=NOW(), delivery_notes=NULL WHERE order_id=? AND driver_id=?")->execute([$orderId,(int)$driver['id']]);
    } elseif ($action === 'start_route') {
        $db->prepare("UPDATE {$table} SET delivery_status='in_transit' WHERE order_id=? AND driver_id=?")->execute([$orderId,(int)$driver['id']]);
    } elseif ($action === 'delivered') {
        $db->prepare("UPDATE {$table} SET delivery_status='delivered', delivered_at=NOW(), finished_at=NOW(), delivery_notes=NULL WHERE order_id=? AND driver_id=?")->execute([$orderId,(int)$driver['id']]);
        // Al terminar el despacho principal, dejar de exponer sus coordenadas aunque otros continúen.
        if ($table==='delivery_assignments') {
            $db->prepare("UPDATE delivery_tracking SET driver_lat=NULL,driver_lng=NULL,accuracy_m=NULL WHERE order_id=?")->execute([$orderId]);
        }
        // El pedido SOLO se completa cuando todos los despachos hayan terminado.
        $remaining=$db->prepare("SELECT COUNT(*) FROM delivery_assignments WHERE order_id=? AND delivery_status <> 'delivered'");$remaining->execute([$orderId]);
        $notFinished=(int)$remaining->fetchColumn();
        try { $more=$db->prepare("SELECT COUNT(*) FROM delivery_extra_assignments WHERE order_id=? AND delivery_status <> 'delivered'");$more->execute([$orderId]);$notFinished+=(int)$more->fetchColumn(); } catch (PDOException $ignored) {}
        if ($notFinished===0) $db->prepare("UPDATE yape_orders SET fulfillment_status='entregado', delivered_at=NOW(), status_updated_at=NOW() WHERE id=? AND status='aprobado'")->execute([$orderId]);
    } else {
        // A report is NOT a delivery failure/cancellation and must remain assigned.
        $db->prepare("UPDATE {$table} SET delivery_notes=? WHERE order_id=? AND driver_id=?")->execute([$note,$orderId,(int)$driver['id']]);
        $db->prepare("INSERT INTO delivery_incidents (order_id,driver_id,description) VALUES (?,?,?)")->execute([$orderId,(int)$driver['id'],$note]);
    }
    $db->commit();
    $_SESSION['driver_notice'] = $action === 'pickup' ? 'Recojo confirmado. Cuando salgas del almacén, inicia la ruta.' : ($action === 'start_route' ? 'Reparto iniciado.' : ($action === 'delivered' ? 'Entrega confirmada correctamente.' : 'Incidencia enviada a administración. Tu pedido sigue asignado y queda en pausa hasta la decisión.'));
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    $_SESSION['driver_notice'] = $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo actualizar el despacho. Comprueba que ejecutaste la migración logística.';
}
header('Location: index.php'); exit;
