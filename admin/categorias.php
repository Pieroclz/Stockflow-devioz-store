<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_recommendations.php';
require_admin();

function category_products_url(int $categoryId, string $query = '', int $page = 1): string
{
    $parameters = ['category' => $categoryId];
    if ($query !== '') $parameters['q'] = $query;
    if ($page > 1) $parameters['page'] = $page;
    return 'admin/categorias.php?' . http_build_query($parameters);
}

// Mantiene disponibles las categorías reales del catálogo importado.
try {
    db()->exec(
        "INSERT IGNORE INTO categories(name,icon,active)
         SELECT DISTINCT TRIM(p.categoria),UPPER(LEFT(TRIM(p.categoria),1)),1
         FROM productos p
         WHERE p.activo=1 AND TRIM(COALESCE(p.categoria,''))<>'' AND " . safe_external_catalog_condition('p')
    );
} catch (PDOException) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'save');
    $id = max(0, (int) ($_POST['id'] ?? 0));

    if ($action === 'move_product') {
        $sourceCategoryId = max(0, (int) ($_POST['source_category_id'] ?? 0));
        $query = mb_substr(trim((string) ($_POST['q'] ?? '')), 0, 100);
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $back = category_products_url($sourceCategoryId, $query, $page) . '#productos-categoria';
        $target = db()->prepare('SELECT name FROM categories WHERE id = :id AND active = 1 LIMIT 1');
        $target->execute(['id' => max(0, (int) ($_POST['target_category_id'] ?? 0))]);
        $targetName = $target->fetchColumn();
        $source = db()->prepare('SELECT name FROM categories WHERE id = :id LIMIT 1');
        $source->execute(['id' => $sourceCategoryId]);
        $sourceName = $source->fetchColumn();
        $productId = max(0, (int) ($_POST['product_id'] ?? 0));
        $type = (string) ($_POST['product_type'] ?? '');
        if (!$targetName || !$sourceName || $productId === 0 || !in_array($type, ['own', 'imported'], true) || is_age_restricted_product(['category' => $targetName])) {
            flash('warning', 'Selecciona un producto y una categoría activa.');
            redirect($back);
        }
        if ($targetName === $sourceName) {
            flash('warning', 'Selecciona otra categoría para este producto.');
            redirect($back);
        }
        try {
            $connection = db();
            $connection->beginTransaction();
            if ($type === 'own') {
                $find = $connection->prepare('SELECT id, name, category, description, restricted, source_product_id FROM products WHERE id = :id AND category = :category LIMIT 1 FOR UPDATE');
                $find->execute(['id' => $productId, 'category' => $sourceName]);
                $product = $find->fetch();
                if (!$product || (int) $product['restricted'] !== 0 || is_age_restricted_product($product)) {
                    throw new RuntimeException('El producto ya no está disponible para cambiar de categoría.');
                }
                $update = $connection->prepare('UPDATE products SET category = :category WHERE id = :id');
                $update->execute(['category' => $targetName, 'id' => $productId]);
                if ((int) $product['source_product_id'] > 0) {
                    $updateSource = $connection->prepare('UPDATE productos SET categoria = :category WHERE id_producto = :id AND ' . safe_external_catalog_condition('productos'));
                    $updateSource->execute(['category' => $targetName, 'id' => (int) $product['source_product_id']]);
                }
            } else {
                $find = $connection->prepare('SELECT p.producto AS name, p.categoria AS category FROM productos p WHERE p.id_producto = :id AND p.categoria = :category AND p.activo = 1 AND ' . safe_external_catalog_condition('p') . ' AND ' . public_text_visibility_sql(["COALESCE(p.producto, '')", "COALESCE(p.categoria, '')"]) . ' LIMIT 1 FOR UPDATE');
                $find->execute(['id' => $productId, 'category' => $sourceName]);
                $product = $find->fetch();
                if (!$product || is_age_restricted_product($product)) {
                    throw new RuntimeException('El producto importado ya no está disponible.');
                }
                $update = $connection->prepare('UPDATE productos SET categoria = :category WHERE id_producto = :id');
                $update->execute(['category' => $targetName, 'id' => $productId]);
                $linked = $connection->prepare('UPDATE products SET category = :category WHERE source_product_id = :id AND restricted = 0 AND ' . public_product_text_visibility_sql('products'));
                $linked->execute(['category' => $targetName, 'id' => $productId]);
            }
            $connection->commit();
            flash('success', 'Categoría del producto actualizada.');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo cambiar la categoría del producto.');
        }
        redirect($back);
    }

    if ($action === 'delete') {
        $statement = db()->prepare('SELECT name FROM categories WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $categoryName = $statement->fetchColumn();
        if (!$categoryName) {
            flash('warning', 'La categoría ya no existe.');
        } else {
            $used = db()->prepare(
                'SELECT (SELECT COUNT(*) FROM products WHERE category=:local_category)
                      + (SELECT COUNT(*) FROM productos p WHERE p.categoria=:external_category AND p.activo=1 AND '.safe_external_catalog_condition('p').')'
            );
            $used->execute(['local_category' => $categoryName, 'external_category' => $categoryName]);
            if ((int) $used->fetchColumn() > 0) {
                flash('warning', 'No se puede eliminar una categoría que todavía tiene productos.');
            } else {
                $delete = db()->prepare('DELETE FROM categories WHERE id = :id');
                $delete->execute(['id' => $id]);
                flash('success', 'Categoría eliminada.');
            }
        }
        redirect('admin/categorias.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $icon = mb_strtoupper(trim((string) ($_POST['icon'] ?? '')));
    $active = isset($_POST['active']) ? 1 : 0;
    $errors = [];

    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        $errors[] = 'El nombre debe tener entre 2 y 80 caracteres.';
    }
    if ($icon === '' || mb_strlen($icon) > 12) {
        $errors[] = 'El icono debe tener entre 1 y 12 caracteres.';
    }

    if (!$errors) {
        $unique = db()->prepare('SELECT id FROM categories WHERE name = :name' . ($id > 0 ? ' AND id <> :id' : '') . ' LIMIT 1');
        $parameters = ['name' => $name];
        if ($id > 0) {
            $parameters['id'] = $id;
        }
        $unique->execute($parameters);
        if ($unique->fetch()) {
            $errors[] = 'Ya existe una categoría con ese nombre.';
        }
    }

    if ($errors) {
        flash('danger', implode(' ', $errors));
        redirect('admin/categorias.php' . ($id > 0 ? '?edit=' . $id : ''));
    }

    if ($id > 0) {
        $old = db()->prepare('SELECT name FROM categories WHERE id = :id LIMIT 1');
        $old->execute(['id' => $id]);
        $oldName = $old->fetchColumn();
        if (!$oldName) {
            flash('warning', 'La categoría que intentas editar ya no existe.');
            redirect('admin/categorias.php');
        }

        $connection = db();
        $connection->beginTransaction();
        try {
            $update = $connection->prepare('UPDATE categories SET name = :name, icon = :icon, active = :active WHERE id = :id');
            $update->execute(['name' => $name, 'icon' => $icon, 'active' => $active, 'id' => $id]);
            $productsUpdate = $connection->prepare('UPDATE products SET category = :name WHERE category = :old_name');
            $productsUpdate->execute(['name' => $name, 'old_name' => $oldName]);
            $externalUpdate = $connection->prepare('UPDATE productos SET categoria = :name WHERE categoria = :old_name');
            $externalUpdate->execute(['name' => $name, 'old_name' => $oldName]);
            $connection->commit();
            flash('success', 'Categoría actualizada correctamente.');
        } catch (Throwable $exception) {
            $connection->rollBack();
            flash('danger', 'No se pudo actualizar la categoría. Inténtalo nuevamente.');
            redirect('admin/categorias.php?edit=' . $id);
        }
    } else {
        $insert = db()->prepare('INSERT INTO categories (name, icon, active) VALUES (:name, :icon, :active)');
        $insert->execute(['name' => $name, 'icon' => $icon, 'active' => $active]);
        flash('success', 'Categoría registrada correctamente.');
    }

    redirect('admin/categorias.php');
}

$editId = max(0, (int) ($_GET['edit'] ?? 0));
$editing = ['id' => 0, 'name' => '', 'icon' => '', 'active' => 1];
if ($editId > 0) {
    $statement = db()->prepare('SELECT * FROM categories WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $editId]);
    $editing = $statement->fetch() ?: $editing;
}

$categories = db()->query(
    'SELECT c.*, COUNT(DISTINCT p.id) AS product_count, COALESCE(SUM(p.stock), 0) AS units,
            COALESCE(MAX(ext.external_count),0) AS external_count
     FROM categories c
     LEFT JOIN products p ON p.category = c.name
     LEFT JOIN (
        SELECT ep.categoria,COUNT(*) AS external_count
        FROM productos ep
        WHERE ep.activo=1 AND '.safe_external_catalog_condition('ep').'
        GROUP BY ep.categoria
     ) ext ON ext.categoria=c.name
     GROUP BY c.id
     ORDER BY c.name'
)->fetchAll();

$selectedId = max(0, (int) ($_GET['category'] ?? 0));
$selectedCategory = null;
foreach ($categories as $category) {
    if ((int) $category['id'] === $selectedId) { $selectedCategory = $category; break; }
}
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int) ($_GET['page'] ?? 1));
$productRows = [];
$productCount = 0;
$pageCount = 1;
if ($selectedCategory) {
    // Las fichas del inventario tienen prioridad sobre su origen importado.
    $sourceSql = "SELECT 'own' AS product_type, p.id AS product_id, p.name AS product_name, p.code AS product_code, p.stock AS units
        FROM products p WHERE p.category = :own_category AND p.restricted = 0 AND " . public_product_text_visibility_sql('p') . "
        UNION ALL
        SELECT 'imported' AS product_type, ep.id_producto AS product_id, ep.producto AS product_name, COALESCE(ep.ean, '') AS product_code, 0 AS units
        FROM productos ep WHERE ep.categoria = :external_category AND ep.activo = 1 AND " . safe_external_catalog_condition('ep') . " AND " . public_text_visibility_sql(["COALESCE(ep.producto, '')", "COALESCE(ep.categoria, '')"]) . "
        AND NOT EXISTS (SELECT 1 FROM products p2 WHERE p2.source_product_id = ep.id_producto)";
    $where = $search !== '' ? ' WHERE rows_all.product_name LIKE :search OR rows_all.product_code LIKE :search' : '';
    $parameters = ['own_category' => $selectedCategory['name'], 'external_category' => $selectedCategory['name']];
    if ($search !== '') $parameters['search'] = '%' . $search . '%';
    $count = db()->prepare('SELECT COUNT(*) FROM (' . $sourceSql . ') rows_all' . $where);
    $count->execute($parameters);
    $productCount = (int) $count->fetchColumn();
    $pageCount = max(1, (int) ceil($productCount / 20));
    $page = min($page, $pageCount);
    $rows = db()->prepare('SELECT * FROM (' . $sourceSql . ') rows_all' . $where . ' ORDER BY product_name, product_type, product_id LIMIT 20 OFFSET ' . (($page - 1) * 20));
    $rows->execute($parameters);
    $productRows = $rows->fetchAll();
}

