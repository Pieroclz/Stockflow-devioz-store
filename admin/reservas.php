<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/yape_order_snapshots.php';
require_admin();
if (($_SESSION['admin_role'] ?? 'admin') !== 'admin') { http_response_code(403); exit('Sin permiso.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $connection = db();
    $released = 0;
    try {
        $connection->beginTransaction();
        $orders = $connection->query("SELECT DISTINCT o.id FROM yape_orders o INNER JOIN stock_reservations r ON r.order_id=o.id WHERE o.status='pendiente' AND r.status='active' AND r.expires_at<=NOW() ORDER BY o.id FOR UPDATE")->fetchAll();
        foreach ($orders as $order) {
            $orderId = (int) $order['id'];
            $connection->exec('SAVEPOINT yape_expiry_order');
            try {
                $orderQuery = $connection->prepare('SELECT * FROM yape_orders WHERE id = ? AND status = \'pendiente\' FOR UPDATE');
                $orderQuery->execute([$orderId]);
                $lockedOrder = $orderQuery->fetch();
                if (!$lockedOrder) { $connection->exec('ROLLBACK TO SAVEPOINT yape_expiry_order'); continue; }
                $itemsQuery = $connection->prepare('SELECT * FROM yape_order_items WHERE order_id = ? ORDER BY id FOR UPDATE');
                $itemsQuery->execute([$orderId]);
                $items = $itemsQuery->fetchAll();
                $reservationSnapshot = yape_lock_active_reservations($connection, $orderId);
                yape_assert_snapshot_coherence($connection, $lockedOrder, $items, $reservationSnapshot);
                yape_release_reservations($connection, $reservationSnapshot, 'expired');
                yape_release_manual_combo_caps($connection, $items, (int) $lockedOrder['inventory_snapshot_version']);
                yape_delete_provisional_movements($connection, (string) $lockedOrder['order_code'], (string) $lockedOrder['metodo_pago']);
                $update = $connection->prepare("UPDATE yape_orders SET status='rechazado', fulfillment_status='cancelado', reviewed_at=NOW(), status_updated_at=NOW() WHERE id=? AND status='pendiente'");
                $update->execute([$orderId]);
                if ($update->rowCount() !== 1) throw new RuntimeException('El pedido cambió mientras se liberaba.');
                $released++;
                $connection->exec('RELEASE SAVEPOINT yape_expiry_order');
            } catch (Throwable $orderError) {
                $connection->exec('ROLLBACK TO SAVEPOINT yape_expiry_order');
                error_log('StockFlow: reserva vencida requiere conciliación manual: ' . $orderError->getMessage());
            }
        }
        $connection->commit();
        flash('success', $released . ' pedido(s) vencido(s) liberados; los incoherentes requieren conciliación manual.');
    } catch (Throwable) {
        if ($connection->inTransaction()) $connection->rollBack();
        flash('danger', 'No se pudieron liberar las reservas. No se aplicó ningún cambio.');
    }
    redirect('admin/reservas.php');
}

$summary = db()->query("SELECT SUM(status='active') active_count, SUM(status='converted') converted_count, SUM(status IN('released','expired')) released_count FROM stock_reservations")->fetch() ?: [];
$rows = db()->query("SELECT r.*,o.order_code,o.status order_status,p.name product_name FROM stock_reservations r INNER JOIN yape_orders o ON o.id=r.order_id INNER JOIN products p ON p.id=r.product_id ORDER BY r.created_at DESC LIMIT 200")->fetchAll();
$expired = (int) db()->query("SELECT COUNT(DISTINCT order_id) FROM stock_reservations WHERE status='active' AND expires_at<=NOW()")->fetchColumn();
$pageTitle = 'Reservas de stock'; $pageSubtitle = 'Controla pedidos pendientes y devuelve inventario vencido'; $activePage = 'reservations';
require __DIR__ . '/../includes/admin_header.php';
?>
<section class="metric-grid"><article class="metric-card metric-orange"><div><span>Activas</span><strong><?= (int) ($summary['active_count'] ?? 0) ?></strong></div></article><article class="metric-card metric-green"><div><span>Convertidas</span><strong><?= (int) ($summary['converted_count'] ?? 0) ?></strong></div></article><article class="metric-card metric-blue"><div><span>Liberadas</span><strong><?= (int) ($summary['released_count'] ?? 0) ?></strong></div></article></section>
<section class="panel"><div class="panel-heading"><div><h2>Historial de reservas</h2><p>Solo pedidos coherentes se liberan automáticamente.</p></div><?php if ($expired > 0): ?><form method="post"><?= csrf_field() ?><button class="btn btn-primary">Liberar <?= $expired ?> vencida<?= $expired === 1 ? '' : 's' ?></button></form><?php endif; ?></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Pedido</th><th>Producto</th><th>Unidades</th><th>Estado</th><th>Vence</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= e($row['order_code']) ?></td><td><?= e($row['product_name']) ?></td><td><?= (int) $row['quantity'] ?></td><td><?= e(ucfirst($row['status'])) ?></td><td><?= e(date('d/m/Y H:i', strtotime($row['expires_at']))) ?></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="5">Aún no existen reservas.</td></tr><?php endif; ?></tbody></table></div></section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
