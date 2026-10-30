<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';
require_once __DIR__ . '/../includes/ai_recommendations.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'save');
    $id = max(0, (int) ($_POST['id'] ?? 0));

    if ($action === 'delete') {
        $delete = db()->prepare(
            "DELETE c FROM combos c WHERE c.id = :id
             AND EXISTS (SELECT 1 FROM combo_items ci INNER JOIN products p ON p.id = ci.product_id WHERE ci.combo_id = c.id AND p.catalog_scope = 'master')
             AND NOT EXISTS (SELECT 1 FROM combo_items ci INNER JOIN products p ON p.id = ci.product_id WHERE ci.combo_id = c.id AND p.catalog_scope <> 'master')"
        );
        $delete->execute(['id' => $id]);
        flash($delete->rowCount() ? 'success' : 'warning', $delete->rowCount() ? 'Combo eliminado.' : 'El combo ya no existe.');
        redirect('admin/combos.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $priceRaw = trim((string) ($_POST['price'] ?? ''));
    $stockRaw = trim((string) ($_POST['stock'] ?? ''));
    $comboStock = $stockRaw === '' ? null : filter_var($stockRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999999]]);
    $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
    $featured = isset($_POST['featured']) ? 1 : 0;
    $active = isset($_POST['active']) ? 1 : 0;
    $productQuantities = $_POST['products'] ?? [];
    $productSalePrices = is_array($_POST['product_prices'] ?? null) ? $_POST['product_prices'] : [];
    $items = [];
    $errors = [];

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) $errors[] = 'El nombre debe tener entre 2 y 120 caracteres.';
    if (mb_strlen($description) > 500) $errors[] = 'La descripción no puede superar los 500 caracteres.';
    if (!is_numeric($priceRaw) || (float) $priceRaw <= 0 || (float) $priceRaw > 999999.99) $errors[] = 'Ingresa un precio válido mayor que cero.';
    if ($stockRaw !== '' && $comboStock === false) $errors[] = 'El stock del combo debe ser un número entero entre 0 y 999999, o déjalo vacío para calcularlo automáticamente.';
    if ($imageUrl !== '' && !preg_match('#^assets/uploads/[a-z0-9_-]+/[a-f0-9]{24}\.(jpg|png|webp)$#i', $imageUrl)
        && (!filter_var($imageUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $imageUrl))) $errors[] = 'La URL de imagen no es válida.';

    if (is_array($productQuantities)) {
        foreach ($productQuantities as $productId => $quantityRaw) {
            $productId = (int) $productId;
            $quantity = (int) $quantityRaw;
            if ($productId > 0 && $quantity > 0 && $quantity <= 100) $items[$productId] = $quantity;
        }
    }
    if (!$items) $errors[] = 'Selecciona al menos un producto para el combo.';
    if (count($items) > 50) $errors[] = 'Un combo admite como máximo 50 productos distintos.';
    if ($items && array_sum($items) < 2) $errors[] = 'El combo debe incluir al menos dos unidades en total.';

    $regularPrice = 0.0;
    if ($items) {
        $productIds = array_keys($items);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $selectedStatement = db()->prepare("SELECT id, name, category, description, price, stock, active, sale_enabled, restricted FROM products WHERE catalog_scope = 'master' AND id IN ($placeholders)");
        $selectedStatement->execute($productIds);
        $selectedProducts = $selectedStatement->fetchAll();
        if (count($selectedProducts) !== count($items)) {
            $errors[] = 'Uno de los productos seleccionados ya no está disponible.';
        } else {
            foreach ($selectedProducts as $selectedProduct) {
                $itemId = (int) $selectedProduct['id'];
                $itemPrice = (float) $selectedProduct['price'];
                if ($itemPrice <= 0) {
                    $inputPrice = $productSalePrices[$itemId] ?? null;
                    if (!is_scalar($inputPrice) || !is_numeric($inputPrice) || (float) $inputPrice <= 0 || (float) $inputPrice > 999999.99
                        || round((float) $inputPrice, 2) <= 0) {
                        $errors[] = 'Indica el precio de venta de cada producto sin precio antes de crear el combo.';
                        break;
                    }
                    $itemPrice = round((float) $inputPrice, 2);
                }
                if ((int) $selectedProduct['active'] !== 1 || (int) $selectedProduct['restricted'] === 1 || is_age_restricted_product($selectedProduct)
                    || ($active === 1 && (int) $selectedProduct['stock'] < $items[$itemId])) {
                    $errors[] = 'Selecciona productos permitidos con precio y stock suficientes.';
                    break;
                }
                $regularPrice += $itemPrice * $items[$itemId];
            }
            if (is_numeric($priceRaw) && (float) $priceRaw >= $regularPrice) {
                $errors[] = 'El precio del combo debe ser menor que el precio normal de sus productos (' . money($regularPrice) . ').';
            }
        }
    }

    if (!$errors) {
        try {
            $uploadedImage = upload_image('image_file', 'combos');
            if ($uploadedImage !== null) $imageUrl = $uploadedImage;
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if ($errors) {
        $_SESSION['combo_old'] = $_POST;
        flash('danger', implode(' ', $errors));
        redirect('admin/combos.php' . ($id > 0 ? '?edit=' . $id : ''));
    }

    $connection = db();
    $connection->beginTransaction();
    try {
        $productIds = array_keys($items);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $lockedProducts = $connection->prepare(
            "SELECT id, name, category, description, price, stock, active, sale_enabled, restricted FROM products
             WHERE catalog_scope = 'master' AND id IN ($placeholders) ORDER BY id FOR UPDATE"
        );
        $lockedProducts->execute($productIds);
        $authoritativeProducts = $lockedProducts->fetchAll();
        if (count($authoritativeProducts) !== count($items)) {
            throw new RuntimeException('Uno de los productos no pertenece al catálogo maestro.');
        }
        $regularPrice = 0.0;
        $priceUpdate = $connection->prepare("UPDATE products SET price = ? WHERE id = ? AND catalog_scope = 'master' AND active = 1 AND restricted = 0 AND price <= 0");
        foreach ($authoritativeProducts as $authoritativeProduct) {
            $required = $items[(int) $authoritativeProduct['id']];
            $currentPrice = (float) $authoritativeProduct['price'];
            if ($currentPrice <= 0) {
                $inputPrice = $productSalePrices[(int) $authoritativeProduct['id']] ?? null;
                if (!is_scalar($inputPrice) || !is_numeric($inputPrice) || (float) $inputPrice <= 0 || (float) $inputPrice > 999999.99
                    || round((float) $inputPrice, 2) <= 0) throw new RuntimeException('Indica un precio de venta válido para cada producto del combo.');
                $currentPrice = round((float) $inputPrice, 2);
            }
            if ((int) $authoritativeProduct['active'] !== 1 || (int) $authoritativeProduct['restricted'] === 1 || is_age_restricted_product($authoritativeProduct)
                || ($active === 1 && (int) $authoritativeProduct['stock'] < $required)) {
                throw new RuntimeException('El combo contiene un producto que no se puede vender.');
            }
            if ((float) $authoritativeProduct['price'] <= 0) {
                $priceUpdate->execute([number_format($currentPrice, 2, '.', ''), (int) $authoritativeProduct['id']]);
                if ($priceUpdate->rowCount() !== 1) throw new RuntimeException('El precio del producto cambió. Vuelve a revisar el combo.');
            }
            $regularPrice += $currentPrice * $required;
        }
        if ((float) $priceRaw >= $regularPrice) {
            throw new RuntimeException('El precio del combo debe ser menor que el precio vigente de sus productos.');
        }
        // An active combo publishes only its verified, safe, in-stock components.
        // This avoids an active combo pointing at products hidden from checkout.
        if ($active === 1) {
            $publish = $connection->prepare("UPDATE products SET active = 1, sale_enabled = 1 WHERE id = ? AND catalog_scope = 'master' AND restricted = 0 AND stock >= ? AND price > 0");
            foreach ($authoritativeProducts as $product) {
                $publish->execute([(int) $product['id'], $items[(int) $product['id']]]);
            }
        }

        if ($id > 0) {
            $targetComboQuery = $connection->prepare('SELECT c.id FROM combos c WHERE c.id = ? FOR UPDATE');
            $targetComboQuery->execute([$id]);
            if (!$targetComboQuery->fetchColumn()) {
                throw new RuntimeException('El combo ya no existe.');
            }
            $targetComponentsQuery = $connection->prepare(
                "SELECT ci.product_id, p.catalog_scope
                 FROM combo_items ci LEFT JOIN products p ON p.id = ci.product_id
                 WHERE ci.combo_id = ? ORDER BY ci.product_id FOR UPDATE"
            );
            $targetComponentsQuery->execute([$id]);
            $targetComponents = $targetComponentsQuery->fetchAll();
            if (!$targetComponents || count(array_filter(
                $targetComponents,
                static fn (array $component): bool => ($component['catalog_scope'] ?? '') === 'master'
            )) !== count($targetComponents)) {
                throw new RuntimeException('El combo pertenece al catálogo anterior y no puede modificarse desde este flujo.');
            }

            $update = $connection->prepare(
                "UPDATE combos c
                 SET name=:name, description=:description, price=:price, stock=:stock, image_url=:image_url, featured=:featured, active=:active
                 WHERE c.id=:id
                 AND EXISTS (
                    SELECT 1 FROM combo_items ci INNER JOIN products p ON p.id = ci.product_id
                    WHERE ci.combo_id = c.id AND p.catalog_scope = 'master'
                 )
                 AND NOT EXISTS (
                    SELECT 1 FROM combo_items ci LEFT JOIN products p ON p.id = ci.product_id
                    WHERE ci.combo_id = c.id AND (p.id IS NULL OR p.catalog_scope <> 'master')
                 )"
            );
            $update->execute(['name' => $name, 'description' => $description ?: null, 'price' => number_format((float) $priceRaw, 2, '.', ''), 'stock' => $comboStock, 'image_url' => $imageUrl ?: null, 'featured' => $featured, 'active' => $active, 'id' => $id]);
            $verifiedComponentIds = array_map(
                static fn (array $component): int => (int) $component['product_id'],
                $targetComponents
            );
            $verifiedComponentPlaceholders = implode(',', array_fill(0, count($verifiedComponentIds), '?'));
            $deleteItems = $connection->prepare(
                "DELETE ci FROM combo_items ci
                 INNER JOIN products current_product ON current_product.id = ci.product_id AND current_product.catalog_scope = 'master'
                 WHERE ci.combo_id = ? AND ci.product_id IN ($verifiedComponentPlaceholders)"
            );
            $deleteItems->execute(array_merge([$id], $verifiedComponentIds));
        } else {
            $insert = $connection->prepare('INSERT INTO combos (name, description, price, stock, image_url, featured, active) VALUES (:name,:description,:price,:stock,:image_url,:featured,:active)');
            $insert->execute(['name' => $name, 'description' => $description ?: null, 'price' => number_format((float) $priceRaw, 2, '.', ''), 'stock' => $comboStock, 'image_url' => $imageUrl ?: null, 'featured' => $featured, 'active' => $active]);
            $id = (int) $connection->lastInsertId();
        }

        $itemInsert = $connection->prepare('INSERT INTO combo_items (combo_id, product_id, quantity) VALUES (:combo_id, :product_id, :quantity)');
        foreach ($items as $productId => $quantity) {
            $itemInsert->execute(['combo_id' => $id, 'product_id' => $productId, 'quantity' => $quantity]);
        }
        $connection->commit();
        unset($_SESSION['combo_old']);
        flash('success', 'Combo guardado correctamente.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo guardar el combo. Verifica los productos seleccionados.');
    }
    redirect('admin/combos.php');
}

$editId = max(0, (int) ($_GET['edit'] ?? 0));
$combo = ['id' => 0, 'name' => '', 'description' => '', 'price' => '', 'stock' => null, 'image_url' => '', 'featured' => 0, 'active' => 1];
$selectedItems = [];
if ($editId > 0) {
    $statement = db()->prepare(
        "SELECT c.* FROM combos c WHERE c.id = :id
         AND EXISTS (SELECT 1 FROM combo_items ci INNER JOIN products p ON p.id = ci.product_id WHERE ci.combo_id = c.id AND p.catalog_scope = 'master')
         AND NOT EXISTS (SELECT 1 FROM combo_items ci INNER JOIN products p ON p.id = ci.product_id WHERE ci.combo_id = c.id AND p.catalog_scope <> 'master') LIMIT 1"
    );
    $statement->execute(['id' => $editId]);
    $combo = $statement->fetch() ?: $combo;
    $itemsStatement = db()->prepare('SELECT product_id, quantity FROM combo_items WHERE combo_id = :id');
    $itemsStatement->execute(['id' => $editId]);
    foreach ($itemsStatement->fetchAll() as $item) $selectedItems[(int) $item['product_id']] = (int) $item['quantity'];
}

$old = $_SESSION['combo_old'] ?? [];
unset($_SESSION['combo_old']);
if ($old) {
    $combo = array_merge($combo, $old);
    $selectedItems = array_slice(array_map('intval', is_array($old['products'] ?? null) ? $old['products'] : []), 0, 50, true);
}

$selectedProductIds = array_slice(array_values(array_filter(array_map('intval', array_keys($selectedItems)))), 0, 50);
$products = [];
if ($selectedProductIds) {
    $selectedPlaceholders = implode(',', array_fill(0, count($selectedProductIds), '?'));
    $selectedStatement = db()->prepare(
        "SELECT id, code, name, category, price, suggested_price, stock, image_url FROM products
         WHERE catalog_scope = 'master' AND id IN ($selectedPlaceholders)"
    );
    $selectedStatement->execute($selectedProductIds);
    $products = $selectedStatement->fetchAll();
}
usort($products, static fn (array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name']));

$combos = db()->query(
    "SELECT c.*, COALESCE(SUM(ci.quantity * p.price), 0) AS regular_price,
            COUNT(ci.product_id) AS item_types,
            GROUP_CONCAT(CONCAT(ci.quantity, '× ', p.name) ORDER BY p.name SEPARATOR ' · ') AS item_names,
            MIN(FLOOR(p.stock / ci.quantity)) AS product_capacity,
            LEAST(COALESCE(c.stock, 4294967295), MIN(FLOOR(p.stock / ci.quantity))) AS available_combos
     FROM combos c INNER JOIN combo_items ci ON ci.combo_id = c.id INNER JOIN products p ON p.id = ci.product_id
     GROUP BY c.id HAVING COUNT(ci.product_id) = SUM(p.catalog_scope = 'master')
     ORDER BY c.featured DESC, c.updated_at DESC"
)->fetchAll();

$pageTitle = 'Combos y promociones';
$pageSubtitle = 'Agrupa productos y define precios especiales';
$activePage = 'combos';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="combo-form-layout">
    <article class="panel form-panel combo-form-main">
        <div class="panel-heading"><div><h2><?= $editId > 0 ? 'Editar combo' : 'Nuevo combo' ?></h2><p>Elige tus productos, escribe el precio y guarda.</p></div></div>
        <form method="post" enctype="multipart/form-data" class="stack-form" data-combo-form>
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $combo['id'] ?>">
            <div class="combo-form-step"><span>1</span><div><strong>Elige productos de tu inventario</strong><small>Se mostrarán los productos activos con stock. Si falta un precio de venta, indícalo al agregar el producto.</small></div></div>
            <div class="combo-product-picker">
                <label class="form-group"><span>Buscar producto</span>
                    <input type="search" autocomplete="off" placeholder="Nombre, código o EAN" data-admin-product-search data-product-mode="combo" data-product-search-url="<?= e(url('api/admin_product_search.php')) ?>" data-product-results="#comboProductSearchResults" data-product-picker="#comboProductPicker">
                </label>
                <small id="comboAvailableStatus" role="status">Cargando productos activos con stock…</small>
                <div id="comboProductSearchResults" class="picker-grid" aria-live="polite"></div>
                <p class="combo-inventory-help">¿No aparece un producto? <a href="<?= url('admin/todos_productos.php') ?>">Revisa que esté activo y tenga stock en Todos los productos →</a></p>
                <strong class="combo-selected-heading" id="comboSelectedHeading">Productos agregados al combo (<?= count($selectedItems) ?>)</strong>
                <div class="picker-grid" id="comboProductPicker">
                    <?php foreach ($products as $product): ?>
                        <label class="picker-product <?= (float)$product['price'] <= 0 ? 'needs-price' : '' ?>" data-product-id="<?= (int) $product['id'] ?>" data-price="<?= e($product['price']) ?>" data-stock="<?= (int) $product['stock'] ?>" data-product-name="<?= e($product['name']) ?>">
                            <input type="checkbox" data-combo-check data-product-id="<?= (int) $product['id'] ?>" <?= isset($selectedItems[(int) $product['id']]) ? 'checked' : '' ?>>
                            <span><?= e(category_icon($product['category'])) ?></span><div><strong><?= e($product['name']) ?></strong><small><?= e($product['code']) ?> · <?= (float)$product['price'] > 0 ? money($product['price']) : 'Sin precio de venta' ?> · stock <?= (int) $product['stock'] ?></small></div>
                            <input class="picker-quantity" type="number" name="products[<?= (int) $product['id'] ?>]" value="<?= (int) ($selectedItems[(int) $product['id']] ?? 0) ?>" min="0" max="100" inputmode="numeric" title="Unidades de este producto dentro de un combo" aria-label="Unidades de <?= e($product['name']) ?> dentro de un combo">
                            <?php if ((float)$product['price'] <= 0): ?><span class="combo-item-price"><span>Precio de venta de <?= e($product['name']) ?></span><input class="picker-sale-price" type="number" name="product_prices[<?= (int)$product['id'] ?>]" value="<?= e($old['product_prices'][$product['id']] ?? $product['suggested_price'] ?? '') ?>" min="0.01" max="999999.99" step="0.01" inputmode="decimal" placeholder="S/ 0.00" aria-label="Precio de venta de <?= e($product['name']) ?>"></span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="combo-picker-empty" id="comboPickerEmpty" <?= $selectedItems ? 'hidden' : '' ?>>Todavía no has agregado productos. Pulsa «Agregar» en la lista de arriba.</p>
            </div>
            <div class="combo-form-step"><span>2</span><div><strong>Nombre y precio del combo</strong><small>El precio especial debe ser menor a la suma de los productos.</small></div></div>
            <div class="form-grid two-cols">
                <label class="form-group"><span>Nombre <b>*</b></span><input name="name" value="<?= e($combo['name']) ?>" maxlength="120" required placeholder="Ej. Combo para compartir"></label>
                <label class="form-group"><span>Precio especial <b>*</b></span><div class="input-prefix"><i>S/</i><input id="comboPrice" type="number" name="price" value="<?= e($combo['price']) ?>" min="0.01" step="0.01" required></div></label>
            </div>
            <details class="combo-advanced-options" <?= $combo['stock'] !== null && $combo['stock'] !== '' || $combo['image_url'] !== '' || $combo['description'] !== '' ? 'open' : '' ?>>
                <summary>Opciones adicionales: imagen, descripción y límite de stock</summary>
                <div class="form-grid two-cols">
                    <div class="form-group"><span>Stock del combo (opcional)</span><div class="combo-stock-control"><button type="button" data-combo-stock-step="-1" aria-label="Restar un combo">−</button><input id="comboStock" type="number" name="stock" value="<?= $combo['stock'] === null || $combo['stock'] === '' ? '' : (int) $combo['stock'] ?>" min="0" max="999999" step="1" inputmode="numeric" placeholder="Automático"><button type="button" data-combo-stock-step="1" aria-label="Agregar un combo">＋</button></div><button class="combo-stock-auto" type="button" id="comboStockAuto">Calcular según productos</button><small>Déjalo vacío para usar el stock real de los productos.</small></div>
                    <label class="form-group"><span>Subir imagen (opcional)</span><input type="file" name="image_file" accept="image/jpeg,image/png,image/webp"><small>Si no subes una foto, se mostrarán las imágenes de los productos.</small></label>
                    <label class="form-group"><span>URL de imagen (opcional)</span><input type="text" name="image_url" value="<?= e($combo['image_url']) ?>" placeholder="https://..."></label>
                    <label class="form-group"><span>Descripción (opcional)</span><textarea name="description" rows="3" maxlength="500"><?= e($combo['description']) ?></textarea></label>
                </div>
            </details>
            <div class="form-grid two-cols">
                <label class="switch-row"><div><strong>Combo activo</strong><small>Visible en el catálogo</small></div><span class="switch"><input type="checkbox" name="active" value="1" <?= (int) $combo['active'] === 1 ? 'checked' : '' ?>><i></i></span></label>
                <label class="switch-row"><div><strong>Destacado</strong><small>Aparece antes que otros combos</small></div><span class="switch"><input type="checkbox" name="featured" value="1" <?= (int) $combo['featured'] === 1 ? 'checked' : '' ?>><i></i></span></label>
            </div>
            <div class="form-submit-inline"><button class="btn btn-primary" type="submit"><?= $editId > 0 ? 'Guardar cambios' : 'Crear combo' ?></button><?php if ($editId > 0): ?><a class="btn btn-secondary" href="<?= url('admin/combos.php') ?>">Cancelar</a><?php endif; ?></div>
        </form>
    </article>

    <aside class="panel combo-summary-card">
        <span>RESUMEN EN VIVO</span><h3>Arma una promoción rentable</h3><p>El cálculo cambia mientras seleccionas productos y cantidades.</p>
        <div class="combo-live-stats">
            <div><span>Unidades</span><strong id="comboLiveUnits">0</strong></div>
            <div><span>Máximo por productos</span><strong id="comboLiveProductCapacity">0</strong></div>
            <div><span>Stock configurado</span><strong id="comboLiveConfiguredStock">Automático</strong></div>
            <div><span>Disponibles para vender</span><strong id="comboLiveAvailable">0</strong></div>
            <div><span>Precio normal</span><strong id="comboLiveRegular">S/ 0.00</strong></div>
            <div><span>Ahorro</span><strong id="comboLiveSaving">S/ 0.00</strong></div>
        </div>
        <p class="combo-live-message" id="comboLiveMessage">Selecciona al menos dos productos o dos unidades.</p>
        <ul><li>Precio menor al valor normal</li><li>Disponibilidad calculada por stock</li><li>Imagen propia o mosaico automático</li></ul>
    </aside>
</section>

<section class="panel">
    <div class="panel-heading"><div><h2>Combos registrados</h2><p><?= count($combos) ?> promociones configuradas.</p></div></div>
    <div class="admin-combo-grid">
        <?php foreach ($combos as $row): ?>
            <?php $saving = max(0, (float) $row['regular_price'] - (float) $row['price']); ?>
            <article class="admin-combo-card">
                <div class="admin-combo-head">
                    <?php if ($row['image_url']): ?><img src="<?= e(image_src($row['image_url'])) ?>" alt="<?= e($row['name']) ?>"><?php else: ?><span>✦</span><?php endif; ?>
                    <div><small><?= (int) $row['featured'] === 1 ? 'DESTACADO' : 'COMBO' ?></small><h3><?= e($row['name']) ?></h3></div>
                </div>
                <p><?= e($row['description'] ?: 'Combo especial de Devioz Store.') ?></p>
                <small class="combo-items-line"><?= e($row['item_names'] ?: 'Sin productos') ?></small>
                <div class="combo-admin-stats"><span><small>Normal</small><del><?= money($row['regular_price']) ?></del></span><span><small>Combo</small><strong><?= money($row['price']) ?></strong></span><span><small>Ahorro</small><strong><?= money($saving) ?></strong></span><span><small>Stock combo</small><strong><?= $row['stock'] === null ? 'Auto' : (int) $row['stock'] ?></strong></span><span><small>Disponibles</small><strong><?= max(0, (int) $row['available_combos']) ?></strong></span></div>
                <div class="combo-actions"><span class="visibility <?= (int) $row['active'] === 1 ? 'is-active' : '' ?>"><i></i><?= (int) $row['active'] === 1 ? 'Activo' : 'Inactivo' ?></span><div class="row-actions"><a class="table-action edit-action" href="?edit=<?= (int) $row['id'] ?>">✎</a><form method="post" onsubmit="return confirm('¿Eliminar este combo?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="table-action delete-action" type="submit">⌫</button></form></div></div>
            </article>
        <?php endforeach; ?>
        <?php if (!$combos): ?><div class="empty-mini">Aún no has creado combos.</div><?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
