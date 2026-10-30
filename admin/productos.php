<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_admin();

$pageTitle = 'Gestión de productos';
$pageSubtitle = 'Consulta, edita y controla todos los registros';
$activePage = 'products';

$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$status = (string) ($_GET['status'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

$where = ["catalog_scope = 'master'"];
$parameters = [];

if ($query !== '') {
    $where[] = '(name LIKE :query_name OR code LIKE :query_code OR category LIKE :query_category OR ean LIKE :query_ean OR source_name LIKE :query_source)';
    $searchTerm = '%' . $query . '%';
    $parameters['query_name'] = $searchTerm;
    $parameters['query_code'] = $searchTerm;
    $parameters['query_category'] = $searchTerm;
    $parameters['query_ean'] = $searchTerm;
    $parameters['query_source'] = $searchTerm;
}

if ($status === 'active') {
    $where[] = 'active = 1';
} elseif ($status === 'inactive') {
    $where[] = 'active = 0';
} elseif ($status === 'low') {
    $where[] = 'stock <= min_stock';
}

$whereSql = ' WHERE ' . implode(' AND ', $where);
$countStatement = db()->prepare('SELECT COUNT(*) FROM products' . $whereSql);
$countStatement->execute($parameters);
$total = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT id, code, name, category, cost_price, price, stock, min_stock, units_per_pack, pack_price, featured, active, updated_at,
               source_name, ean, supplier_price, suggested_price, margin_pct, sale_enabled, restricted
        FROM products' . $whereSql . '
        ORDER BY updated_at DESC
        LIMIT :limit OFFSET :offset';
$statement = db()->prepare($sql);
foreach ($parameters as $key => $value) {
    $statement->bindValue(':' . $key, $value);
}
$statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$products = $statement->fetchAll();

require __DIR__ . '/../includes/admin_header.php';
?>

<section class="panel products-panel">
    <div class="product-actions-bar">
        <form class="admin-filters" method="get">
            <label class="search-control search-small">
                <span>⌕</span>
                <input type="search" name="q" value="<?= e($query) ?>" placeholder="Nombre, código, EAN u origen...">
            </label>
            <select name="status" aria-label="Filtrar por estado">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>Todos los estados</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Activos</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactivos</option>
                <option value="low" <?= $status === 'low' ? 'selected' : '' ?>>Stock bajo</option>
            </select>
            <button class="btn btn-secondary" type="submit">Filtrar</button>
            <?php if ($query !== '' || $status !== ''): ?><a class="clear-filter" href="<?= url('admin/productos.php') ?>">Limpiar</a><?php endif; ?>
        </form>
        <a class="btn btn-primary" href="<?= url('admin/producto_form.php') ?>">＋ Nuevo producto</a>
    </div>

    <div class="table-summary">
        <p><strong><?= number_format($total) ?></strong> producto<?= $total === 1 ? '' : 's' ?> encontrado<?= $total === 1 ? '' : 's' ?></p>
        <small>Página <?= $page ?> de <?= $totalPages ?></small>
    </div>

    <div class="table-wrap">
        <table class="data-table product-table">
            <thead><tr><th>Producto</th><th>Origen / EAN</th><th>Categoría</th><th>Proveedor / sugerido</th><th>Costo / precio</th><th>Margen</th><th>Stock</th><th>Publicación</th><th>Acciones</th></tr></thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <?php $state = stock_class((int) $product['stock'], (int) $product['min_stock']); ?>
                    <tr>
                        <td><div class="table-product"><span><?= e(category_icon($product['category'])) ?></span><div><strong><?= e($product['name']) ?> <?= (int) $product['featured'] === 1 ? '<em class="offer-mini">OFERTA</em>' : '' ?></strong><small><?= e($product['code']) ?></small></div></div></td>
                        <td><strong><?= e($product['source_name']) ?></strong><small class="table-subline"><?= e($product['ean'] ?: 'Sin EAN') ?></small></td>
                        <td><span class="category-chip"><?= e($product['category']) ?></span></td>
                        <td><small class="money-pair">Proveedor <?= $product['supplier_price'] !== null ? money($product['supplier_price']) : '—' ?></small><strong><?= $product['suggested_price'] !== null ? money($product['suggested_price']) : '—' ?></strong></td>
                        <td><small class="money-pair">Costo <?= money($product['cost_price']) ?></small><strong><?= money($product['price']) ?></strong></td>
                        <td><?= $product['margin_pct'] !== null ? number_format((float) $product['margin_pct'], 1) . ' %' : '—' ?></td>
                        <td><span class="status-pill status-<?= e($state) ?>"><i></i><?= (int) $product['stock'] ?> und.</span></td>
                        <td><?php if ((int) $product['restricted'] === 1): ?><span class="visibility">Restringido</span><?php else: ?><span class="visibility <?= (int) $product['active'] === 1 && (int) $product['sale_enabled'] === 1 ? 'is-active' : '' ?>"><i></i><?= (int) $product['active'] === 1 && (int) $product['sale_enabled'] === 1 ? 'Publicado' : 'No publicado' ?></span><?php endif; ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="table-action edit-action" href="<?= url('admin/producto_form.php?id=' . (int) $product['id']) ?>" title="Editar">✎</a>
                                <button class="table-action delete-action" type="button" title="Eliminar"
                                        data-delete-product="<?= (int) $product['id'] ?>"
                                        data-delete-name="<?= e($product['name']) ?>">⌫</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$products): ?>
                    <tr><td colspan="9"><div class="empty-mini"><strong>No hay resultados.</strong><br>Modifica los filtros o registra un producto nuevo.</div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Paginación">
            <?php
                $queryParams = ['q' => $query, 'status' => $status];
                $previous = http_build_query(array_merge($queryParams, ['page' => max(1, $page - 1)]));
                $next = http_build_query(array_merge($queryParams, ['page' => min($totalPages, $page + 1)]));
            ?>
            <a class="<?= $page === 1 ? 'disabled' : '' ?>" href="?<?= e($previous) ?>">← Anterior</a>
            <span><?= $page ?> / <?= $totalPages ?></span>
            <a class="<?= $page === $totalPages ? 'disabled' : '' ?>" href="?<?= e($next) ?>">Siguiente →</a>
        </nav>
    <?php endif; ?>
</section>

<form id="deleteProductForm" method="post" action="<?= url('admin/producto_eliminar.php') ?>" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="id" id="deleteProductId">
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
