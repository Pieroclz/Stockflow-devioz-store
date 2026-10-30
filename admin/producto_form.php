<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';
require_once __DIR__ . '/../includes/nutrition.php';
require_admin();

$id = max(0, (int) ($_GET['id'] ?? 0));
$editing = $id > 0;
$product = [
    'id' => 0,
    'code' => '',
    'name' => '',
    'category' => '',
    'description' => '',
    'cost_price' => '',
    'price' => '',
    'stock' => 0,
    'min_stock' => 5,
    'units_per_pack' => 1,
    'pack_price' => '',
    'image_url' => '',
    'featured' => 0,
    'entrega_inmediata' => 1,
    'active' => 1,
    'source_name' => 'Devioz',
    'ean' => '',
    'supplier_price' => '',
    'suggested_price' => '',
    'margin_pct' => '',
    'sale_enabled' => 1,
    'restricted' => 0,
];
$productImages = [];
$nutrition = ['applicability' => '', 'energy_kcal_100g' => '', 'serving_size' => '', 'serving_unit' => 'g', 'energy_kcal_serving' => '', 'source_type' => 'manual', 'source_ref' => '', 'confidence' => 'reference'];
$galleryDatabaseError = false;

if ($editing) {
    $statement = db()->prepare("SELECT * FROM products WHERE id = :id AND catalog_scope = 'master' LIMIT 1");
    $statement->execute(['id' => $id]);
    $databaseProduct = $statement->fetch();

    if (!$databaseProduct) {
        flash('danger', 'El producto solicitado no existe.');
        redirect('admin/productos.php');
    }

    $product = array_merge($product, $databaseProduct);

    try {
        $galleryStatement = db()->prepare('SELECT id, image_url, sort_order FROM product_images WHERE product_id = :product_id ORDER BY sort_order, id');
        $galleryStatement->execute(['product_id' => $id]);
        $productImages = $galleryStatement->fetchAll();
    } catch (PDOException) {
        $galleryDatabaseError = true;
    }
    try {
        $nutritionStatement = db()->prepare('SELECT * FROM product_nutrition WHERE product_id = :product_id LIMIT 1');
        $nutritionStatement->execute(['product_id' => $id]);
        $nutritionRow = $nutritionStatement->fetch();
        if ($nutritionRow) $nutrition = array_merge($nutrition, $nutritionRow);
    } catch (PDOException) {
        $nutrition['applicability'] = nutrition_default_applicability((string) $product['name'], (string) $product['category']);
    }
}

$errors = $_SESSION['errors'] ?? [];
$oldData = $_SESSION['old'] ?? [];
unset($_SESSION['errors'], $_SESSION['old']);
if ($galleryDatabaseError) $errors[] = 'Importa database/stockflow.sql para activar la galería de imágenes.';

if ($oldData) {
    $product = array_merge($product, $oldData);
    $nutrition = array_merge($nutrition, [
        'applicability' => $oldData['nutrition_applicability'] ?? $nutrition['applicability'],
        'energy_kcal_100g' => $oldData['nutrition_energy_kcal_100g'] ?? $nutrition['energy_kcal_100g'],
        'serving_size' => $oldData['nutrition_serving_size'] ?? $nutrition['serving_size'],
        'serving_unit' => $oldData['nutrition_serving_unit'] ?? $nutrition['serving_unit'],
        'energy_kcal_serving' => $oldData['nutrition_energy_kcal_serving'] ?? $nutrition['energy_kcal_serving'],
        'source_type' => $oldData['nutrition_source_type'] ?? $nutrition['source_type'],
        'source_ref' => $oldData['nutrition_source_ref'] ?? $nutrition['source_ref'],
        'confidence' => $oldData['nutrition_confidence'] ?? $nutrition['confidence'],
    ]);
}
if ($nutrition['applicability'] === '') $nutrition['applicability'] = nutrition_default_applicability((string) $product['name'], (string) $product['category']);
$galleryUrlsValue = (string) ($oldData['gallery_urls'] ?? '');
$primaryGalleryValue = max(0, (int) ($oldData['primary_gallery_image'] ?? 0));

