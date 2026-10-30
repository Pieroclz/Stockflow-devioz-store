<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_admin();

// The historical snapshot is intentionally available to administrators only.
if ((string) ($_SESSION['admin_role'] ?? 'admin') !== 'admin') {
    http_response_code(403);
    exit('Sin permiso.');
}

$connection = db();
$legacyTables = [
    'legacy_products' => 'Productos',
    'legacy_inventory_movements' => 'Movimientos',
    'legacy_product_images' => 'Imágenes',
    'legacy_combos' => 'Combos',
    'legacy_combo_items' => 'Ítems de combos',
    'legacy_store_featured_products' => 'Destacados',
    'legacy_stock_reservations' => 'Reservas',
];

/**
 * A migration can be installed in stages. Check the information schema before
 * querying each snapshot table so this page remains useful during an upgrade.
 */
function legacy_table_exists(PDO $connection, string $table): bool
{
    try {
        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<int, array<string, mixed>> */
function legacy_fetch_all(PDO $connection, string $sql, array $parameters = []): array
{
    try {
        $statement = $connection->prepare($sql);
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    } catch (Throwable) {
        return [];
    }
}

function legacy_count(PDO $connection, string $table, bool $available): int
{
    if (!$available) {
        return 0;
    }
    try {
        return (int) $connection->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function legacy_page_size(mixed $value): int
{
    $size = (int) $value;
    return in_array($size, [25, 50, 100], true) ? $size : 25;
}

function legacy_count_query(PDO $connection, string $sql, array $parameters = []): int
{
    $rows = legacy_fetch_all($connection, $sql, $parameters);
    return (int) ($rows[0]['total'] ?? 0);
}

$availableTables = [];
$totals = [];
foreach ($legacyTables as $table => $label) {
    $availableTables[$table] = legacy_table_exists($connection, $table);
    $totals[$table] = legacy_count($connection, $table, $availableTables[$table]);
}

$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['rows'] ?? 25);
if (!in_array($perPage, [25, 50, 100], true)) {
    $perPage = 25;
}
$searchTerm = '%' . $query . '%';
$productTotal = $totals['legacy_products'];
$totalPages = max(1, (int) ceil($productTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$products = [];

if ($availableTables['legacy_products']) {
    $products = legacy_fetch_all(
        $connection,
        'SELECT id, code, name, category, source_name, source_product_id, ean, brand,
                presentation, cost_price, price, stock, image_url, active, updated_at
         FROM legacy_products
         WHERE (name LIKE :query_name OR code LIKE :query_code OR category LIKE :query_category)
         ORDER BY id DESC
         LIMIT :limit OFFSET :offset',
        [
            'query_name' => $searchTerm,
            'query_code' => $searchTerm,
            'query_category' => $searchTerm,
            'limit' => $perPage,
            'offset' => $offset,
        ]
    );
    if ($query !== '') {
        // The total shown in the summary must match the prepared search.
        $filtered = legacy_fetch_all(
            $connection,
            'SELECT COUNT(*) AS total
             FROM legacy_products
             WHERE (name LIKE :query_name OR code LIKE :query_code OR category LIKE :query_category)',
            [
                'query_name' => $searchTerm,
                'query_code' => $searchTerm,
                'query_category' => $searchTerm,
            ]
        );
        $productTotal = (int) ($filtered[0]['total'] ?? 0);
        $totalPages = max(1, (int) ceil($productTotal / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
            $products = legacy_fetch_all(
                $connection,
                'SELECT id, code, name, category, source_name, source_product_id, ean, brand,
                        presentation, cost_price, price, stock, image_url, active, updated_at
                 FROM legacy_products
                 WHERE (name LIKE :query_name OR code LIKE :query_code OR category LIKE :query_category)
                 ORDER BY id DESC
                 LIMIT :limit OFFSET :offset',
                [
                    'query_name' => $searchTerm,
                    'query_code' => $searchTerm,
                    'query_category' => $searchTerm,
                    'limit' => $perPage,
                    'offset' => $offset,
                ]
            );
        }
    }
}

$selectedProductId = max(0, (int) ($_GET['product_id'] ?? 0));
$selectedProduct = null;
$selectedMovements = [];
$selectedImages = [];
$selectedCombos = [];
$movementTotal = 0;
$imageTotal = 0;
$comboTotal = 0;
$movementPages = 1;
$imagePages = 1;
$comboPages = 1;
$movementPage = max(1, (int) ($_GET['movement_page'] ?? 1));
$imagePage = max(1, (int) ($_GET['image_page'] ?? 1));
$comboPage = max(1, (int) ($_GET['combo_page'] ?? 1));
$movementPerPage = legacy_page_size($_GET['movement_rows'] ?? 25);
$imagePerPage = legacy_page_size($_GET['image_rows'] ?? 25);
$comboPerPage = legacy_page_size($_GET['combo_rows'] ?? 25);
if ($selectedProductId > 0 && $availableTables['legacy_products']) {
    $selectedRows = legacy_fetch_all(
        $connection,
        'SELECT id, code, name, category, source_name, source_product_id, ean, brand,
                presentation, cost_price, price, stock, image_url, active, updated_at
         FROM legacy_products WHERE id = :id LIMIT 1',
        ['id' => $selectedProductId]
    );
    $selectedProduct = $selectedRows[0] ?? null;
    if ($selectedProduct) {
        if ($availableTables['legacy_inventory_movements']) {
            $movementTotal = legacy_count_query(
                $connection,
                'SELECT COUNT(*) AS total FROM legacy_inventory_movements WHERE product_id = :product_id',
                ['product_id' => $selectedProductId]
            );
            $movementPages = max(1, (int) ceil($movementTotal / $movementPerPage));
            $movementPage = min($movementPage, $movementPages);
            $selectedMovements = legacy_fetch_all(
                $connection,
                'SELECT movement_type, presentation, quantity, units_changed, unit_cost,
                        sale_price, total_cost, total_income, profit, payment_method,
                        reference, notes, moved_at
                 FROM legacy_inventory_movements
                 WHERE product_id = :product_id
                 ORDER BY moved_at DESC, id DESC
                 LIMIT :movement_limit OFFSET :movement_offset',
                [
                    'product_id' => $selectedProductId,
                    'movement_limit' => $movementPerPage,
                    'movement_offset' => ($movementPage - 1) * $movementPerPage,
                ]
            );
        }
        if ($availableTables['legacy_product_images']) {
            $imageTotal = legacy_count_query(
                $connection,
                'SELECT COUNT(*) AS total FROM legacy_product_images WHERE product_id = :product_id',
                ['product_id' => $selectedProductId]
            );
            $imagePages = max(1, (int) ceil($imageTotal / $imagePerPage));
            $imagePage = min($imagePage, $imagePages);
            $selectedImages = legacy_fetch_all(
                $connection,
                'SELECT image_url, sort_order
                 FROM legacy_product_images
                 WHERE product_id = :product_id
                 ORDER BY sort_order, id
                 LIMIT :image_limit OFFSET :image_offset',
                [
                    'product_id' => $selectedProductId,
                    'image_limit' => $imagePerPage,
                    'image_offset' => ($imagePage - 1) * $imagePerPage,
                ]
            );
        }
        if ($availableTables['legacy_combos'] && $availableTables['legacy_combo_items']) {
            $comboTotal = legacy_count_query(
                $connection,
                'SELECT COUNT(*) AS total
                 FROM legacy_combo_items ci
                 INNER JOIN legacy_combos c ON c.id = ci.combo_id
                 WHERE ci.product_id = :product_id',
                ['product_id' => $selectedProductId]
            );
            $comboPages = max(1, (int) ceil($comboTotal / $comboPerPage));
            $comboPage = min($comboPage, $comboPages);
            $selectedCombos = legacy_fetch_all(
                $connection,
                'SELECT c.id, c.name, c.description, c.price, c.stock, c.active, ci.quantity
                 FROM legacy_combos c
                 INNER JOIN legacy_combo_items ci ON ci.combo_id = c.id
                 WHERE ci.product_id = :product_id
                 ORDER BY c.name, c.id
                 LIMIT :combo_limit OFFSET :combo_offset',
                [
                    'product_id' => $selectedProductId,
                    'combo_limit' => $comboPerPage,
                    'combo_offset' => ($comboPage - 1) * $comboPerPage,
                ]
            );
        }
    }
}

function legacy_view_query(array $changes = []): string
{
    $values = [
        'q' => trim((string) ($_GET['q'] ?? '')),
        'rows' => (int) ($_GET['rows'] ?? 25),
        'page' => (int) ($_GET['page'] ?? 1),
        'product_id' => max(0, (int) ($_GET['product_id'] ?? 0)),
        'movement_page' => max(1, (int) ($_GET['movement_page'] ?? 1)),
        'image_page' => max(1, (int) ($_GET['image_page'] ?? 1)),
        'combo_page' => max(1, (int) ($_GET['combo_page'] ?? 1)),
        'movement_rows' => legacy_page_size($_GET['movement_rows'] ?? 25),
        'image_rows' => legacy_page_size($_GET['image_rows'] ?? 25),
        'combo_rows' => legacy_page_size($_GET['combo_rows'] ?? 25),
    ];
    return http_build_query(array_filter(array_merge($values, $changes), static fn (mixed $value): bool => $value !== '' && $value !== null));
}

$pageTitle = 'Respaldo anterior';
$pageSubtitle = 'Consulta histórica protegida y de solo lectura';
$activePage = 'legacy-backup';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="unified-metrics" aria-label="Totales del respaldo anterior">
    <?php foreach (['legacy_products', 'legacy_inventory_movements', 'legacy_product_images', 'legacy_combos'] as $table): ?>
        <article><span><?= e($legacyTables[$table]) ?></span><strong><?= number_format($totals[$table]) ?></strong><small><?= $availableTables[$table] ? 'snapshot disponible' : 'tabla no disponible' ?></small></article>
    <?php endforeach; ?>
</section>

<?php if (in_array(false, $availableTables, true)): ?>
    <div class="alert alert-warning"><span>Algunas tablas históricas todavía no están disponibles. La información existente se mantiene en modo consulta.</span></div>
<?php endif; ?>

<section class="panel unified-catalog-panel">
    <div class="panel-heading">
        <div><h2>Productos históricos</h2><p>Registros del inventario anterior; esta pantalla no permite modificarlos.</p></div>
        <a class="btn btn-secondary" href="<?= url('admin/respaldo.php') ?>">Descargar base de datos</a>
    </div>
    <form class="unified-filters" method="get">
        <label><span>Buscar</span><input type="search" name="q" value="<?= e($query) ?>" placeholder="Nombre, código o categoría"></label>
        <label><span>Filas</span><select name="rows"><?php foreach ([25, 50, 100] as $rows): ?><option value="<?= $rows ?>" <?= $perPage === $rows ? 'selected' : '' ?>><?= $rows ?></option><?php endforeach; ?></select></label>
        <div class="unified-filter-actions"><button class="btn btn-primary" type="submit">Aplicar</button><a href="<?= url('admin/respaldo_anterior.php') ?>">Limpiar</a></div>
    </form>

    <div class="table-summary"><p><strong><?= number_format($productTotal) ?></strong> producto<?= $productTotal === 1 ? '' : 's' ?> históricos</p><small>Página <?= $page ?> de <?= $totalPages ?></small></div>
    <div class="table-wrap"><table class="data-table unified-table"><thead><tr><th>Producto</th><th>Origen</th><th>Categoría</th><th>EAN</th><th>Costo</th><th>Precio</th><th>Stock</th><th>Consulta</th></tr></thead><tbody>
        <?php foreach ($products as $product): ?>
            <tr><td><strong><?= e($product['name'] ?? 'Sin nombre') ?></strong><small><?= e($product['code'] ?? '') ?></small></td><td><?= e($product['source_name'] ?? 'Devioz') ?></td><td><?= e($product['category'] ?? '') ?></td><td><?= e($product['ean'] ?? '') ?></td><td><?= money((float) ($product['cost_price'] ?? 0)) ?></td><td><?= money((float) ($product['price'] ?? 0)) ?></td><td><?= number_format((int) ($product['stock'] ?? 0)) ?></td><td><a class="table-action" href="?<?= e(legacy_view_query(['product_id' => (int) $product['id']])) ?>">Ver detalle</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="8"><div class="empty-mini"><strong><?= $availableTables['legacy_products'] ? 'No hay resultados.' : 'El respaldo aún no está disponible.' ?></strong><br>Las tablas históricas se muestran cuando la migración las crea.</div></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Paginación histórica"><a class="<?= $page === 1 ? 'disabled' : '' ?>" href="?<?= e(legacy_view_query(['page' => max(1, $page - 1)])) ?>">← Anterior</a><span><?= $page ?> / <?= $totalPages ?></span><a class="<?= $page === $totalPages ? 'disabled' : '' ?>" href="?<?= e(legacy_view_query(['page' => min($totalPages, $page + 1)])) ?>">Siguiente →</a></nav><?php endif; ?>
</section>

<?php if ($selectedProduct): ?>
    <section class="panel" id="detalle-historico">
        <div class="panel-heading"><div><h2><?= e($selectedProduct['name'] ?? 'Producto histórico') ?></h2><p><?= e($selectedProduct['code'] ?? '') ?> · <?= e($selectedProduct['category'] ?? '') ?></p></div><a class="btn btn-secondary" href="?<?= e(legacy_view_query(['product_id' => null])) ?>#detalle-historico">Cerrar detalle</a></div>
        <div class="unified-metrics"><article><span>Costo anterior</span><strong><?= money((float) ($selectedProduct['cost_price'] ?? 0)) ?></strong></article><article><span>Precio anterior</span><strong><?= money((float) ($selectedProduct['price'] ?? 0)) ?></strong></article><article><span>Stock anterior</span><strong><?= number_format((int) ($selectedProduct['stock'] ?? 0)) ?></strong></article><article><span>Estado</span><strong><?= (int) ($selectedProduct['active'] ?? 0) === 1 ? 'Activo' : 'Inactivo' ?></strong></article></div>
        <form class="unified-filters" method="get">
            <input type="hidden" name="q" value="<?= e($query) ?>"><input type="hidden" name="rows" value="<?= $perPage ?>"><input type="hidden" name="page" value="<?= $page ?>"><input type="hidden" name="product_id" value="<?= $selectedProductId ?>">
            <label><span>Movimientos por página</span><select name="movement_rows"><?php foreach ([25, 50, 100] as $rows): ?><option value="<?= $rows ?>" <?= $movementPerPage === $rows ? 'selected' : '' ?>><?= $rows ?></option><?php endforeach; ?></select></label>
            <label><span>Imágenes por página</span><select name="image_rows"><?php foreach ([25, 50, 100] as $rows): ?><option value="<?= $rows ?>" <?= $imagePerPage === $rows ? 'selected' : '' ?>><?= $rows ?></option><?php endforeach; ?></select></label>
            <label><span>Combos por página</span><select name="combo_rows"><?php foreach ([25, 50, 100] as $rows): ?><option value="<?= $rows ?>" <?= $comboPerPage === $rows ? 'selected' : '' ?>><?= $rows ?></option><?php endforeach; ?></select></label>
            <div class="unified-filter-actions"><button class="btn btn-secondary" type="submit">Aplicar paginación</button></div>
        </form>
        <h3>Imágenes anteriores <small>(<?= number_format($imageTotal) ?> registros, página <?= $imagePage ?> de <?= $imagePages ?>)</small></h3><?php if ($selectedImages): ?><div class="product-gallery"><?php foreach ($selectedImages as $image): ?><img src="<?= e(image_src((string) ($image['image_url'] ?? ''))) ?>" alt="<?= e($selectedProduct['name'] ?? 'Producto histórico') ?>" loading="lazy"><?php endforeach; ?></div><?php elseif (!$availableTables['legacy_product_images']): ?><p>La tabla de imágenes no está disponible.</p><?php else: ?><p>Sin imágenes históricas registradas.</p><?php endif; ?>
        <?php if ($imagePages > 1): ?><nav class="pagination" aria-label="Paginación de imágenes"><a href="?<?= e(legacy_view_query(['image_page' => max(1, $imagePage - 1)])) ?>#detalle-historico">← Anterior</a><span><?= $imagePage ?> / <?= $imagePages ?></span><a href="?<?= e(legacy_view_query(['image_page' => min($imagePages, $imagePage + 1)])) ?>#detalle-historico">Siguiente →</a></nav><?php endif; ?>
        <h3>Movimientos anteriores <small>(<?= number_format($movementTotal) ?> registros, página <?= $movementPage ?> de <?= $movementPages ?>)</small></h3><div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha</th><th>Tipo</th><th>Unidades</th><th>Costo total</th><th>Ingreso</th><th>Ganancia</th></tr></thead><tbody><?php foreach ($selectedMovements as $movement): ?><tr><td><?= e($movement['moved_at'] ?? '') ?></td><td><?= e($movement['movement_type'] ?? '') ?></td><td><?= number_format((int) ($movement['units_changed'] ?? 0)) ?></td><td><?= money((float) ($movement['total_cost'] ?? 0)) ?></td><td><?= money((float) ($movement['total_income'] ?? 0)) ?></td><td><?= money((float) ($movement['profit'] ?? 0)) ?></td></tr><?php endforeach; ?><?php if (!$selectedMovements): ?><tr><td colspan="6"><?= $availableTables['legacy_inventory_movements'] ? 'Sin movimientos históricos registrados.' : 'La tabla de movimientos no está disponible.' ?></td></tr><?php endif; ?></tbody></table></div>
        <?php if ($movementPages > 1): ?><nav class="pagination" aria-label="Paginación de movimientos"><a href="?<?= e(legacy_view_query(['movement_page' => max(1, $movementPage - 1)])) ?>#detalle-historico">← Anterior</a><span><?= $movementPage ?> / <?= $movementPages ?></span><a href="?<?= e(legacy_view_query(['movement_page' => min($movementPages, $movementPage + 1)])) ?>#detalle-historico">Siguiente →</a></nav><?php endif; ?>
        <h3>Combos anteriores <small>(<?= number_format($comboTotal) ?> registros, página <?= $comboPage ?> de <?= $comboPages ?>)</small></h3><div class="table-wrap"><table class="data-table"><thead><tr><th>Combo</th><th>Precio</th><th>Cantidad</th><th>Stock</th><th>Estado</th></tr></thead><tbody><?php foreach ($selectedCombos as $combo): ?><tr><td><strong><?= e($combo['name'] ?? '') ?></strong><small><?= e($combo['description'] ?? '') ?></small></td><td><?= money((float) ($combo['price'] ?? 0)) ?></td><td><?= number_format((int) ($combo['quantity'] ?? 0)) ?></td><td><?= $combo['stock'] === null ? 'Automático' : number_format((int) $combo['stock']) ?></td><td><?= (int) ($combo['active'] ?? 0) === 1 ? 'Activo' : 'Inactivo' ?></td></tr><?php endforeach; ?><?php if (!$selectedCombos): ?><tr><td colspan="5"><?= $availableTables['legacy_combos'] && $availableTables['legacy_combo_items'] ? 'Sin combos históricos vinculados.' : 'Las tablas de combos no están disponibles.' ?></td></tr><?php endif; ?></tbody></table></div>
        <?php if ($comboPages > 1): ?><nav class="pagination" aria-label="Paginación de combos"><a href="?<?= e(legacy_view_query(['combo_page' => max(1, $comboPage - 1)])) ?>#detalle-historico">← Anterior</a><span><?= $comboPage ?> / <?= $comboPages ?></span><a href="?<?= e(legacy_view_query(['combo_page' => min($comboPages, $comboPage + 1)])) ?>#detalle-historico">Siguiente →</a></nav><?php endif; ?>
    </section>
<?php elseif ($selectedProductId > 0): ?>
    <div class="alert alert-warning"><span>No se encontró ese producto en el respaldo anterior.</span></div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