$pageTitle = 'Categorías';
$pageSubtitle = 'Organiza el catálogo sin modificar código';
$activePage = 'categories';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="management-layout">
    <article class="panel form-panel compact-form-panel">
        <div class="panel-heading"><div><h2><?= $editId > 0 ? 'Editar categoría' : 'Nueva categoría' ?></h2><p>Nombre, icono y visibilidad.</p></div></div>
        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
            <label class="form-group"><span>Nombre <b>*</b></span><input name="name" value="<?= e($editing['name']) ?>" maxlength="80" placeholder="Ej. Bebidas" required></label>
            <label class="form-group"><span>Icono o inicial <b>*</b></span><input name="icon" value="<?= e($editing['icon']) ?>" maxlength="12" placeholder="B" required></label>
            <label class="switch-row"><div><strong>Categoría activa</strong><small>Disponible en formularios y catálogo</small></div><span class="switch"><input type="checkbox" name="active" value="1" <?= (int) $editing['active'] === 1 ? 'checked' : '' ?>><i></i></span></label>
            <button class="btn btn-primary btn-block" type="submit"><?= $editId > 0 ? 'Guardar cambios' : 'Agregar categoría' ?></button>
            <?php if ($editId > 0): ?><a class="btn btn-secondary btn-block" href="<?= url('admin/categorias.php') ?>">Cancelar</a><?php endif; ?>
        </form>
    </article>

    <article class="panel">
        <div class="panel-heading"><div><h2>Categorías registradas</h2><p><?= count($categories) ?> grupos para organizar productos.</p></div></div>
        <div class="category-admin-grid">
            <?php foreach ($categories as $category): ?>
                <div class="category-admin-card">
                    <span class="category-admin-icon"><?= e($category['icon']) ?></span>
                    <div class="category-admin-main"><strong><?= e($category['name']) ?></strong><small><?= (int) $category['product_count'] ?> propios · <?= (int) $category['external_count'] ?> importados · <?= (int) $category['units'] ?> unidades en stock</small><a class="category-products-link" href="<?= e(url(category_products_url((int) $category['id']))) ?>#productos-categoria">Ver productos y categorías →</a></div>
                    <span class="visibility <?= (int) $category['active'] === 1 ? 'is-active' : '' ?>"><i></i><?= (int) $category['active'] === 1 ? 'Activa' : 'Inactiva' ?></span>
                    <div class="row-actions">
                        <a class="table-action edit-action" href="?edit=<?= (int) $category['id'] ?>" title="Editar">✎</a>
                        <form method="post" onsubmit="return confirm('¿Eliminar esta categoría?');">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                            <button class="table-action delete-action" type="submit" title="Eliminar">⌫</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<?php if ($selectedCategory): ?>
