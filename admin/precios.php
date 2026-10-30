<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/unified_catalog.php';
require_admin();

$pageTitle = 'Precios y rentabilidad';
$pageSubtitle = 'Analiza costos, márgenes y el valor potencial del inventario';
$activePage = 'pricing';
$externalMargin = store_setting('external_default_margin_pct','25.00');
$externalPreview = [];
try {
    $externalPreview = db()->query("SELECT * FROM (" . unified_catalog_base_sql() . ") external_rows WHERE source_type='external' ORDER BY unit_profit DESC LIMIT 10")->fetchAll();
} catch (PDOException) {}

$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$category = mb_substr(trim((string) ($_GET['category'] ?? '')), 0, 80);
$stockFilter = (string) ($_GET['stock'] ?? '');
$sort = (string) ($_GET['sort'] ?? 'name');
$direction = strtolower((string) ($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$page = max(1, (int) ($_GET['page'] ?? 1));
$requestedRows = (int) ($_GET['rows'] ?? 10);
$perPage = in_array($requestedRows, [10, 20, 50], true) ? $requestedRows : 10;

$where = ["p.catalog_scope = 'master'"];
$parameters = [];
if ($query !== '') {
    $where[] = '(p.name LIKE :query_name OR p.code LIKE :query_code OR p.category LIKE :query_category)';
    $searchTerm = '%' . $query . '%';
    $parameters['query_name'] = $searchTerm;
    $parameters['query_code'] = $searchTerm;
    $parameters['query_category'] = $searchTerm;
}
if ($category !== '') {
    $where[] = 'p.category = :category';
    $parameters['category'] = $category;
}
if ($stockFilter === 'available') {
    $where[] = 'p.stock > p.min_stock';
} elseif ($stockFilter === 'low') {
    $where[] = 'p.stock > 0 AND p.stock <= p.min_stock';
} elseif ($stockFilter === 'out') {
    $where[] = 'p.stock = 0';
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$movementJoin = " LEFT JOIN (
    SELECT product_id,
           COALESCE(SUM(CASE WHEN movement_type = 'entrada' THEN units_changed ELSE 0 END), 0) AS purchased_units,
           COALESCE(SUM(CASE WHEN movement_type = 'entrada' THEN total_cost ELSE 0 END), 0) AS movement_purchase_total
    FROM inventory_movements
    GROUP BY product_id
) pm ON pm.product_id = p.id";

$purchaseQuantityExpression = 'CASE WHEN COALESCE(pm.purchased_units, 0) > 0 THEN pm.purchased_units ELSE p.stock END';
$purchaseTotalExpression = 'CASE WHEN COALESCE(pm.purchased_units, 0) > 0 THEN pm.movement_purchase_total ELSE p.cost_price * p.stock END';
$unitCostExpression = 'CASE WHEN COALESCE(pm.purchased_units, 0) > 0 THEN pm.movement_purchase_total / NULLIF(pm.purchased_units, 0) ELSE p.cost_price END';
$unitProfitExpression = '(p.price - (' . $unitCostExpression . '))';
$marginExpression = 'CASE WHEN p.price > 0 THEN ((' . $unitProfitExpression . ') / p.price) * 100 ELSE 0 END';
$salePotentialExpression = '(p.price * p.stock)';
$potentialProfitExpression = '((' . $unitProfitExpression . ') * p.stock)';

$sortExpressions = [
    'name' => 'p.name',
    'category' => 'p.category',
    'purchase_quantity' => $purchaseQuantityExpression,
    'stock' => 'p.stock',
    'purchase_total' => $purchaseTotalExpression,
    'unit_cost' => $unitCostExpression,
    'price' => 'p.price',
    'unit_profit' => $unitProfitExpression,
    'margin' => $marginExpression,
    'sale_potential' => $salePotentialExpression,
    'potential_profit' => $potentialProfitExpression,
];
if (!isset($sortExpressions[$sort])) $sort = 'name';
$orderSql = $sortExpressions[$sort] . ' ' . strtoupper($direction) . ', p.name ASC';

$countStatement = db()->prepare('SELECT COUNT(*) FROM products p' . $whereSql);
$countStatement->execute($parameters);
$total = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT p.id, p.code, p.name, p.category, p.cost_price, p.price, p.stock, p.min_stock,
               COALESCE(pm.purchased_units, 0) AS purchased_units,
               COALESCE(pm.movement_purchase_total, 0) AS movement_purchase_total,
               ' . $purchaseQuantityExpression . ' AS purchase_quantity,
               ' . $purchaseTotalExpression . ' AS purchase_total,
               ' . $unitCostExpression . ' AS unit_cost,
               ' . $unitProfitExpression . ' AS unit_profit,
               ' . $marginExpression . ' AS margin,
               ' . $salePotentialExpression . ' AS sale_potential,
               ' . $potentialProfitExpression . ' AS potential_profit
        FROM products p' . $movementJoin . $whereSql . '
        ORDER BY ' . $orderSql . '
        LIMIT :limit OFFSET :offset';
$statement = db()->prepare($sql);
foreach ($parameters as $key => $value) $statement->bindValue(':' . $key, $value);
$statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$products = $statement->fetchAll();

$summarySql = 'SELECT COALESCE(SUM(p.stock), 0) AS stock_units,
                      COALESCE(SUM(' . $purchaseTotalExpression . '), 0) AS investment,
                      COALESCE(SUM(' . $salePotentialExpression . '), 0) AS sale_potential,
                      COALESCE(SUM(' . $potentialProfitExpression . '), 0) AS potential_profit,
                      COALESCE(AVG(' . $marginExpression . '), 0) AS average_margin
               FROM products p' . $movementJoin . $whereSql;
$summaryStatement = db()->prepare($summarySql);
$summaryStatement->execute($parameters);
$summary = $summaryStatement->fetch();

$categories = db()->query("SELECT DISTINCT category FROM products WHERE catalog_scope = 'master' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$baseParameters = [
    'q' => $query,
    'category' => $category,
    'stock' => $stockFilter,
    'rows' => $perPage,
    'sort' => $sort,
    'dir' => $direction,
    'page' => $page,
];
$pricingUrl = static function (array $changes = []) use ($baseParameters): string {
    $values = array_merge($baseParameters, $changes);
    $values = array_filter($values, static fn (mixed $value): bool => $value !== '' && $value !== null);
    return '?' . http_build_query($values);
};
$sortLink = static function (string $field) use ($pricingUrl, $sort, $direction): string {
    $nextDirection = $sort === $field && $direction === 'asc' ? 'desc' : 'asc';
    return $pricingUrl(['sort' => $field, 'dir' => $nextDirection, 'page' => 1]);
};
$sortMark = static function (string $field) use ($sort, $direction): string {
    return $sort === $field ? ($direction === 'asc' ? ' ↑' : ' ↓') : ' ↕';
};

require __DIR__ . '/../includes/admin_header.php';
?>

<section class="panel external-profitability-panel">
    <div class="panel-heading"><div><h2>Ganancia estimada de proveedores</h2><p>Proyección separada de la ganancia real de Devioz.</p></div><a href="<?= url('admin/todos_productos.php') ?>">Ver todos →</a></div>
    <form class="admin-inline-form" method="post" action="<?= url('admin/producto_unificado_guardar.php') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="global_margin"><label>Margen global externo (%)<input type="number" name="margin" min="0" max="500" step="0.01" value="<?= e($externalMargin) ?>" required></label><button class="btn btn-primary">Actualizar margen</button></form>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Producto</th><th>Proveedor</th><th>Costo</th><th>Venta sugerida</th><th>Ganancia estimada</th><th>Margen</th></tr></thead><tbody><?php foreach($externalPreview as $row): ?><tr><td><strong><?= e($row['name']) ?></strong><small><?= e($row['code']) ?></small></td><td><?= e($row['source_name']) ?></td><td><?= $row['cost']!==null?money($row['cost']):'Sin precio' ?></td><td><?= $row['sale_price']!==null?money($row['sale_price']):'Sin precio' ?></td><td><?= $row['unit_profit']!==null?money($row['unit_profit']):'—' ?></td><td><?= $row['margin']!==null?number_format((float)$row['margin'],1).' %':'—' ?></td></tr><?php endforeach; ?><?php if(!$externalPreview): ?><tr><td colspan="6">Importa la migración profesional para ver esta sección.</td></tr><?php endif; ?></tbody></table></div>
</section>

<section class="profit-summary-grid">
    <article><span>Inversión filtrada</span><strong><?= money($summary['investment']) ?></strong><small>Costo del inventario</small></article>
    <article><span>Venta potencial</span><strong><?= money($summary['sale_potential']) ?></strong><small>Precio × stock</small></article>
    <article><span>Ganancia potencial</span><strong class="<?= (float) $summary['potential_profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($summary['potential_profit']) ?></strong><small>Venta menos costo</small></article>
    <article><span>Margen promedio</span><strong><?= number_format((float) $summary['average_margin'], 1) ?>%</strong><small><?= number_format((int) $summary['stock_units']) ?> unidades</small></article>
</section>

<section class="panel profitability-panel">
    <header class="profitability-heading">
        <div><span>DETALLE FINANCIERO</span><h2>Productos y rentabilidad</h2><p>Consulta, filtra y ordena costos, precios, márgenes y ganancias potenciales.</p></div>
        <a href="<?= url('admin/index.php') ?>">← Volver al Dashboard</a>
    </header>

    <form class="profitability-filters" method="get" data-profitability-filters>
        <label class="search-control"><span>⌕</span><input type="search" name="q" value="<?= e($query) ?>" placeholder="Buscar producto, código o categoría"></label>
        <select name="category" aria-label="Categoría" data-auto-submit>
            <option value="">Todas las categorías</option>
            <?php foreach ($categories as $categoryOption): ?><option value="<?= e($categoryOption) ?>" <?= $category === $categoryOption ? 'selected' : '' ?>><?= e($categoryOption) ?></option><?php endforeach; ?>
        </select>
        <select name="stock" aria-label="Stock" data-auto-submit>
            <option value="" <?= $stockFilter === '' ? 'selected' : '' ?>>Todo el stock</option>
            <option value="available" <?= $stockFilter === 'available' ? 'selected' : '' ?>>Disponible</option>
            <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Stock bajo</option>
            <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Agotado</option>
        </select>
        <select name="rows" aria-label="Filas por página" data-auto-submit>
            <?php foreach ([10, 20, 50] as $rowCount): ?><option value="<?= $rowCount ?>" <?= $perPage === $rowCount ? 'selected' : '' ?>><?= $rowCount ?> filas</option><?php endforeach; ?>
        </select>
        <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($direction) ?>">
        <button class="btn btn-primary" type="submit">Buscar</button>
        <?php if ($query !== '' || $category !== '' || $stockFilter !== ''): ?><a class="clear-filter" href="<?= url('admin/precios.php') ?>">Limpiar</a><?php endif; ?>
    </form>

    <div class="table-wrap profitability-table-wrap">
        <table class="data-table profitability-table">
            <thead><tr>
                <th><a href="<?= e($sortLink('name')) ?>">Producto<?= e($sortMark('name')) ?></a></th>
                <th><a href="<?= e($sortLink('category')) ?>">Categoría<?= e($sortMark('category')) ?></a></th>
                <th><a href="<?= e($sortLink('purchase_quantity')) ?>">Cant. compra<?= e($sortMark('purchase_quantity')) ?></a></th>
                <th><a href="<?= e($sortLink('stock')) ?>">Stock<?= e($sortMark('stock')) ?></a></th>
                <th><a href="<?= e($sortLink('purchase_total')) ?>">Compra total<?= e($sortMark('purchase_total')) ?></a></th>
                <th><a href="<?= e($sortLink('unit_cost')) ?>">Costo unit.<?= e($sortMark('unit_cost')) ?></a></th>
                <th><a href="<?= e($sortLink('price')) ?>">Venta unit.<?= e($sortMark('price')) ?></a></th>
                <th><a href="<?= e($sortLink('unit_profit')) ?>">Ganancia/u.<?= e($sortMark('unit_profit')) ?></a></th>
                <th><a href="<?= e($sortLink('margin')) ?>">Margen<?= e($sortMark('margin')) ?></a></th>
                <th><a href="<?= e($sortLink('sale_potential')) ?>">Venta potencial<?= e($sortMark('sale_potential')) ?></a></th>
                <th><a href="<?= e($sortLink('potential_profit')) ?>">Ganancia potencial<?= e($sortMark('potential_profit')) ?></a></th>
            </tr></thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <?php $state = stock_class((int) $product['stock'], (int) $product['min_stock']); ?>
                    <tr>
                        <td><strong><?= e($product['name']) ?></strong><small><?= e($product['code']) ?></small></td>
                        <td><span class="category-chip"><?= e($product['category']) ?></span></td>
                        <td><?= number_format((int) $product['purchase_quantity']) ?></td>
                        <td><span class="stock-number stock-number-<?= e($state) ?>"><?= number_format((int) $product['stock']) ?></span></td>
                        <td><?= money($product['purchase_total']) ?></td>
                        <td><strong><?= money($product['unit_cost']) ?></strong></td>
                        <td><strong><?= money($product['price']) ?></strong></td>
                        <td><strong class="<?= (float) $product['unit_profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($product['unit_profit']) ?></strong></td>
                        <td><span class="margin-pill <?= (float) $product['margin'] < 0 ? 'is-negative' : '' ?>"><?= number_format((float) $product['margin'], 1) ?>%</span></td>
                        <td><?= money($product['sale_potential']) ?></td>
                        <td><strong class="<?= (float) $product['potential_profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($product['potential_profit']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$products): ?><tr><td colspan="11"><div class="empty-mini"><strong>No hay resultados.</strong><br>Cambia los filtros o registra productos.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <footer class="profitability-footer">
        <span>Mostrando <?= $total > 0 ? number_format($offset + 1) : 0 ?>–<?= number_format(min($offset + $perPage, $total)) ?> de <?= number_format($total) ?> producto<?= $total === 1 ? '' : 's' ?></span>
        <?php if ($totalPages > 1): ?>
            <nav class="number-pagination" aria-label="Paginación de rentabilidad">
                <a class="<?= $page === 1 ? 'disabled' : '' ?>" href="<?= e($pricingUrl(['page' => max(1, $page - 1)])) ?>">‹</a>
                <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                ?>
                <?php if ($startPage > 1): ?><a href="<?= e($pricingUrl(['page' => 1])) ?>">1</a><?php if ($startPage > 2): ?><span>…</span><?php endif; ?><?php endif; ?>
                <?php for ($number = $startPage; $number <= $endPage; $number++): ?><a class="<?= $number === $page ? 'active' : '' ?>" href="<?= e($pricingUrl(['page' => $number])) ?>"><?= $number ?></a><?php endfor; ?>
                <?php if ($endPage < $totalPages): ?><?php if ($endPage < $totalPages - 1): ?><span>…</span><?php endif; ?><a href="<?= e($pricingUrl(['page' => $totalPages])) ?>"><?= $totalPages ?></a><?php endif; ?>
                <a class="<?= $page === $totalPages ? 'disabled' : '' ?>" href="<?= e($pricingUrl(['page' => min($totalPages, $page + 1)])) ?>">›</a>
            </nav>
        <?php endif; ?>
    </footer>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
