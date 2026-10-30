<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';
require_once __DIR__ . '/../includes/admin_actions.php';
require_once __DIR__ . '/../includes/delivery_incidents.php';
require_admin();

$pageTitle = 'Dashboard';
$pageSubtitle = 'Control ejecutivo del catálogo maestro';
$activePage = 'dashboard';
$schemaReady = true;
$metrics = [
    'total' => 0,
    'published' => 0,
    'low_stock' => 0,
    'missing_cost' => 0,
    'missing_price' => 0,
    'missing_pricing' => 0,
    'inventory_cost' => 0,
    'potential_profit' => 0,
];
$movementSummary = ['purchases' => 0, 'incoming_units' => 0, 'movement_count' => 0];
$dailySales = [];
$alerts = [];
$topProducts = [];
$actionItems = [];

try {
    $connection = db();
    $metrics = array_merge($metrics, master_catalog_summary($connection));
    $actionItems = admin_action_items(admin_action_counts($connection));

    $dailySales = $connection->query(
        "SELECT DATE(o.created_at) AS day,
                COALESCE(SUM(i.subtotal), 0) AS sales,
                COALESCE(SUM(i.profit), 0) AS profit
         FROM yape_order_items i
         INNER JOIN yape_orders o ON o.id = i.order_id
         WHERE o.status = 'aprobado'
           AND o.created_at >= CURRENT_DATE - INTERVAL 29 DAY
         GROUP BY DATE(o.created_at)
         ORDER BY day"
    )->fetchAll();

    $movementSummary = array_merge($movementSummary, $connection->query(
        "SELECT COALESCE(SUM(CASE WHEN m.movement_type = 'entrada' THEN m.total_cost ELSE 0 END), 0) AS purchases,
                COALESCE(SUM(CASE WHEN m.movement_type = 'entrada' THEN m.units_changed ELSE 0 END), 0) AS incoming_units,
                COUNT(*) AS movement_count
         FROM inventory_movements m
         INNER JOIN products p ON p.id = m.product_id AND p.catalog_scope = 'master'
         WHERE m.moved_at >= CURRENT_DATE - INTERVAL 29 DAY"
    )->fetch() ?: []);

    $alerts = $connection->query(
        "SELECT id, code, name, source_name, stock, min_stock, cost_price, price, restricted
         FROM products
         WHERE catalog_scope = 'master'
           AND (restricted = 1 OR stock <= min_stock OR cost_price <= 0 OR price <= 0)
         ORDER BY restricted DESC, (cost_price <= 0 OR price <= 0) DESC, (stock <= min_stock) DESC, name ASC
         LIMIT 8"
    )->fetchAll();

    $topProducts = $connection->query(
        "SELECT id, code, name, source_name, stock, cost_price, price,
                ROUND((price - cost_price) * stock, 2) AS potential_profit
         FROM products
         WHERE catalog_scope = 'master' AND cost_price > 0 AND price > 0 AND stock > 0
         ORDER BY potential_profit DESC, name ASC
         LIMIT 6"
    )->fetchAll();
} catch (PDOException) {
    $schemaReady = false;
}