<section class="panel category-products-panel" id="productos-categoria">
    <div class="panel-heading"><div><h2>Productos de <?= e($selectedCategory['name']) ?></h2><p><?= number_format($productCount) ?> productos. Cambia la categoría de cada uno desde esta lista.</p></div><a class="btn btn-secondary" href="<?= url('admin/categorias.php') ?>">Cerrar lista</a></div>
    <form method="get" class="category-products-search"><input type="hidden" name="category" value="<?= $selectedId ?>"><label for="category-product-query">Buscar producto o código</label><input id="category-product-query" type="search" name="q" value="<?= e($search) ?>" maxlength="100" placeholder="Nombre, código o EAN"><button class="btn btn-secondary" type="submit">Buscar</button></form>
    <div class="category-products-list">
        <?php foreach ($productRows as $product): ?>
        <form method="post" class="category-product-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="move_product"><input type="hidden" name="source_category_id" value="<?= $selectedId ?>"><input type="hidden" name="product_type" value="<?= e($product['product_type']) ?>"><input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>"><input type="hidden" name="q" value="<?= e($search) ?>"><input type="hidden" name="page" value="<?= $page ?>">
            <div class="category-product-name"><strong><?= e($product['product_name']) ?></strong><small><?= $product['product_type'] === 'own' ? 'Inventario · ' . (int) $product['units'] . ' unidades' : 'Importado' ?><?= $product['product_code'] !== '' ? ' · ' . e($product['product_code']) : '' ?></small></div>
            <label><span>Categoría</span><select name="target_category_id" aria-label="Nueva categoría para <?= e($product['product_name']) ?>" required><option value="" selected>Seleccionar destino</option><?php foreach ($categories as $destination): if ((int) $destination['active'] !== 1 || (int) $destination['id'] === $selectedId || is_age_restricted_product(['category' => $destination['name']])) continue; ?><option value="<?= (int) $destination['id'] ?>"><?= e($destination['name']) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn-primary" type="submit">Guardar</button>
        </form>
        <?php endforeach; ?>
        <?php if (!$productRows): ?><p class="empty-mini">No hay productos para esta búsqueda.</p><?php endif; ?>
    </div>
    <?php if ($pageCount > 1): ?><nav class="pagination" aria-label="Páginas de productos"><a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(url(category_products_url($selectedId, $search, max(1, $page - 1)))) ?>#productos-categoria">← Anterior</a><span><?= $page ?> / <?= $pageCount ?></span><a class="<?= $page >= $pageCount ? 'disabled' : '' ?>" href="<?= e(url(category_products_url($selectedId, $search, min($pageCount, $page + 1)))) ?>#productos-categoria">Siguiente →</a></nav><?php endif; ?>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
