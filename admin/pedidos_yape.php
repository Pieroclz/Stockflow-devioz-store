<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_recommendations.php';
require_once __DIR__ . '/../includes/yape_order_snapshots.php';
require_once __DIR__ . '/../includes/delivery_incidents.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = max(0, (int) ($_POST['id'] ?? 0));
    $action = (string) ($_POST['action'] ?? 'payment');
    if (in_array($action, ['add_extra_driver', 'remove_extra_driver', 'save_primary_cargo'], true) && $id > 0) {
        if ((string)($_SESSION['admin_role'] ?? 'admin') !== 'admin') { flash('danger', 'Sin permisos.'); redirect('admin/pedidos_yape.php'); }
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT id,status,fulfillment_status FROM yape_orders WHERE id=? FOR UPDATE");
            $lock->execute([$id]); $locked = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$locked || $locked['status'] !== 'aprobado' || $locked['fulfillment_status'] !== 'listo') throw new RuntimeException('El pedido debe estar aprobado y listo.');
            $driverId = max(0, (int)($_POST['driver_id'] ?? 0));
            if ($action === 'save_primary_cargo') {
                $cargo = trim((string)($_POST['cargo_description'] ?? ''));
                if (mb_strlen($cargo) < 5 || mb_strlen($cargo) > 500) throw new RuntimeException('Describe los productos o paquetes (5 a 500 caracteres).');
                $pdo->prepare('INSERT INTO delivery_primary_cargo(order_id,cargo_description) VALUES (?,?) ON DUPLICATE KEY UPDATE cargo_description=VALUES(cargo_description)')->execute([$id,$cargo]);
            } elseif ($action === 'add_extra_driver') {
                $cargo = trim((string)($_POST['cargo_description'] ?? ''));
                if (mb_strlen($cargo)<5 || mb_strlen($cargo)>500) throw new RuntimeException('Indica qué productos y cantidades llevará este motorizado.');
                $prim=$pdo->prepare("SELECT driver_id,delivery_status,finished_at FROM delivery_assignments WHERE order_id=? FOR UPDATE");$prim->execute([$id]);$base=$prim->fetch(PDO::FETCH_ASSOC);
                if (!$base || $base['finished_at'] !== null || $base['delivery_status'] !== 'assigned') throw new RuntimeException('Primero asigna el motorizado principal. Debe estar sin recoger la carga.');
                if ((int)$base['driver_id'] === $driverId) throw new RuntimeException('El motorizado ya está asignado al pedido.');
                $primaryCargoCheck=$pdo->prepare('SELECT cargo_description FROM delivery_primary_cargo WHERE order_id=?');$primaryCargoCheck->execute([$id]);if(mb_strlen(trim((string)$primaryCargoCheck->fetchColumn()))<5) throw new RuntimeException('Guarda primero la carga del motorizado principal.');
                $valid=$pdo->prepare('SELECT id FROM delivery_drivers WHERE id=? AND active=1');$valid->execute([$driverId]);if(!$valid->fetchColumn())throw new RuntimeException('Selecciona un motorizado activo.');
                $started=$pdo->prepare("SELECT COUNT(*) FROM delivery_extra_assignments WHERE order_id=? AND delivery_status <> 'assigned'");$started->execute([$id]);if((int)$started->fetchColumn()>0) throw new RuntimeException('La distribución ya comenzó: no puedes añadir otro despacho ahora.');
                $pdo->prepare("INSERT INTO delivery_extra_assignments(order_id,driver_id,cargo_description) VALUES (?,?,?)")->execute([$id,$driverId,$cargo]);
            } else {
                $check=$pdo->prepare('SELECT delivery_status FROM delivery_extra_assignments WHERE order_id=? AND driver_id=? FOR UPDATE');$check->execute([$id,$driverId]);
                if ($check->fetchColumn() !== 'assigned') throw new RuntimeException('Solo puedes retirar un despacho adicional que aún no ha sido recogido.');
                $pdo->prepare('DELETE FROM delivery_extra_assignments WHERE order_id=? AND driver_id=?')->execute([$id,$driverId]);
            }
            $pdo->commit();flash('success','Distribución de la entrega actualizada.');
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack();flash('danger',$e instanceof RuntimeException?$e->getMessage():'No se pudo actualizar. Ejecuta database/migracion_multiples_motorizados.sql.'); }
        redirect('admin/pedidos_yape.php');
    }
    if ($action === 'assign_driver' && $id > 0) {
        if ((string)($_SESSION['admin_role'] ?? 'admin') !== 'admin') {
            flash('danger', 'Solo el administrador puede asignar motorizados.');
            redirect('admin/pedidos_yape.php');
        }
        $driverId = max(0, (int)($_POST['driver_id'] ?? 0));
        try {
            $connection = db();
            delivery_incidents_initialize($connection);
            $pending=$connection->prepare("SELECT COUNT(*) FROM delivery_incidents WHERE order_id=? AND status IN ('pendiente','en_revision')");$pending->execute([$id]);
            if ((int)$pending->fetchColumn()>0) throw new RuntimeException('Este pedido tiene una incidencia por decidir. Gestiónala desde Incidencias.');
            $connection->beginTransaction();
            $orderQuery = $connection->prepare("SELECT id, status, fulfillment_status FROM yape_orders WHERE id = ? FOR UPDATE");
            $orderQuery->execute([$id]);
            $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
            if (!$order || $order['status'] !== 'aprobado' || $order['fulfillment_status'] !== 'listo') {
                throw new RuntimeException('Solo puedes asignar pedidos aprobados y preparados.');
            }
            try { $extras=$connection->prepare('SELECT COUNT(*) FROM delivery_extra_assignments WHERE order_id=?');$extras->execute([$id]); if((int)$extras->fetchColumn()>0) throw new RuntimeException('Hay más motorizados asignados. Gestiona esos despachos antes de cambiar el principal.'); } catch (PDOException $ignored) { /* Instalación anterior a la migración. */ }
            $driverQuery = $connection->prepare('SELECT id FROM delivery_drivers WHERE id = ? AND active = 1 FOR UPDATE');
            $driverQuery->execute([$driverId]);
            if (!$driverQuery->fetchColumn()) throw new RuntimeException('Selecciona un motorizado activo.');
            $connection->prepare("INSERT INTO delivery_assignments (order_id, driver_id, claimed_at, finished_at, delivery_status) VALUES (?, ?, NOW(), NULL, 'assigned') ON DUPLICATE KEY UPDATE driver_id = VALUES(driver_id), claimed_at = NOW(), finished_at = NULL, delivery_status = 'assigned', delivery_notes = NULL, picked_up_at = NULL, delivered_at = NULL")
                ->execute([$id, $driverId]);
            // El seguimiento se crea al asignar el despacho; el motorizado no necesita
            // esperar a que administración genere un enlace GPS manualmente.
            $trackingSecret = hash('sha256', bin2hex(random_bytes(32)));
            $connection->prepare("INSERT INTO delivery_tracking (order_id, driver_token_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE driver_token_hash=VALUES(driver_token_hash), driver_lat=NULL, driver_lng=NULL, accuracy_m=NULL, updated_at=NOW()")
                ->execute([$id, $trackingSecret]);
            $connection->commit();
            flash('success', 'Motorizado asignado. El pedido ya aparece en su panel.');
        } catch (Throwable $exception) {
            if (isset($connection) && $connection->inTransaction()) $connection->rollBack();
            flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo asignar el motorizado. Verifica que ejecutaste la migración logística.');
        }
        redirect('admin/pedidos_yape.php');
    }
    if ($action === 'tracking_setup' && $id > 0) {
        if ((string)($_SESSION['admin_role']??'admin') !== 'admin') { flash('danger','Solo el administrador configura GPS.'); redirect('admin/pedidos_yape.php'); }
        $lat=filter_var($_POST['destination_lat']??null,FILTER_VALIDATE_FLOAT);
        $lng=filter_var($_POST['destination_lng']??null,FILTER_VALIDATE_FLOAT);
        if($lat===false||$lng===false||abs($lat)>90||abs($lng)>180){flash('danger','Destino inválido. Usa coordenadas decimales.');redirect('admin/pedidos_yape.php');}
        $token=bin2hex(random_bytes(32));
        try {
            $check=db()->prepare("SELECT id FROM yape_orders WHERE id=? AND status='aprobado' AND fulfillment_status NOT IN ('entregado','cancelado')");$check->execute([$id]);
            if(!$check->fetchColumn()) throw new RuntimeException('Solo puedes activar GPS para un pedido aprobado y no entregado.');
            $q=db()->prepare('INSERT INTO delivery_tracking (order_id,driver_token_hash,destination_lat,destination_lng) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE driver_token_hash=VALUES(driver_token_hash),destination_lat=VALUES(destination_lat),destination_lng=VALUES(destination_lng)');
            $q->execute([$id,hash('sha256',$token),$lat,$lng]);
            $_SESSION['tracking_driver_link']='repartidor_gps.php?id='.$id.'&token='.$token;
            flash('success','GPS configurado. Copia el enlace privado del repartidor que aparece arriba.');
        }catch(Throwable $ex){flash('danger','No se pudo configurar GPS. Comprueba el SQL y el estado del pedido.');}
        redirect('admin/pedidos_yape.php');
    }
    if ($action === 'tracking_manual' && $id > 0) {
        if ((string)($_SESSION['admin_role']??'admin') !== 'admin') {flash('danger','Sin permiso.');redirect('admin/pedidos_yape.php');}
        $lat=filter_var($_POST['lat']??null,FILTER_VALIDATE_FLOAT);$lng=filter_var($_POST['lng']??null,FILTER_VALIDATE_FLOAT);
        if($lat===false||$lng===false||abs($lat)>90||abs($lng)>180){flash('danger','Coordenadas inválidas.');redirect('admin/pedidos_yape.php');}
        try {$q=db()->prepare("UPDATE delivery_tracking t INNER JOIN yape_orders o ON o.id=t.order_id SET t.driver_lat=?,t.driver_lng=?,t.tracking_mode='manual',t.updated_at=NOW() WHERE t.order_id=? AND o.status='aprobado' AND o.fulfillment_status NOT IN ('entregado','cancelado')");$q->execute([$lat,$lng,$id]);flash($q->rowCount()?'success':'danger',$q->rowCount()?'Ubicación manual actualizada.':'Primero configura el seguimiento GPS.');}
        catch(Throwable $ex){flash('danger','No se pudo actualizar la ubicación.');}
        redirect('admin/pedidos_yape.php');
    }
    if ($action === 'cancel_purchase' && $id > 0) {
        if ((string) ($_SESSION['admin_role'] ?? 'admin') !== 'admin') { flash('danger', 'Solo un administrador puede cancelar compras.'); redirect('admin/pedidos_yape.php'); }
        $reasonType = (string) ($_POST['cancel_reason'] ?? '');
        $reasonDetail = trim((string) ($_POST['cancel_detail'] ?? ''));
        $allowedReasons = ['solicitud_cliente', 'sin_stock', 'problema_pago', 'problema_entrega', 'otro'];
        if (!in_array($reasonType, $allowedReasons, true) || mb_strlen($reasonDetail) < 5 || mb_strlen($reasonDetail) > 1000) {
            flash('danger', 'Selecciona un motivo y escribe una explicación de 5 a 1000 caracteres.');
            redirect('admin/pedidos_yape.php');
        }
        $connection = db();
        try {
            $incidentId=(int)($_POST['incident_id']??0);
            if ($incidentId>0) delivery_incidents_initialize($connection);
            $connection->beginTransaction();
            if ($incidentId>0) {
                $incidentCheck=$connection->prepare("SELECT id,driver_id FROM delivery_incidents WHERE id=? AND order_id=? AND status IN ('pendiente','en_revision') FOR UPDATE");
                $incidentCheck->execute([$incidentId,$id]);
                $cancelIncident=$incidentCheck->fetch(PDO::FETCH_ASSOC);
                if (!$cancelIncident) throw new RuntimeException('La incidencia ya fue revisada o no pertenece a ese pedido.');
            }
            $orderQuery = $connection->prepare('SELECT * FROM yape_orders WHERE id = ? FOR UPDATE');
            $orderQuery->execute([$id]);
            $cancelOrder = $orderQuery->fetch();
            if (!$cancelOrder || !in_array((string) $cancelOrder['status'], ['pendiente', 'aprobado'], true)
                || (string) ($cancelOrder['fulfillment_status'] ?? '') === 'entregado'
                || (string) ($cancelOrder['fulfillment_status'] ?? '') === 'cancelado') {
                throw new RuntimeException('Este pedido no se puede cancelar: ya fue cancelado, rechazado o entregado.');
            }
            $itemsQuery = $connection->prepare('SELECT * FROM yape_order_items WHERE order_id = ? ORDER BY id FOR UPDATE');
            $itemsQuery->execute([$id]);
            $items = $itemsQuery->fetchAll();
            $paymentMethod = (string) ($cancelOrder['metodo_pago'] ?? '');
            if ($cancelOrder['status'] === 'pendiente') {
                if (!payment_requires_review($paymentMethod)) throw new RuntimeException('Método de pago pendiente no compatible.');
                $reservationSnapshot = yape_lock_active_reservations($connection, $id);
                yape_assert_snapshot_coherence($connection, $cancelOrder, $items, $reservationSnapshot);
                yape_release_reservations($connection, $reservationSnapshot, 'released');
                yape_release_manual_combo_caps($connection, $items, (int) $cancelOrder['inventory_snapshot_version']);
                yape_delete_provisional_movements($connection, (string) $cancelOrder['order_code'], $paymentMethod);
            } else {
                // Una venta aprobada tiene reservas convertidas y movimientos definitivos.
                // No usar la función de reservas activas para evitar duplicar devoluciones.
                $reservationQuery = $connection->prepare("SELECT id, product_id, quantity FROM stock_reservations WHERE order_id = ? AND status = 'converted' ORDER BY id FOR UPDATE");
                $reservationQuery->execute([$id]);
                $reservations = $reservationQuery->fetchAll();
                if (!$reservations || !$items) throw new RuntimeException('No se encontró la reserva convertida; revisar manualmente el inventario.');
                $sums = [];
                foreach ($reservations as $reservation) {
                    $pid = (int) $reservation['product_id'];
                    $qty = (int) $reservation['quantity'];
                    if ($pid < 1 || $qty < 1) throw new RuntimeException('Reserva de inventario inválida.');
                    $sums[$pid] = ($sums[$pid] ?? 0) + $qty;
                }
                $movementQuery = $connection->prepare("SELECT product_id, SUM(units_changed) AS qty FROM inventory_movements WHERE reference = ? AND payment_method = ? AND movement_type = 'salida' GROUP BY product_id ORDER BY product_id");
                $movementQuery->execute([(string) $cancelOrder['order_code'], $paymentMethod]);
                $movementSums = [];
                foreach ($movementQuery->fetchAll() as $movement) $movementSums[(int) $movement['product_id']] = (int) $movement['qty'];
                ksort($sums); ksort($movementSums);
                if ($sums !== $movementSums) throw new RuntimeException('Reservas y salidas no coinciden. No se modificó el inventario.');
                $restore = $connection->prepare("UPDATE products SET stock = stock + ? WHERE id = ? AND catalog_scope = 'master'");
                $reverse = $connection->prepare("INSERT INTO inventory_movements (product_id, movement_type, presentation, quantity, units_changed, payment_method, reference, notes, created_by) VALUES (?, 'ajuste_entrada', 'unidad', ?, ?, ?, ?, ?, NULL)");
                foreach ($sums as $pid => $qty) {
                    $restore->execute([$qty, $pid]);
                    if ($restore->rowCount() !== 1) throw new RuntimeException('No se pudo devolver un producto al inventario.');
                    $reverse->execute([$pid, $qty, $qty, $paymentMethod, 'CANCEL-' . (string) $cancelOrder['order_code'], 'Devolución por cancelación administrativa del pedido #' . $id]);
                }
                $releaseConverted = $connection->prepare("UPDATE stock_reservations SET status = 'released' WHERE id = ? AND status = 'converted'");
                foreach ($reservations as $reservation) {
                    $releaseConverted->execute([(int) $reservation['id']]);
                    if ($releaseConverted->rowCount() !== 1) throw new RuntimeException('La reserva cambió durante la cancelación.');
                }
                yape_release_manual_combo_caps($connection, $items, (int) ($cancelOrder['inventory_snapshot_version'] ?? 0));
            }
            $updateCancelled = $connection->prepare("UPDATE yape_orders SET status = 'rechazado', fulfillment_status = 'cancelado', reviewed_at = NOW(), status_updated_at = NOW() WHERE id = ? AND status = ? AND fulfillment_status <> 'entregado'");
            $updateCancelled->execute([$id, (string) $cancelOrder['status']]);
            if ($updateCancelled->rowCount() !== 1) throw new RuntimeException('El estado del pedido cambió durante la cancelación.');
            $connection->prepare('INSERT INTO admin_order_cancellations (order_id, reason_type, reason_detail) VALUES (?, ?, ?)')
                ->execute([$id, $reasonType, $reasonDetail]);
            // The dispatch is removed only AFTER the administrator's cancellation succeeds.
            $connection->prepare("UPDATE delivery_assignments SET delivery_status='failed',finished_at=NOW() WHERE order_id=? AND finished_at IS NULL")->execute([$id]);
            if ($incidentId>0) $connection->prepare("UPDATE delivery_incidents SET status='resuelta',decision='cancelada',decided_at=NOW(),resolved_at=NOW(),resolution_notes=? WHERE id=?")->execute([$reasonDetail,$incidentId]);
            $connection->commit();
            flash('success', 'Compra cancelada y stock devuelto. Se registró el motivo. Si hubo cobro real, gestiona el reembolso por separado.');
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) $connection->rollBack();
            flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo cancelar la compra. Comprueba que ejecutaste el SQL de instalación.');
        }
        redirect('admin/pedidos_yape.php');
    }
    if ($action === 'fulfillment' && $id > 0) {
        try {
            $query = db()->prepare('SELECT status, fulfillment_status, tipo_despacho FROM yape_orders WHERE id = ? LIMIT 1');
            $query->execute([$id]);
            $order = $query->fetch();
            if (!$order || $order['status'] !== 'aprobado') throw new RuntimeException('Primero debes aprobar el pago del pedido.');
            $current = (string) ($order['fulfillment_status'] ?? 'recibido');
            $next = (string) ($_POST['fulfillment_status'] ?? '');
            $isExpress = (string) ($order['tipo_despacho'] ?? 'preparacion') === 'entrega_rapida';
            $allowedTransitions = $isExpress
                ? [
                    'recibido' => ['entregado'],
                    'en_preparacion' => ['entregado'],
                    'listo' => ['entregado'],
                    'entregado' => [],
                    'cancelado' => [],
                ]
                : [
                    'recibido' => ['en_preparacion'],
                    'en_preparacion' => ['listo'],
                    'listo' => ['entregado'],
                    'entregado' => [],
                    'cancelado' => [],
                ];
            if (!in_array($next, $allowedTransitions[$current] ?? [], true)) throw new RuntimeException('El cambio de estado no es válido.');
            if ($next === 'entregado') {
                try {
                    $x=db()->prepare("SELECT COUNT(*) FROM delivery_extra_assignments WHERE order_id=? AND delivery_status <> 'delivered'");$x->execute([$id]);
                    if ((int)$x->fetchColumn()>0) throw new RuntimeException('Faltan motorizados por entregar su parte. No puedes cerrar el pedido.');
                } catch (PDOException $ignored) { /* Si no hay tabla adicional, flujo anterior. */ }
                $base=db()->prepare("SELECT COUNT(*) FROM delivery_assignments WHERE order_id=? AND delivery_status IN ('assigned','picked_up','in_transit')");$base->execute([$id]);
                if ((int)$base->fetchColumn()>0) throw new RuntimeException('Hay un despacho principal sin completar.');

                $assignmentCheck = db()->prepare("SELECT delivery_status FROM delivery_assignments WHERE order_id = ? AND finished_at IS NULL LIMIT 1");
                $assignmentCheck->execute([$id]);
                $activeDeliveryStatus = $assignmentCheck->fetchColumn();
                if ($activeDeliveryStatus !== false && $activeDeliveryStatus !== 'delivered') {
                    throw new RuntimeException('Este pedido está asignado a un motorizado. La entrega debe confirmarse desde su panel.');
                }
            }
            db()->prepare("UPDATE yape_orders SET fulfillment_status = ?, status_updated_at = NOW(), delivered_at = CASE WHEN ? = 'entregado' THEN NOW() ELSE delivered_at END WHERE id = ?")
                ->execute([$next, $next, $id]);
            $labels = ['en_preparacion' => 'Pedido enviado a preparación.', 'listo' => 'Pedido marcado como listo.', 'entregado' => 'Pedido marcado como entregado.'];
            $message = $labels[$next] ?? 'Estado del pedido actualizado.';
            if ($next === 'entregado') {
                try {
                    $telegramReceipt = send_telegram_delivered_notification($id);
                    $message .= $telegramReceipt['success']
                        ? ' El recibo fue enviado al Telegram administrativo.'
                        : ' El recibo está disponible, pero Telegram no pudo enviarlo: ' . $telegramReceipt['message'];
                } catch (Throwable) {
                    $message .= ' El recibo está disponible, pero Telegram no pudo enviar el aviso.';
                }
            }
            flash('success', $message);
        } catch (RuntimeException $exception) { flash('danger', $exception->getMessage()); }
        redirect('admin/pedidos_yape.php');
    }

    $status = (string) ($_POST['status'] ?? '');
    if ($id > 0 && in_array($status, ['aprobado', 'rechazado'], true)) {
        $connection = db();
        $connection->beginTransaction();
        try {
            $orderQuery = $connection->prepare('SELECT * FROM yape_orders WHERE id = ? FOR UPDATE');
            $orderQuery->execute([$id]);
            $order = $orderQuery->fetch();
            if (!$order || $order['status'] !== 'pendiente') throw new RuntimeException('El pedido ya había sido revisado.');
            if (!payment_requires_review((string) ($order['metodo_pago'] ?? ''))) throw new RuntimeException('Este método de pago no requiere revisión de comprobante.');
            $itemsQuery = $connection->prepare('SELECT * FROM yape_order_items WHERE order_id = ? ORDER BY id FOR UPDATE');
            $itemsQuery->execute([$id]);
            $items = $itemsQuery->fetchAll();
            if (!$items) throw new RuntimeException('El pedido no contiene productos para revisar.');
            $reservationSnapshot = yape_lock_active_reservations($connection, $id);
            yape_assert_snapshot_coherence($connection, $order, $items, $reservationSnapshot);

            $effectiveStatus = $status;
            $restricted = false;
            if ($status === 'aprobado') {
                $restricted = yape_snapshot_has_restricted_product($reservationSnapshot['rows']);
                if ($restricted) {
                    yape_release_reservations($connection, $reservationSnapshot, 'released');
                    yape_release_manual_combo_caps($connection, $items, (int) $order['inventory_snapshot_version']);
                    yape_delete_provisional_movements($connection, (string) $order['order_code'], (string) $order['metodo_pago']);
                    $effectiveStatus = 'rechazado';
                }
                if (!$restricted) {
                    yape_convert_reservations($connection, $reservationSnapshot['rows']);
                }
            } elseif ($status === 'rechazado') {
                yape_release_reservations($connection, $reservationSnapshot, 'released');
                yape_release_manual_combo_caps($connection, $items, (int) $order['inventory_snapshot_version']);
                yape_delete_provisional_movements($connection, (string) $order['order_code'], (string) $order['metodo_pago']);
            }

            $fulfillmentStatus = $effectiveStatus === 'aprobado'
                ? (((string) ($order['tipo_despacho'] ?? 'preparacion') === 'entrega_rapida') ? 'listo' : 'recibido')
                : 'cancelado';
            $update = $connection->prepare("UPDATE yape_orders SET status = ?, fulfillment_status = ?, reviewed_at = NOW(), status_updated_at = NOW() WHERE id = ? AND status = 'pendiente'");
            $update->execute([$effectiveStatus, $fulfillmentStatus, $id]);
            if ($update->rowCount() !== 1) throw new RuntimeException('El pedido ya había sido revisado.');
            if ($effectiveStatus === 'aprobado' && !empty($order['coupon_id']) && (float) ($order['discount_amount'] ?? 0) > 0) {
                $couponUsage = $connection->prepare('INSERT IGNORE INTO coupon_usage(coupon_id,customer_id,order_id,discount_amount) VALUES(?,?,?,?)');
                $couponUsage->execute([(int) $order['coupon_id'], $order['customer_id'] ?: null, $id, (float) $order['discount_amount']]);
                if ($couponUsage->rowCount() === 1) $connection->prepare('UPDATE coupons SET used_count=used_count+1 WHERE id=?')->execute([(int) $order['coupon_id']]);
            }
            $connection->commit();
            flash($effectiveStatus === 'aprobado' ? 'success' : 'danger', $effectiveStatus === 'aprobado'
                ? 'Pago aprobado. Se conservaron los importes y movimientos del pedido.'
                : ($restricted ? 'El pedido se rechazó porque el producto reservado quedó restringido; requiere seguimiento del pago.' : 'Pedido rechazado y stock restaurado.'));
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) $connection->rollBack();
            flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo actualizar el pedido.');
        }
    }
    redirect('admin/pedidos_yape.php');
}