$logistics = ['ready' => 0, 'assigned' => 0, 'in_transit' => 0];
try {
    $logistics = array_merge($logistics, db()->query("SELECT
        SUM(CASE WHEN o.status='aprobado' AND o.fulfillment_status='listo' AND (a.order_id IS NULL OR a.finished_at IS NOT NULL) THEN 1 ELSE 0 END) AS ready,
        SUM(CASE WHEN a.finished_at IS NULL AND a.delivery_status IN ('assigned','picked_up') THEN 1 ELSE 0 END) AS assigned,
        SUM(CASE WHEN a.finished_at IS NULL AND a.delivery_status='in_transit' THEN 1 ELSE 0 END) AS in_transit
        FROM yape_orders o LEFT JOIN delivery_assignments a ON a.order_id=o.id")->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $exception) {
    // El dashboard general sigue cargando si la migración logística aún no se ejecutó.
}

$incidentCount = 0;
$incidentOpen = 0;
$recentIncidents = [];
$incidentsReady = true;
try {
    $incidentDb = db();
    delivery_incidents_initialize($incidentDb);
    $incidentStats = $incidentDb->query("SELECT COUNT(*) total, SUM(CASE WHEN status != 'resuelta' THEN 1 ELSE 0 END) open_count FROM delivery_incidents")->fetch(PDO::FETCH_ASSOC) ?: [];
    $incidentCount = (int)($incidentStats['total'] ?? 0);
    $incidentOpen = (int)($incidentStats['open_count'] ?? 0);
    $recentIncidents = $incidentDb->query("SELECT i.id,i.order_id,i.description,i.reported_at,i.status,o.order_code,d.name AS driver_name
        FROM delivery_incidents i
        LEFT JOIN yape_orders o ON o.id=i.order_id
        LEFT JOIN delivery_drivers d ON d.id=i.driver_id
        ORDER BY i.reported_at DESC,i.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    $incidentsReady = false;
}

$salesByDay = [];
foreach ($dailySales as $row) {
    $salesByDay[(string) $row['day']] = [
        'sales' => (float) $row['sales'],
        'profit' => (float) $row['profit'],
    ];
}

$chartLabels = [];
$chartSales = [];
$chartProfits = [];
$today = new DateTimeImmutable('today');
for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
    $date = $today->modify('-' . $daysAgo . ' days');
    $key = $date->format('Y-m-d');
    $chartLabels[] = $date->format('d/m');
    $chartSales[] = $salesByDay[$key]['sales'] ?? 0.0;
    $chartProfits[] = $salesByDay[$key]['profit'] ?? 0.0;
}

$realSales = array_sum($chartSales);
$realProfit = array_sum($chartProfits);
$alertTotal = (int) $metrics['missing_pricing'] + (int) $metrics['low_stock'];
$lastSevenProfit = array_sum(array_slice($chartProfits, -7));
$previousSevenProfit = array_sum(array_slice($chartProfits, -14, 7));
$profitDelta = $previousSevenProfit !== 0.0
    ? (($lastSevenProfit - $previousSevenProfit) / abs($previousSevenProfit)) * 100
    : null;

if ((int) $metrics['missing_pricing'] > 0) {
    $insightTitle = 'Prioriza la calidad del precio';
    $insightText = number_format((int) $metrics['missing_pricing']) . ' productos requieren costo o precio antes de poder medir y publicar su rentabilidad con confianza.';
    $insightHref = url('admin/todos_productos.php?status=pending');
    $insightAction = 'Revisar pendientes';
} elseif ((int) $metrics['low_stock'] > 0) {
    $insightTitle = 'Conviene preparar reposición';
    $insightText = number_format((int) $metrics['low_stock']) . ' productos maestros están en su mínimo de stock o por debajo.';
    $insightHref = url('admin/todos_productos.php?status=out_of_stock');
    $insightAction = 'Ver inventario crítico';
} elseif ($profitDelta !== null) {
    $insightTitle = $profitDelta >= 0 ? 'La rentabilidad reciente avanza' : 'La rentabilidad reciente bajó';
    $insightText = 'La ganancia aprobada de los últimos 7 días cambió ' . number_format(abs($profitDelta), 1) . '% frente a los 7 días anteriores.';
    $insightHref = url('admin/pedidos_yape.php?status=aprobado');
    $insightAction = 'Revisar ventas aprobadas';
} else {
    $insightTitle = 'Catálogo listo para medir';
    $insightText = 'Aprueba ventas para construir una referencia real de ingresos y ganancia a partir de las instantáneas de cada pedido.';
    $insightHref = url('admin/pedidos_yape.php');
    $insightAction = 'Revisar pedidos';
}

$alertLabel = static function (array $product): string {
    if ((int) $product['restricted'] === 1) return 'Restringido';
    if ((float) $product['cost_price'] <= 0) return 'Sin costo';
    if ((float) $product['price'] <= 0) return 'Sin precio';
    if ((int) $product['stock'] <= 0) return 'Sin stock';
    return 'Stock bajo';
};

$alertClass = static function (array $product): string {
    if ((int) $product['restricted'] === 1) return 'restricted';
    if ((float) $product['cost_price'] <= 0 || (float) $product['price'] <= 0) return 'pricing';
    return 'stock';
};

require __DIR__ . '/../includes/admin_header.php';
?>

<div class="catalog-dashboard">
    <?php if (!$schemaReady): ?>
        <div class="alert alert-warning"><span>Importa <strong>database/upgrade_catalogo_maestro_11604.sql</strong> para activar todos los indicadores.</span></div>
    <?php endif; ?>

    <section class="dashboard-welcome" aria-labelledby="dashboardWelcomeTitle">
        <div>
            <span class="dashboard-eyebrow">PANORAMA DEL NEGOCIO</span>
            <h2 id="dashboardWelcomeTitle">Decisiones claras, con datos del catálogo maestro</h2>
            <p>La ganancia real usa exclusivamente pedidos aprobados; la proyección usa el stock maestro actual.</p>
        </div>
        <div class="dashboard-welcome-actions">
            <a class="btn btn-secondary" href="<?= url('admin/todos_productos.php') ?>">Explorar catálogo</a>
            <a class="btn btn-primary" href="<?= url('admin/producto_form.php') ?>">＋ Nuevo producto</a>
        </div>
    </section>

    <section class="panel admin-action-inbox">
        <div class="dashboard-panel-heading"><div><span>PENDIENTES</span><h2>Qué atender primero</h2><p>Acciones concretas para operar sin revisar módulos de más.</p></div></div>
        <div class="admin-action-grid">
            <?php foreach ($actionItems as $item): ?>
                <a class="admin-action-card is-<?= e($item['priority']) ?>" href="<?= url($item['href']) ?>"><strong><?= number_format((int) $item['count']) ?></strong><span><?= e($item['label']) ?></span><b>Resolver</b></a>
            <?php endforeach; ?>
            <?php if (!$actionItems): ?><div class="empty-mini">No hay pendientes prioritarios ahora.</div><?php endif; ?>
        </div>
    </section>

    <section class="dashboard-kpis" aria-label="Indicadores principales">
        <a class="dashboard-kpi kpi-catalog" href="<?= url('admin/todos_productos.php') ?>">
            <span class="dashboard-kpi-icon">▦</span><div><small>Productos maestros</small><strong><?= number_format((int) $metrics['total']) ?></strong><em>Base activa de gestión</em></div>
        </a>
        <a class="dashboard-kpi kpi-published" href="<?= url('admin/todos_productos.php?status=published') ?>">
            <span class="dashboard-kpi-icon">✓</span><div><small>Productos publicados</small><strong><?= number_format((int) $metrics['published']) ?></strong><em>Aptos para el catálogo público</em></div>
        </a>
        <a class="dashboard-kpi kpi-real" href="<?= url('admin/pedidos_yape.php?status=aprobado') ?>">
            <span class="dashboard-kpi-icon">S/</span><div><small>Ganancia real · 30 días</small><strong><?= money($realProfit) ?></strong><em><?= money($realSales) ?> en ventas aprobadas</em></div>
        </a>
        <a class="dashboard-kpi kpi-potential" href="<?= url('admin/precios.php') ?>">
            <span class="dashboard-kpi-icon">↗</span><div><small>Ganancia potencial</small><strong><?= money((float) $metrics['potential_profit']) ?></strong><em>Stock maestro al precio actual</em></div>
        </a>
        <a class="dashboard-kpi kpi-alert" href="<?= url('admin/todos_productos.php?status=pending') ?>">
            <span class="dashboard-kpi-icon">!</span><div><small>Alertas de catálogo</small><strong><?= number_format($alertTotal) ?></strong><em>Sin costo <?= number_format((int) $metrics['missing_cost']) ?> · Sin precio <?= number_format((int) $metrics['missing_price']) ?></em></div>
        </a>
        <a class="dashboard-kpi kpi-potential" href="<?= url('admin/pedidos_yape.php?status=aprobado') ?>">
            <span class="dashboard-kpi-icon">↗</span><div><small>Despachos por gestionar</small><strong><?= number_format((int)$logistics['ready']) ?></strong><em><?= number_format((int)$logistics['assigned']) ?> asignados · <?= number_format((int)$logistics['in_transit']) ?> en camino</em></div>
        </a>
    </section>

    <section class="panel" aria-labelledby="incidentDashboardTitle" style="margin:22px 0;padding:24px">
        <header class="dashboard-panel-heading" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <div><span>LOGÍSTICA · ALERTAS DE ENTREGA</span><h2 id="incidentDashboardTitle">Incidencias de motorizados</h2>
            <p><?= $incidentsReady ? number_format($incidentOpen).' por revisar · '.number_format($incidentCount).' registradas en total' : 'No se pudo cargar el registro. Verifica los permisos de la base de datos.' ?></p></div>
            <a class="btn btn-primary" href="<?= url('admin/incidencias.php') ?>">Ver todas las incidencias →</a>
        </header>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Pedido</th><th>Motorizado</th><th>Incidencia</th><th>Fecha</th><th>Estado</th></tr></thead><tbody>
            <?php foreach ($recentIncidents as $incident): ?><tr>
                <td><strong><?= e($incident['order_code'] ?: '#'.$incident['order_id']) ?></strong></td>
                <td><?= e($incident['driver_name'] ?: 'Motorizado no disponible') ?></td>
                <td><?= e($incident['description']) ?></td>
                <td><?= e(date('d/m/Y H:i', strtotime($incident['reported_at']))) ?></td>
                <td><strong><?= e(str_replace('_',' ',ucfirst($incident['status']))) ?></strong></td>
            </tr><?php endforeach; ?>
            <?php if (!$recentIncidents): ?><tr><td colspan="5"><div class="empty-mini"><?= $incidentsReady ? 'No hay incidencias registradas.' : 'El listado no está disponible.' ?></div></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="dashboard-primary-grid">
        <article class="panel dashboard-chart-panel">
            <header class="dashboard-panel-heading">
                <div><span>ÚLTIMOS 30 DÍAS</span><h2>Ventas y ganancia aprobada</h2><p>Instantáneas financieras guardadas en cada artículo del pedido.</p></div>
                <div class="dashboard-chart-legend"><span><i class="legend-sales"></i>Ventas</span><span><i class="legend-profit"></i>Ganancia</span></div>
            </header>
            <div class="dashboard-chart-summary"><div><span>Ventas</span><strong><?= money($realSales) ?></strong></div><div><span>Ganancia real</span><strong><?= money($realProfit) ?></strong></div></div>
            <div class="dashboard-trend-chart" data-dashboard-chart data-labels="<?= e(json_encode($chartLabels, JSON_UNESCAPED_SLASHES)) ?>" data-sales="<?= e(json_encode($chartSales, JSON_UNESCAPED_SLASHES)) ?>" data-profits="<?= e(json_encode($chartProfits, JSON_UNESCAPED_SLASHES)) ?>" role="img" aria-label="Gráfico de ventas y ganancia real de los últimos 30 días">
                <p class="dashboard-chart-fallback">El gráfico se activa con JavaScript. Ventas: <?= money($realSales) ?>; ganancia real: <?= money($realProfit) ?>.</p>
            </div>
        </article>

        <aside class="dashboard-insight" aria-labelledby="dashboardInsightTitle">
            <div class="dashboard-insight-badge"><span>✦</span> Insight IA</div>
            <h2 id="dashboardInsightTitle"><?= e($insightTitle) ?></h2>
            <p><?= e($insightText) ?></p>
            <a href="<?= e($insightHref) ?>"><?= e($insightAction) ?> →</a>
            <div class="dashboard-insight-meta"><span>Actualizado ahora</span><span>Análisis automático</span></div>
        </aside>
    </section>

    <section class="dashboard-operations" aria-label="Actividad de inventario de los últimos 30 días">
        <div><span>Compras registradas</span><strong><?= money((float) $movementSummary['purchases']) ?></strong></div>
        <div><span>Unidades recibidas</span><strong><?= number_format((int) $movementSummary['incoming_units']) ?></strong></div>
        <div><span>Movimientos maestros</span><strong><?= number_format((int) $movementSummary['movement_count']) ?></strong></div>
        <div><span>Inversión en stock</span><strong><?= money((float) $metrics['inventory_cost']) ?></strong></div>
    </section>

    <section class="dashboard-secondary-grid">
        <article class="panel dashboard-alerts-panel">
            <header class="dashboard-panel-heading">
                <div><span>ACCIÓN REQUERIDA</span><h2>Alertas de inventario</h2><p>Incluye productos restringidos para que administración pueda gestionarlos; nunca los publica.</p></div>
                <a href="<?= url('admin/todos_productos.php') ?>">Ver catálogo →</a>
            </header>
            <div class="dashboard-alert-list">
                <?php foreach ($alerts as $product): ?>
                    <a href="<?= url('admin/producto_form.php?id=' . (int) $product['id']) ?>">
                        <span class="dashboard-alert-mark alert-<?= e($alertClass($product)) ?>">!</span>
                        <div><strong><?= e($product['name']) ?></strong><small><?= e($product['source_name'] ?: $product['code']) ?> · <?= number_format((int) $product['stock']) ?> und.</small></div>
                        <em><?= e($alertLabel($product)) ?></em><b>›</b>
                    </a>
                <?php endforeach; ?>
                <?php if (!$alerts): ?><div class="empty-mini">No hay alertas prioritarias en el catálogo maestro.</div><?php endif; ?>
            </div>
        </article>

        <article class="panel dashboard-top-panel">
            <header class="dashboard-panel-heading">
                <div><span>PROYECCIÓN ACTUAL</span><h2>Mayor rentabilidad potencial</h2><p>Productos maestros con stock, costo y precio vigentes.</p></div>
                <a href="<?= url('admin/precios.php?sort=potential_profit&dir=desc') ?>">Ver rentabilidad →</a>
            </header>
            <div class="table-wrap">
                <table class="data-table dashboard-profit-table">
                    <thead><tr><th>Producto</th><th>Stock</th><th>Margen/u.</th><th>Potencial</th></tr></thead>
                    <tbody>
                        <?php foreach ($topProducts as $product): ?>
                            <tr><td><strong><?= e($product['name']) ?></strong><small><?= e($product['source_name'] ?: $product['code']) ?></small></td><td><?= number_format((int) $product['stock']) ?></td><td><?= money((float) $product['price'] - (float) $product['cost_price']) ?></td><td><strong class="text-success"><?= money((float) $product['potential_profit']) ?></strong></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$topProducts): ?><tr><td colspan="4"><div class="empty-mini">Completa costo, precio y stock para ver la proyección.</div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <p class="dashboard-scope-note">La ganancia real se calcula solo con artículos de pedidos aprobados. Productos externos y su ganancia estimada se consultan por separado en <a href="<?= url('admin/precios.php') ?>">Rentabilidad</a>.</p>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