$pageTitle = $editing ? 'Editar producto' : 'Nuevo producto';
$pageSubtitle = $editing ? 'Actualiza la información del registro' : 'Completa los datos para agregarlo al inventario';
$activePage = $editing ? 'all-products' : 'new';
$categories = db()->query('SELECT name FROM categories WHERE active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

require __DIR__ . '/../includes/admin_header.php';
?>

<div class="form-page-head">
    <a class="back-link" href="<?= url('admin/todos_productos.php') ?>">← Volver a productos</a>
    <span class="form-mode"><?= $editing ? 'Editando ' . e($product['code']) : 'Nuevo registro' ?></span>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger validation-summary">
        <div><strong>Revisa los siguientes datos:</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
    </div>
<?php endif; ?>

<form class="product-form" method="post" action="<?= url('admin/producto_guardar.php') ?>" enctype="multipart/form-data" data-validate-product novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">

    <div class="form-main-column">
        <section class="panel form-panel">
            <div class="form-section-title"><span>01</span><div><h2>Información principal</h2><p>Datos que identifican al producto.</p></div></div>
            <div class="form-grid two-cols">
                <label class="form-group">
                    <span>Código del producto <b>*</b></span>
                    <input type="text" name="code" value="<?= e($product['code']) ?>" placeholder="Ej. TEC-001" maxlength="20" pattern="[A-Za-z0-9-]{3,20}" required>
                    <small>De 3 a 20 caracteres: letras, números y guiones.</small>
                </label>
                <label class="form-group">
                    <span>Nombre <b>*</b></span>
                    <input type="text" name="name" value="<?= e($product['name']) ?>" placeholder="Ej. Papas fritas clásicas 40 g" maxlength="255" required>
                </label>
                <label class="form-group">
                    <span>Categoría <b>*</b></span>
                    <input type="text" name="category" value="<?= e($product['category']) ?>" list="categoryOptions" placeholder="Selecciona o escribe una categoría" maxlength="80" required>
                    <datalist id="categoryOptions"><?php foreach ($categories as $category): ?><option value="<?= e($category) ?>"><?php endforeach; ?></datalist>
                </label>
            </div>
            <label class="form-group">
                <span>Descripción</span>
                <textarea name="description" rows="5" maxlength="1000" placeholder="Describe las características principales del producto..."><?= e($product['description']) ?></textarea>
                <small class="character-count"><span data-char-count>0</span>/1000 caracteres</small>
            </label>
            <div class="form-grid two-cols">
                <label class="form-group">
                    <span>Origen <b>*</b></span>
                    <input type="text" name="source_name" value="<?= e($product['source_name']) ?>" maxlength="100" required placeholder="Ej. Devioz">
                </label>
                <label class="form-group">
                    <span>EAN</span>
                    <input type="text" name="ean" value="<?= e($product['ean']) ?>" maxlength="100" placeholder="Código de barras del proveedor">
                </label>
            </div>
        </section>

        <section class="panel form-panel">
            <div class="form-section-title"><span>02</span><div><h2>Galería del producto</h2><p>Selecciona hasta 8 imágenes juntas. Se guardarán aunque XAMPP no permita escribir en la carpeta.</p></div></div>
            <label class="gallery-upload-drop">
                <input type="file" name="gallery_files[]" accept="image/jpeg,image/png,image/webp" multiple data-gallery-input>
                <span class="gallery-upload-icon">▧</span>
                <span class="gallery-upload-copy"><strong>Elegir imágenes del producto</strong><small>JPG, PNG o WEBP · máximo 8 imágenes · 5 MB cada una</small></span>
                <b data-gallery-count>0 / 8</b>
            </label>
            <label class="gallery-replace-option">
                <input type="checkbox" name="replace_gallery" value="1" <?= (!$oldData || (int) ($oldData['replace_gallery'] ?? 0) === 1) ? 'checked' : '' ?>>
                <span><strong>Reemplazar las imágenes actuales</strong><small>Las nuevas imágenes formarán la galería completa y la primera será la portada.</small></span>
            </label>
            <div class="gallery-admin-fields gallery-admin-fields-secondary">
                <details class="gallery-url-details">
                    <summary>Usar enlaces externos (opcional)</summary>
                    <label class="form-group">
                        <span>Imagen principal por URL</span>
                        <input type="text" name="image_url" value="<?= e($product['image_url']) ?>" placeholder="https://ejemplo.com/imagen.jpg">
                    </label>
                    <label class="form-group">
                        <span>Otras imágenes por URL</span>
                        <textarea name="gallery_urls" rows="3" placeholder="Una URL por línea"><?= e($galleryUrlsValue) ?></textarea>
                    </label>
                </details>
            </div>
            <div class="gallery-upload-preview" data-gallery-preview hidden></div>
            <?php if ($productImages): ?>
                <div class="existing-gallery-head"><strong>Imágenes actuales</strong><small>Marca las que deseas quitar al guardar.</small></div>
                <label class="form-group gallery-primary-select">
                    <span>Imagen principal de la galería</span>
                    <select name="primary_gallery_image">
                        <option value="">Mantener la imagen principal indicada arriba</option>
                        <?php foreach ($productImages as $index => $galleryImage): ?>
                            <option value="<?= (int) $galleryImage['id'] ?>" <?= $primaryGalleryValue === (int) $galleryImage['id'] ? 'selected' : '' ?>>Vista <?= $index + 1 ?><?= (string) $galleryImage['image_url'] === (string) $product['image_url'] ? ' · actual' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="existing-gallery-grid">
                    <?php foreach ($productImages as $index => $galleryImage): ?>
                        <label class="existing-gallery-item">
                            <img src="<?= e(image_src($galleryImage['image_url'])) ?>" alt="Vista <?= $index + 1 ?> de <?= e($product['name']) ?>" loading="lazy" referrerpolicy="no-referrer" data-product-image>
                            <span><?= $index === 0 ? 'Principal' : 'Vista ' . ($index + 1) ?></span>
                            <input type="checkbox" name="remove_gallery_images[]" value="<?= (int) $galleryImage['id'] ?>">
                            <i>Quitar</i>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="gallery-empty-admin"><span>▧</span><div><strong>Aún no hay imágenes adicionales</strong><small>Las nuevas vistas aparecerán aquí después de guardar.</small></div></div>
            <?php endif; ?>
        </section>

        <section class="panel form-panel">
            <div class="form-section-title"><span>04</span><div><h2>Calorías y nutrición</h2><p>Solo aplica a comida para humanos; limpieza, mascotas e higiene quedan fuera del cálculo.</p></div></div>
            <div class="form-grid three-cols">
                <label class="form-group"><span>Tipo</span><select name="nutrition_applicability"><option value="food" <?= $nutrition['applicability']==='food'?'selected':'' ?>>Comida</option><option value="non_food" <?= $nutrition['applicability']==='non_food'?'selected':'' ?>>No aplica</option><option value="review" <?= $nutrition['applicability']==='review'?'selected':'' ?>>Revisar</option></select></label>
                <label class="form-group"><span>Kcal por 100 g/ml</span><input type="number" name="nutrition_energy_kcal_100g" min="0" step="0.01" value="<?= e($nutrition['energy_kcal_100g']) ?>"></label>
                <label class="form-group"><span>Kcal por porción/envase</span><input type="number" name="nutrition_energy_kcal_serving" min="0" step="0.01" value="<?= e($nutrition['energy_kcal_serving']) ?>"></label>
                <label class="form-group"><span>Tamaño del envase</span><input type="number" name="nutrition_serving_size" min="0" step="0.01" value="<?= e($nutrition['serving_size']) ?>"></label>
                <label class="form-group"><span>Unidad</span><select name="nutrition_serving_unit"><option value="g" <?= $nutrition['serving_unit']==='g'?'selected':'' ?>>g</option><option value="ml" <?= $nutrition['serving_unit']==='ml'?'selected':'' ?>>ml</option><option value="unit" <?= $nutrition['serving_unit']==='unit'?'selected':'' ?>>unidad</option></select></label>
                <label class="form-group"><span>Fuente</span><select name="nutrition_source_type"><option value="manual" <?= $nutrition['source_type']==='manual'?'selected':'' ?>>Manual</option><option value="label" <?= $nutrition['source_type']==='label'?'selected':'' ?>>Etiqueta real</option><option value="open_food_facts" <?= $nutrition['source_type']==='open_food_facts'?'selected':'' ?>>Open Food Facts</option><option value="ins" <?= $nutrition['source_type']==='ins'?'selected':'' ?>>Tabla INS/CENAN</option></select></label>
                <label class="form-group"><span>Referencia</span><input type="text" name="nutrition_source_ref" maxlength="255" value="<?= e($nutrition['source_ref']) ?>" placeholder="EAN, etiqueta, tabla o nota"></label>
                <label class="form-group"><span>Confianza</span><select name="nutrition_confidence"><option value="reference" <?= $nutrition['confidence']==='reference'?'selected':'' ?>>Referencia</option><option value="verified" <?= $nutrition['confidence']==='verified'?'selected':'' ?>>Verificado por etiqueta</option></select></label>
            </div>
            <button class="btn btn-secondary" type="button" data-nutrition-lookup>Buscar por EAN</button>
            <small data-nutrition-message>Si falta un dato confiable, el cliente verá "Sin información" en vez de una cifra inventada.</small>
        </section>

        <section class="panel form-panel">
            <div class="form-section-title"><span>05</span><div><h2>Precio e inventario</h2><p>Define el valor y las cantidades disponibles.</p></div></div>
            <div class="form-grid three-cols">
                <label class="form-group">
                    <span>Precio del proveedor</span>
                    <div class="input-prefix"><i>S/</i><input type="number" name="supplier_price" value="<?= e($product['supplier_price']) ?>" min="0" max="999999.99" step="0.01" placeholder="0.00"></div>
                </label>
                <label class="form-group">
                    <span>Costo por unidad <b>*</b></span>
                    <div class="input-prefix"><i>S/</i><input type="number" name="cost_price" value="<?= e($product['cost_price']) ?>" min="0" max="999999.99" step="0.01" placeholder="0.00" required></div>
                    <small>Se usa para calcular la ganancia.</small>
                </label>
                <label class="form-group">
                    <span>Precio unitario <b>*</b></span>
                    <div class="input-prefix"><i>S/</i><input type="number" name="price" value="<?= e($product['price']) ?>" min="0" max="999999.99" step="0.01" placeholder="0.00" required></div>
                </label>
                <label class="form-group">
                    <span>Precio sugerido</span>
                    <div class="input-prefix"><i>S/</i><input type="number" name="suggested_price" value="<?= e($product['suggested_price']) ?>" min="0" max="999999.99" step="0.01" placeholder="Se calcula si queda vacío"></div>
                </label>
                <label class="form-group">
                    <span>Margen</span>
                    <div class="input-prefix"><i>%</i><input type="number" name="margin_pct" value="<?= e($product['margin_pct']) ?>" min="0" max="500" step="0.01" placeholder="25.00"></div>
                </label>
                <label class="form-group">
                    <span>Stock actual <b>*</b></span>
                    <input type="number" name="stock" value="<?= e($product['stock']) ?>" min="0" max="1000000" step="1" required>
                </label>
                <label class="form-group">
                    <span>Stock mínimo <b>*</b></span>
                    <input type="number" name="min_stock" value="<?= e($product['min_stock']) ?>" min="0" max="1000000" step="1" required>
                    <small>Se mostrará una alerta al alcanzar esta cantidad.</small>
                </label>
            </div>
            <div class="pack-config" data-pack-config>
                <div class="form-section-title compact"><span>6X</span><div><h2>Venta por paquete</h2><p>Ideal para bebidas y productos por six pack.</p></div></div>
                <div class="form-grid two-cols">
                    <label class="form-group">
                        <span>Unidades por paquete</span>
                        <input type="number" name="units_per_pack" value="<?= e($product['units_per_pack']) ?>" min="1" max="1000" step="1" required>
                        <small>Usa 1 si solo se vende por unidad.</small>
                    </label>
                    <label class="form-group">
                        <span>Precio del paquete</span>
                        <div class="input-prefix"><i>S/</i><input type="number" name="pack_price" value="<?= e($product['pack_price']) ?>" min="0" max="999999.99" step="0.01" placeholder="Ej. 19.00"></div>
                        <small>Déjalo vacío cuando no haya presentación por paquete.</small>
                    </label>
                </div>
            </div>
        </section>
    </div>

    <aside class="form-side-column">
        <section class="panel form-panel sticky-panel">
            <div class="form-section-title compact"><span>04</span><div><h2>Publicación</h2><p>Controla la visibilidad.</p></div></div>
            <label class="switch-row">
                <div><strong>Ocultar aunque tenga stock</strong><small>Úsalo para pausar temporalmente este producto</small></div>
                <span class="switch"><input type="checkbox" name="hidden_from_store" value="1" <?= $editing && master_product_is_manually_hidden($product) ? 'checked' : '' ?>><i></i></span>
            </label>
            <label class="switch-row">
                <div><strong>Producto restringido</strong><small>Nunca se publica ni se vende en esta tienda</small></div>
                <span class="switch"><input type="checkbox" name="restricted" value="1" <?= (int) $product['restricted'] === 1 ? 'checked' : '' ?>><i></i></span>
            </label>
            <label class="switch-row">
                <div><strong>Destacar como oferta</strong><small>Aparecerá primero en el catálogo</small></div>
                <span class="switch"><input type="checkbox" name="featured" value="1" <?= (int) $product['featured'] === 1 ? 'checked' : '' ?>><i></i></span>
            </label>
            <label class="form-group">
                <span>¿Permite entrega inmediata? <b>*</b></span>
                <select name="entrega_inmediata" required>
                    <option value="1" <?= (int) $product['entrega_inmediata'] === 1 ? 'selected' : '' ?>>Sí</option>
                    <option value="0" <?= (int) $product['entrega_inmediata'] === 0 ? 'selected' : '' ?>>No</option>
                </select>
                <small>Solo un artículo marcado como “Sí” podrá pasar a ventanilla rápida.</small>
            </label>
            <?php if (!empty($product['image_url'])): ?>
                <div class="image-preview"><img src="<?= e(image_src($product['image_url'])) ?>" alt="Vista previa de <?= e($product['name']) ?>" data-product-image></div>
            <?php endif; ?>
            <div class="form-tip"><span>i</span><p>Con precio y stock mayor que cero aparecerá automáticamente. Al llegar a stock cero se ocultará.</p></div>
            <div class="form-submit-actions">
                <button class="btn btn-primary btn-block btn-lg" type="submit"><?= $editing ? 'Guardar cambios' : 'Registrar producto' ?> <span>→</span></button>
                <a class="btn btn-secondary btn-block" href="<?= url('admin/todos_productos.php') ?>">Cancelar</a>
            </div>
        </section>
    </aside>
</form>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