$filter = (string) ($_GET['status'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$conditions = [];
$parameters = [];
if (in_array($filter, ['pendiente', 'aprobado', 'rechazado'], true)) { $conditions[] = 'o.status = ?'; $parameters[] = $filter; }
if ($search !== '') { $conditions[] = '(o.order_code LIKE ? OR o.operation_code LIKE ? OR i.item_name LIKE ?)'; $like = '%' . $search . '%'; array_push($parameters, $like, $like, $like); }
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$statement = db()->prepare('SELECT o.*, GROUP_CONCAT(CONCAT(i.quantity, "× ", i.item_name) ORDER BY i.id SEPARATOR " · ") AS items FROM yape_orders o LEFT JOIN yape_order_items i ON i.order_id = o.id ' . $where . ' GROUP BY o.id ORDER BY o.created_at DESC LIMIT 200');
$statement->execute($parameters);
$orders = $statement->fetchAll();
$orderItems = [];
if ($orders) {
    $ids = array_map(static fn (array $order): int => (int) $order['id'], $orders);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $itemsStatement = db()->prepare('SELECT * FROM yape_order_items WHERE order_id IN (' . $placeholders . ') ORDER BY order_id, id');
    $itemsStatement->execute($ids);
    foreach ($itemsStatement->fetchAll() as $item) $orderItems[(int) $item['order_id']][] = $item;
}
// Consultar valoraciones de los pedidos mostrados, sin modificar pedidos ni pagos.
$orderFeedback = [];
if ($orders) {
    $feedbackIds = array_map(static fn (array $order): int => (int) $order['id'], $orders);
    $feedbackPlaceholders = implode(',', array_fill(0, count($feedbackIds), '?'));
    $feedbackStatement = db()->prepare('SELECT order_id, rating, comment, created_at FROM customer_feedback WHERE order_id IN (' . $feedbackPlaceholders . ') ORDER BY updated_at DESC, id DESC');
    $feedbackStatement->execute($feedbackIds);
    foreach ($feedbackStatement->fetchAll() as $feedback) {
        $feedbackOrderId = (int) $feedback['order_id'];
        if (!isset($orderFeedback[$feedbackOrderId])) $orderFeedback[$feedbackOrderId] = $feedback;
    }
}
// Identificar al motorizado asociado a cada pedido sin alterar la lógica de entregas.
// Se detectan nombres de tablas y columnas para admitir distintas versiones del módulo.
$orderDrivers = [];
if ($orders) {
    try {
        $pdo = db();
        $schemaStmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('delivery_assignments', 'delivery_drivers', 'drivers', 'motorizados', 'motoristas', 'driver_users')");
        $multiAssignments = []; $primaryCargo = [];
        $schema = [];
        foreach ($schemaStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $schema[(string)$column['TABLE_NAME']][(string)$column['COLUMN_NAME']] = true;
        }
        $assignCols = $schema['delivery_assignments'] ?? [];
        if (isset($assignCols['order_id'], $assignCols['driver_id'])) {
            $driverTable = null;
            $nameCol = null;
            foreach (['delivery_drivers', 'drivers', 'motorizados', 'motoristas', 'driver_users'] as $candidate) {
                $columns = $schema[$candidate] ?? [];
                if (!isset($columns['id'])) continue;
                foreach (['full_name', 'name', 'nombre', 'username', 'usuario'] as $candidateName) {
                    if (isset($columns[$candidateName])) {
                        $driverTable = $candidate;
                        $nameCol = $candidateName;
                        break 2;
                    }
                }
            }
            $orderIds = array_map(static fn(array $row): int => (int)$row['id'], $orders);
            $marks = implode(',', array_fill(0, count($orderIds), '?'));
            $selectName = $driverTable !== null
                ? ', d.`' . $nameCol . '` AS driver_name'
                : ', NULL AS driver_name';
            $joinDriver = $driverTable !== null
                ? ' LEFT JOIN `' . $driverTable . '` d ON d.id = a.driver_id'
                : '';
            $selectFinished = isset($assignCols['finished_at']) ? ', a.finished_at AS assignment_finished_at' : ', NULL AS assignment_finished_at';
            $selectDeliveryStatus = isset($assignCols['delivery_status']) ? ', a.delivery_status AS delivery_status' : ', NULL AS delivery_status';
            $sort = isset($assignCols['id']) ? 'a.id DESC' : 'a.driver_id DESC';
            $query = $pdo->prepare('SELECT a.order_id, a.driver_id' . $selectName . $selectFinished . $selectDeliveryStatus .
                ' FROM delivery_assignments a' . $joinDriver .
                ' WHERE a.order_id IN (' . $marks . ') ORDER BY ' . $sort);
            $query->execute($orderIds);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $orderId = (int)$row['order_id'];
                if (!isset($orderDrivers[$orderId])) $orderDrivers[$orderId] = $row;
            }
        }
        $multiAssignments = [];
        $primaryCargo = [];
        try {
            $multiStmt=$pdo->prepare('SELECT a.order_id,a.driver_id,a.cargo_description,a.delivery_status,a.finished_at,d.name AS driver_name FROM delivery_extra_assignments a JOIN delivery_drivers d ON d.id=a.driver_id WHERE a.order_id IN ('.$marks.') ORDER BY a.claimed_at');
            $multiStmt->execute($orderIds);
            foreach ($multiStmt->fetchAll(PDO::FETCH_ASSOC) as $extra) $multiAssignments[(int)$extra['order_id']][]=$extra;
            $cargoStmt=$pdo->prepare('SELECT order_id,cargo_description FROM delivery_primary_cargo WHERE order_id IN ('.$marks.')');$cargoStmt->execute($orderIds);
            foreach ($cargoStmt->fetchAll(PDO::FETCH_ASSOC) as $cr) $primaryCargo[(int)$cr['order_id']]=$cr['cargo_description'];
        } catch (PDOException $ignored) { /* Crear tablas con la migración. */ }
    } catch (Throwable $exception) {
        // El panel sigue disponible si el módulo de motorizados no está instalado.
        error_log('Consulta de motorizados en pedidos_yape: ' . $exception->getMessage());
    }
}
$orderCancellations = [];
if ($orders) {
    $cancelIds = array_map(static fn (array $order): int => (int) $order['id'], $orders);
    $cancelPlaceholders = implode(',', array_fill(0, count($cancelIds), '?'));
    $cancelStatement = db()->prepare('SELECT order_id, reason_type, reason_detail, created_at FROM admin_order_cancellations WHERE order_id IN (' . $cancelPlaceholders . ')');
    $cancelStatement->execute($cancelIds);
    foreach ($cancelStatement->fetchAll() as $cancelRow) $orderCancellations[(int) $cancelRow['order_id']] = $cancelRow;
}
$activeDrivers = [];
try { $activeDrivers = db()->query('SELECT id, name FROM delivery_drivers WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $exception) { error_log('Lista de motorizados: ' . $exception->getMessage()); }
$pageTitle = 'Pedidos y comprobantes';
$pageSubtitle = 'Verifica pagos conservando las instantáneas de checkout';
$activePage = 'yape-orders';
$fulfillmentLabels = [
    'recibido' => 'Pedido recibido',
    'en_preparacion' => 'En preparación',
    'listo' => 'Listo para entregar',
    'entregado' => 'Entregado',
    'cancelado' => 'Cancelado',
];
$fulfillmentNext = [
    'recibido' => ['status' => 'en_preparacion', 'label' => 'Iniciar preparación'],
    'en_preparacion' => ['status' => 'listo', 'label' => 'Marcar como listo'],
    'listo' => ['status' => 'entregado', 'label' => 'Confirmar entrega'],
];
require __DIR__ . '/../includes/admin_header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoFjJY1jI5b7g=" crossorigin="anonymous">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
<?php if(!empty($_SESSION['tracking_driver_link'])): ?>
<div style="padding:16px;border:2px solid #0a7;margin:16px 0;border-radius:10px;overflow-wrap:anywhere"><b>Enlace privado para el repartidor (cópialo ahora; al actualizar desaparecerá):</b><br><code><?= e(absolute_url((string)$_SESSION['tracking_driver_link'])) ?></code><p>El celular debe usar HTTPS y conceder permiso de ubicación. No compartas este enlace con clientes.</p></div>
<?php unset($_SESSION['tracking_driver_link']); endif; ?>
<section class="panel yape-orders-panel">
    <div class="panel-heading"><div><h2>Comprobantes y ventas</h2><p>Los importes, packs y movimientos son los registrados al crear cada pedido.</p></div></div>
    <div class="yape-order-grid">
        <?php foreach ($orders as $order): ?>
            <?php $details = $orderItems[(int) $order['id']] ?? []; ?>
            <article class="yape-order-card status-<?= e($order['status']) ?>"><div class="yape-order-info">
                <div class="yape-order-top"><span><?= e($order['order_code']) ?></span><em><?= e(ucfirst($order['status'])) ?></em></div>
                <h3>Pedido online <small>#<?= (int) $order['id'] ?></small></h3><p><?= e($order['items'] ?: 'Sin detalle') ?></p>
                <dl><div><dt>Cliente</dt><dd><?= e($order['customer_name']) ?> · <?= e($order['customer_phone'] ?? '') ?></dd></div><div><dt>Subtotal</dt><dd><?= money($order['subtotal_amount'] ?? $order['expected_amount']) ?></dd></div><div><dt>Total</dt><dd><?= money($order['expected_amount']) ?></dd></div></dl>
                <section class="yape-financial-detail"><h4>Productos y movimientos del pedido</h4><?php foreach ($details as $item): ?><div class="yape-history-row"><div><strong><?= e($item['item_name']) ?></strong><small><?= (int) $item['quantity'] ?> × <?= money($item['unit_price']) ?><?= ($item['presentation'] ?? 'unidad') === 'paquete' ? ' · Pack de ' . (int) ($item['units_per_item'] ?? 1) : '' ?></small></div><span>Stock <?= (int) $item['stock_before'] ?> → <?= $item['stock_after'] === null ? 'liberado' : (int) $item['stock_after'] ?></span><b><?= money($item['subtotal']) ?></b></div><?php endforeach; ?></section>
                <?php if ($order['status'] === 'aprobado'): ?>
                    <?php
                        $deliveryStatus = (string) ($order['fulfillment_status'] ?? 'recibido');
                        $isExpressOrder = (string) ($order['tipo_despacho'] ?? 'preparacion') === 'entrega_rapida';
                        $nextDelivery = $isExpressOrder
                            ? ($deliveryStatus === 'entregado' ? null : ['status' => 'entregado', 'label' => 'Confirmar entrega'])
                            : ($fulfillmentNext[$deliveryStatus] ?? null);
                        $assignmentForOrder = $orderDrivers[(int)$order['id']] ?? null;
                        $hasActiveAssignedDriver = $assignmentForOrder && ($assignmentForOrder['assignment_finished_at'] ?? null) === null && (int)($assignmentForOrder['driver_id'] ?? 0) > 0;
                        if ($deliveryStatus === 'listo' && $hasActiveAssignedDriver) $nextDelivery = null;
                    ?>
                    <section class="admin-fulfillment-box">
                        <div class="admin-fulfillment-title"><div><small><?= $isExpressOrder ? 'ENTREGA RÁPIDA' : 'ENTREGA DEL PEDIDO' ?></small><strong><?= e($fulfillmentLabels[$deliveryStatus] ?? 'Pedido recibido') ?></strong></div><span class="fulfillment-pill fulfillment-<?= e($deliveryStatus) ?>"><?= e($fulfillmentLabels[$deliveryStatus] ?? $deliveryStatus) ?></span></div>
                        <?php if ($isExpressOrder): ?>
                            <div class="admin-fulfillment-progress"><span class="is-active"><i>1</i>Listo para entregar</span><span class="<?= $deliveryStatus === 'entregado' ? 'is-active' : '' ?>"><i><?= $deliveryStatus === 'entregado' ? '✓' : '2' ?></i>Entregado</span></div>
                            <?php if ($deliveryStatus !== 'entregado'): ?><p>Entrega rápida: se omite la preparación. Confirma la entrega cuando el cliente reciba el pedido.</p><?php endif; ?>
                        <?php else: ?>
                            <div class="admin-fulfillment-progress">
                                <?php $deliverySteps = ['recibido', 'en_preparacion', 'listo', 'entregado']; $deliveryIndex = array_search($deliveryStatus, $deliverySteps, true); ?>
                                <?php foreach ($deliverySteps as $stepIndex => $step): ?><span class="<?= $deliveryIndex !== false && $stepIndex <= $deliveryIndex ? 'is-active' : '' ?>"><i><?= $stepIndex < (int) $deliveryIndex ? '✓' : $stepIndex + 1 ?></i><?= e($fulfillmentLabels[$step]) ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($nextDelivery): ?>
                            <form method="post" data-confirm="Actualizarás el estado visible para el cliente. ¿Deseas continuar?"><?= csrf_field() ?><input type="hidden" name="action" value="fulfillment"><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="fulfillment_status" value="<?= e($nextDelivery['status']) ?>"><button class="btn btn-primary" type="submit"><?= e($nextDelivery['label']) ?> <span>→</span></button></form>
                        <?php elseif ($deliveryStatus === 'listo' && $hasActiveAssignedDriver): ?>
                            <p>El pedido está asignado a <?= e((string)($assignmentForOrder['driver_name'] ?? 'un motorizado')) ?>. La entrega debe confirmarse desde el panel del motorizado.</p>
                        <?php else: ?>
                            <p>El flujo de entrega de este pedido finalizó.</p><a class="btn btn-secondary" href="<?= e(url('recibo.php?id=' . (int) $order['id'])) ?>" target="_blank" rel="noopener">Ver recibo digital</a>
                        <?php endif; ?>
                    </section>
                    <?php if ($deliveryStatus === 'listo' && $activeDrivers): ?>
                        <?php $assigned = $orderDrivers[(int)$order['id']] ?? null; $assignedDriverId = isset($assigned['driver_id']) ? (int)$assigned['driver_id'] : 0; ?>
                        <section class="admin-fulfillment-box" style="margin-top:14px">
                            <div class="admin-fulfillment-title"><div><small>DESPACHO DESDE ALMACÉN</small><strong><?= $assignedDriverId > 0 ? 'Motorizado asignado' : 'Asignar motorizado' ?></strong></div></div>
                            <?php if ($assignedDriverId > 0): ?><p>Asignado a: <strong><?= e((string)($assigned['driver_name'] ?? 'Motorizado')) ?></strong> · Estado: <?= e((string)($assigned['delivery_status'] ?? 'assigned')) ?></p><?php else: ?><p>El pedido está preparado. Selecciona un motorizado activo para autorizar el recojo.</p><?php endif; ?>
                            <form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="assign_driver"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><label style="min-width:220px;flex:1">Motorizado<select name="driver_id" required style="display:block;width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px"><option value="">Seleccionar...</option><?php foreach ($activeDrivers as $driverOption): ?><option value="<?= (int)$driverOption['id'] ?>" <?= $assignedDriverId === (int)$driverOption['id'] ? 'selected' : '' ?>><?= e($driverOption['name']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary" type="submit"><?= $assignedDriverId > 0 ? 'Reasignar' : 'Asignar entrega' ?></button></form>
                            <?php if ($assignedDriverId > 0): ?>
                            <div style="margin-top:17px;padding:16px;background:#f0f6ff;border-radius:13px">
                              <strong>Dividir compra entre varios motorizados</strong>
                              <p style="font-size:14px">Describe la parte exacta de la carga que corresponde a cada persona. Asigna cada artículo una sola vez.</p>
                              <form method="post" style="display:grid;gap:8px;margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="save_primary_cargo"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                <label>Carga del motorizado principal<input name="cargo_description" required maxlength="500" minlength="5" value="<?= e((string)($primaryCargo[(int)$order['id']] ?? '')) ?>" placeholder="Ej. 2 cajas: 12 paquetes de arroz" style="width:100%;padding:11px"></label>
                                <button type="submit" class="btn btn-secondary">Guardar carga principal</button>
                              </form>
                              <?php foreach (($multiAssignments[(int)$order['id']] ?? []) as $extra): ?>
                              <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;border-top:1px solid #cbd5e1;padding:12px 0;font-size:14px">
                                <span><b><?= e($extra['driver_name']) ?></b> · <?= e($extra['cargo_description']) ?> · <?= e($extra['delivery_status']) ?></span>
                                <?php if ($extra['delivery_status']==='assigned'): ?><form method="post" onsubmit="return confirm('¿Retirar este despacho sin recoger?')"><?= csrf_field() ?><input type="hidden" name="action" value="remove_extra_driver"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="driver_id" value="<?= (int)$extra['driver_id'] ?>"><button type="submit" class="btn btn-secondary">Retirar</button></form><?php endif; ?>
                              </div>
                              <?php endforeach; ?>
                              <form method="post" style="display:grid;gap:10px;border-top:1px solid #cbd5e1;padding-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="add_extra_driver"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                                <label>Otro motorizado<select name="driver_id" required style="width:100%;padding:11px"><option value="">Seleccionar...</option><?php foreach ($activeDrivers as $dr): if ((int)$dr['id'] !== $assignedDriverId): ?><option value="<?= (int)$dr['id'] ?>"><?= e($dr['name']) ?></option><?php endif; endforeach; ?></select></label>
                                <label>Productos/paquetes que transportará<input name="cargo_description" required minlength="5" maxlength="500" style="width:100%;padding:11px" placeholder="Ej. 3 cajas: 24 botellas de agua"></label>
                                <button type="submit" class="btn btn-primary">+ Añadir motorizado a este pedido</button>
                              </form>
                              <p style="font-size:13px">Total: <?= 1+count($multiAssignments[(int)$order['id']] ?? []) ?> motorizado(s). El pedido se completa cuando todos confirmen su parte.</p>
                            </div>
                            <?php endif; ?>
                        </section>
                    <?php elseif ($deliveryStatus === 'listo' && !$activeDrivers): ?><p class="muted">No hay motorizados activos. Registra o activa uno antes de asignar el despacho.</p><?php endif; ?>
                <?php endif; ?>
                <?php if ((string)$order['status']==='aprobado' && !in_array((string)($order['fulfillment_status']??''),['entregado','cancelado'],true)): ?>
                <section style="margin:18px 0;padding:15px;border:1px solid #cbd5e1;border-radius:12px">
                    <h4 style="font-size:20px;margin:0 0 6px">🗺️ Mapa de despacho y seguimiento</h4>
                    <p>Primero toca el punto de destino en el mapa. Después pulsa <strong>Guardar destino</strong>. La ubicación azul aparece cuando el motorizado activa su GPS.</p>
                    <?php $trackingOrderId=(int)$order['id'];$trackingAdminPicker=true;require __DIR__.'/../includes/delivery_map_widget.php';unset($trackingAdminPicker); ?>
                    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px"><?= csrf_field() ?><input type="hidden" name="action" value="tracking_setup"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><label>Latitud destino <input type="number" step="any" name="destination_lat" min="-90" max="90" required placeholder="-12.0464"></label><label>Longitud destino <input type="number" step="any" name="destination_lng" min="-180" max="180" required placeholder="-77.0428"></label><button type="submit" class="btn btn-secondary">Guardar destino / activar GPS</button></form>
                    <details style="margin-top:14px;border:1px solid #dce5f2;border-radius:10px;padding:12px"><summary style="cursor:pointer;font-weight:650">Opciones avanzadas: ubicación manual (solo respaldo)</summary><form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="tracking_manual"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><label>Latitud actual <input type="number" step="any" name="lat" min="-90" max="90" required></label><label>Longitud actual <input type="number" step="any" name="lng" min="-180" max="180" required></label><button type="submit" class="btn btn-secondary">Actualizar ubicación manual</button></form></details>
                    
                </section>
                <?php endif; ?>
                <?php $feedback = $orderFeedback[(int) $order['id']] ?? null; ?>
                <?php if ($feedback): ?>
                    <section class="admin-fulfillment-box" style="margin-top:16px;">
                        <div class="admin-fulfillment-title">
                            <div><small>OPINIÓN DEL CLIENTE</small><strong>Satisfacción del cliente</strong></div>
                            <span style="font-weight:700;color:#b7791f;" aria-label="<?= (int) $feedback['rating'] ?> de 5 estrellas"><?= str_repeat('★', max(0, min(5, (int) $feedback['rating']))) ?><?= str_repeat('☆', 5 - max(0, min(5, (int) $feedback['rating']))) ?></span>
                        </div>
                        <p style="margin:10px 0;white-space:pre-wrap;overflow-wrap:anywhere;"><?= trim((string) ($feedback['comment'] ?? '')) !== '' ? nl2br(e((string) $feedback['comment'])) : 'El cliente no dejó comentario.' ?></p>
                        <small>Valoración registrada: <?= e((string) $feedback['created_at']) ?></small>
                        <?php
                            $assignedDriver = $orderDrivers[(int)$order['id']] ?? null;
                            $driverName = trim((string)($assignedDriver['driver_name'] ?? ''));
                            $deliveryFinishedAt = trim((string)($order['delivered_at'] ?? ''));
                            if ($deliveryFinishedAt === '' || $deliveryFinishedAt === '0000-00-00 00:00:00') {
                                $deliveryFinishedAt = trim((string)($assignedDriver['assignment_finished_at'] ?? ''));
                            }
                        ?>
                        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:14px;padding:13px 15px;border:1px solid #e1d8eb;border-radius:10px;background:#fff;">
                            <div style="flex:1 1 210px;min-width:0;">
                                <small style="display:block;margin-bottom:4px;">MOTORIZADO RESPONSABLE</small>
                                <strong><?= e($driverName !== '' ? $driverName : ($assignedDriver ? 'Motorizado #' . (int)$assignedDriver['driver_id'] . ' (nombre no disponible)' : 'Sin motorizado registrado')) ?></strong>
                            </div>
                            <div style="flex:1 1 210px;min-width:0;">
                                <small style="display:block;margin-bottom:4px;">ENTREGA FINALIZADA</small>
                                <strong><?= e($deliveryFinishedAt !== '' && (string)($order['fulfillment_status'] ?? '') === 'entregado' ? $deliveryFinishedAt : 'Sin fecha de entrega registrada') ?></strong>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>
                <?php $cancellation = $orderCancellations[(int) $order['id']] ?? null; ?>
                <?php if ($cancellation): ?>
                    <section class="admin-fulfillment-box" style="margin-top:16px;border-color:#efb7b7;">
                        <strong>Compra cancelada</strong>
                        <p><b>Motivo:</b> <?= e(str_replace('_', ' ', (string) $cancellation['reason_type'])) ?></p>
                        <p style="white-space:pre-wrap;overflow-wrap:anywhere;"><?= e($cancellation['reason_detail']) ?></p>
                        <small>Fecha: <?= e($cancellation['created_at']) ?></small>
                    </section>
                <?php elseif (in_array((string) $order['status'], ['pendiente', 'aprobado'], true) && !in_array((string) ($order['fulfillment_status'] ?? ''), ['entregado', 'cancelado'], true)): ?>
                    <details class="admin-fulfillment-box" style="margin-top:16px;border-color:#efb7b7;">
                        <summary style="cursor:pointer;font-weight:700;color:#a52828;">Cancelar compra y registrar motivo</summary>
                        <form method="post" style="display:grid;gap:10px;margin-top:12px;" data-confirm="¿Confirmas la cancelación? Se devolverá el stock verificable y se registrará el motivo. Los reembolsos no son automáticos.">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cancel_purchase">
                            <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                            <label>Motivo obligatorio
                                <select name="cancel_reason" required style="display:block;width:100%;padding:10px;">
                                    <option value="">Seleccionar motivo</option>
                                    <option value="solicitud_cliente">Solicitud del cliente</option>
                                    <option value="sin_stock">Sin stock</option>
                                    <option value="problema_pago">Problema con el pago</option>
                                    <option value="problema_entrega">Problema con la entrega</option>
                                    <option value="otro">Otro</option>
                                </select>
                            </label>
                            <label>Explicación obligatoria
                                <textarea name="cancel_detail" required minlength="5" maxlength="1000" rows="3" style="display:block;width:100%;padding:10px;" placeholder="Explica por qué se cancela el pedido"></textarea>
                            </label>
                            <button class="btn btn-secondary" type="submit">Confirmar cancelación</button>
                        </form>
                    </details>
                <?php endif; ?>
                <?php if ($order['status'] === 'pendiente'): ?><div class="yape-review-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="status" value="rechazado"><button class="btn btn-secondary" type="submit">Rechazar</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="status" value="aprobado"><button class="btn btn-primary" type="submit">Aprobar pago</button></form></div><?php endif; ?>
            </div></article>
        <?php endforeach; ?>
        <?php if (!$orders): ?><div class="empty-state"><h3>No hay pedidos en este estado</h3></div><?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
