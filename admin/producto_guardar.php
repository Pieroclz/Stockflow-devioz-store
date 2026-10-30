<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';
require_once __DIR__ . '/../includes/ai_recommendations.php';
require_once __DIR__ . '/../includes/nutrition.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/todos_productos.php');
}

verify_csrf();

$id = max(0, (int) ($_POST['id'] ?? 0));
$existingMaster = null;
if ($id > 0) {
    $scopeStatement = db()->prepare("SELECT id, sale_enabled, restricted FROM products WHERE id = :id AND catalog_scope = 'master' LIMIT 1");
    $scopeStatement->execute(['id' => $id]);
    $existingMaster = $scopeStatement->fetch();
    if (!$existingMaster) {
        flash('danger', 'Solo se pueden modificar productos del catálogo maestro.');
        redirect('admin/todos_productos.php');
    }
}
$code = strtoupper(trim((string) ($_POST['code'] ?? '')));
$name = trim((string) ($_POST['name'] ?? ''));
$category = trim((string) ($_POST['category'] ?? ''));
$sourceName = trim((string) ($_POST['source_name'] ?? 'Devioz'));
$ean = trim((string) ($_POST['ean'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$supplierPriceRaw = trim((string) ($_POST['supplier_price'] ?? ''));
$suggestedPriceRaw = trim((string) ($_POST['suggested_price'] ?? ''));
$marginRaw = trim((string) ($_POST['margin_pct'] ?? ''));
$costRaw = trim((string) ($_POST['cost_price'] ?? ''));
$priceRaw = trim((string) ($_POST['price'] ?? ''));
$stockRaw = trim((string) ($_POST['stock'] ?? ''));
$minimumRaw = trim((string) ($_POST['min_stock'] ?? ''));
$unitsPerPackRaw = trim((string) ($_POST['units_per_pack'] ?? '1'));
$packPriceRaw = trim((string) ($_POST['pack_price'] ?? ''));
$imageUrl = trim((string) ($_POST['image_url'] ?? ''));
$galleryUrlsText = trim((string) ($_POST['gallery_urls'] ?? ''));
$galleryUrls = array_values(array_unique(array_filter(array_map(
    'trim',
    preg_split('/\R+/', $galleryUrlsText) ?: []
))));
$removeGalleryIds = array_values(array_unique(array_filter(array_map(
    'intval',
    is_array($_POST['remove_gallery_images'] ?? null) ? $_POST['remove_gallery_images'] : []
))));
$primaryGalleryId = max(0, (int) ($_POST['primary_gallery_image'] ?? 0));
$replaceGallery = isset($_POST['replace_gallery']);
$featured = isset($_POST['featured']) ? 1 : 0;
$hiddenFromStore = isset($_POST['hidden_from_store']);
$restricted = isset($_POST['restricted']) ? 1 : 0;
$entregaInmediataRaw = (string) ($_POST['entrega_inmediata'] ?? '1');
$entregaInmediata = in_array($entregaInmediataRaw, ['0', '1'], true) ? (int) $entregaInmediataRaw : -1;
$nutrition = nutrition_normalize([
    'applicability' => $_POST['nutrition_applicability'] ?? nutrition_default_applicability($name, $category),
    'energy_kcal_100g' => $_POST['nutrition_energy_kcal_100g'] ?? null,
    'serving_size' => $_POST['nutrition_serving_size'] ?? null,
    'serving_unit' => $_POST['nutrition_serving_unit'] ?? null,
    'energy_kcal_serving' => $_POST['nutrition_energy_kcal_serving'] ?? null,
    'source_type' => $_POST['nutrition_source_type'] ?? 'manual',
    'source_ref' => $_POST['nutrition_source_ref'] ?? '',
    'confidence' => $_POST['nutrition_confidence'] ?? 'reference',
]);
$errors = [];

if ($entregaInmediata === -1) {
    $errors[] = 'Selecciona una opción válida para la entrega inmediata.';
}

if (!preg_match('/^[A-Z0-9-]{3,20}$/', $code)) {
    $errors[] = 'El código debe tener entre 3 y 20 caracteres y usar solo letras, números o guiones.';
}

if (mb_strlen($name) < 2 || mb_strlen($name) > 255) {
    $errors[] = 'El nombre debe tener entre 2 y 255 caracteres.';
}

if (mb_strlen($category) < 2 || mb_strlen($category) > 80) {
    $errors[] = 'La categoría debe tener entre 2 y 80 caracteres.';
}

if (mb_strlen($sourceName) < 2 || mb_strlen($sourceName) > 100) {
    $errors[] = 'El origen debe tener entre 2 y 100 caracteres.';
}

if (mb_strlen($ean) > 100) {
    $errors[] = 'El EAN no puede superar los 100 caracteres.';
}

foreach (['precio del proveedor' => $supplierPriceRaw, 'precio sugerido' => $suggestedPriceRaw] as $label => $rawValue) {
    if ($rawValue !== '' && (!is_numeric($rawValue) || (float) $rawValue < 0 || (float) $rawValue > 999999.99)) {
        $errors[] = 'El ' . $label . ' debe ser un importe válido.';
    }
}

if ($marginRaw !== '' && (!is_numeric($marginRaw) || (float) $marginRaw < 0 || (float) $marginRaw > 500)) {
    $errors[] = 'El margen debe estar entre 0 y 500.';
}

if ($priceRaw === '' || !is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 999999.99) {
    $errors[] = 'Ingresa un precio válido entre 0 y 999999.99.';
}

if ($costRaw === '' || !is_numeric($costRaw) || (float) $costRaw < 0 || (float) $costRaw > 999999.99) {
    $errors[] = 'Ingresa un costo válido entre 0 y 999999.99.';
}

if (filter_var($stockRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]) === false) {
    $errors[] = 'El stock actual debe ser un número entero entre 0 y 1000000.';
}

if (filter_var($minimumRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]) === false) {
    $errors[] = 'El stock mínimo debe ser un número entero entre 0 y 1000000.';
}

if (filter_var($unitsPerPackRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) === false) {
    $errors[] = 'Las unidades por paquete deben ser un entero entre 1 y 1000.';
}

if ($packPriceRaw !== '' && (!is_numeric($packPriceRaw) || (float) $packPriceRaw <= 0 || (float) $packPriceRaw > 999999.99)) {
    $errors[] = 'El precio por paquete debe ser válido o quedar vacío.';
}

if ((int) $unitsPerPackRaw > 1 && $packPriceRaw === '') {
    $errors[] = 'Ingresa el precio del paquete cuando contiene más de una unidad.';
}

if (mb_strlen($description) > 1000) {
    $errors[] = 'La descripción no puede superar los 1000 caracteres.';
}

if (!valid_image_reference($imageUrl)) {
    $errors[] = 'La imagen debe ser una URL válida que empiece con http:// o https://.';
}
if (mb_strlen($imageUrl) > 500) {
    $errors[] = 'La URL de la imagen principal no puede superar los 500 caracteres.';
}

if (count($galleryUrls) > 8) {
    $errors[] = 'Puedes agregar como máximo 8 URLs a la galería.';
}

foreach ($galleryUrls as $galleryUrl) {
    if (!valid_image_reference($galleryUrl) || mb_strlen($galleryUrl) > 500) {
        $errors[] = 'Cada imagen adicional debe usar una URL válida que empiece con http:// o https://.';
        break;
    }
}

if (!$errors) {
    $uniqueSql = "SELECT id FROM products WHERE catalog_scope = 'master' AND code = :code" . ($id > 0 ? ' AND id <> :id' : '') . ' LIMIT 1';
    $uniqueStatement = db()->prepare($uniqueSql);
    $uniqueParameters = ['code' => $code];
    if ($id > 0) {
        $uniqueParameters['id'] = $id;
    }
    $uniqueStatement->execute($uniqueParameters);
    if ($uniqueStatement->fetch()) {
        $errors[] = 'El código ingresado ya pertenece a otro producto.';
    }
}

if (is_age_restricted_product(['name' => $name, 'category' => $category, 'description' => $description])) $restricted = 1;
$publication = master_product_publication_fields($hiddenFromStore, $restricted === 1);
$active = $publication['active'];
$saleEnabled = $publication['sale_enabled'];

$suggestedPrice = $suggestedPriceRaw !== ''
    ? (float) $suggestedPriceRaw
    : master_suggested_price($supplierPriceRaw !== '' ? (float) $supplierPriceRaw : null, $marginRaw !== '' ? (float) $marginRaw : null);

$formData = [
    'id' => $id,
    'code' => $code,
    'name' => $name,
    'category' => $category,
    'source_name' => $sourceName,
    'ean' => $ean,
    'description' => $description,
    'supplier_price' => $supplierPriceRaw,
    'suggested_price' => $suggestedPrice ?? '',
    'margin_pct' => $marginRaw,
    'cost_price' => $costRaw,
    'price' => $priceRaw,
    'stock' => $stockRaw,
    'min_stock' => $minimumRaw,
    'units_per_pack' => $unitsPerPackRaw,
    'pack_price' => $packPriceRaw,
    'image_url' => $imageUrl,
    'gallery_urls' => $galleryUrlsText,
    'primary_gallery_image' => $primaryGalleryId,
    'replace_gallery' => $replaceGallery ? 1 : 0,
    'featured' => $featured,
    'entrega_inmediata' => $entregaInmediata === -1 ? 1 : $entregaInmediata,
    'active' => $active,
    'sale_enabled' => $saleEnabled,
    'restricted' => $restricted,
    'hidden_from_store' => $hiddenFromStore ? 1 : 0,
    'nutrition_applicability' => $nutrition['applicability'],
    'nutrition_energy_kcal_100g' => $nutrition['energy_kcal_100g'] ?? '',
    'nutrition_serving_size' => $nutrition['serving_size'] ?? '',
    'nutrition_serving_unit' => $nutrition['serving_unit'] ?? '',
    'nutrition_energy_kcal_serving' => $nutrition['energy_kcal_serving'] ?? '',
    'nutrition_source_type' => $nutrition['source_type'] ?? '',
    'nutrition_source_ref' => $nutrition['source_ref'] ?? '',
    'nutrition_confidence' => $nutrition['confidence'] ?? '',
];

$existingGallery = [];
if ($id > 0) {
    try {
        $galleryStateStatement = db()->prepare('SELECT id, image_url FROM product_images WHERE product_id = :product_id ORDER BY sort_order, id');
        $galleryStateStatement->execute(['product_id' => $id]);
        $existingGallery = $galleryStateStatement->fetchAll();
    } catch (PDOException) {
        $errors[] = 'Importa database/stockflow.sql para activar la galería de imágenes.';
    }
}
$remainingGalleryUrls = array_values(array_map(
    static fn (array $image): string => (string) $image['image_url'],
    array_filter(
        $existingGallery,
        static fn (array $image): bool => !in_array((int) $image['id'], $removeGalleryIds, true)
    )
));
$selectedPrimaryImage = '';
if ($primaryGalleryId > 0) {
    foreach ($existingGallery as $existingImage) {
        if ((int) $existingImage['id'] === $primaryGalleryId && !in_array($primaryGalleryId, $removeGalleryIds, true)) {
            $selectedPrimaryImage = (string) $existingImage['image_url'];
            break;
        }
    }
}

$pendingGalleryFiles = 0;
if (isset($_FILES['gallery_files']['error']) && is_array($_FILES['gallery_files']['error'])) {
    $pendingGalleryFiles = count(array_filter(
        $_FILES['gallery_files']['error'],
        static fn (mixed $error): bool => (int) $error !== UPLOAD_ERR_NO_FILE
    ));
}
$pendingMainFile = isset($_FILES['image_file']['error']) && (int) $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE ? 1 : 0;
$hasNewGallery = $pendingGalleryFiles > 0 || $pendingMainFile > 0 || count($galleryUrls) > 0;
if ($replaceGallery && $hasNewGallery) {
    $remainingGalleryUrls = [];
    $selectedPrimaryImage = '';

    if ($pendingGalleryFiles > 0 || $pendingMainFile > 0) {
        $imageUrl = '';
        $formData['image_url'] = '';
    }
}
$plannedImages = array_values(array_unique(array_filter(array_merge(
    $imageUrl !== '' ? [$imageUrl] : [],
    $remainingGalleryUrls,
    $galleryUrls
))));
if (count($plannedImages) + $pendingGalleryFiles + $pendingMainFile > 8) {
    $errors[] = 'La galería admite como máximo 8 imágenes en total.';
}

$uploadedImage = null;
$uploadedGallery = [];
if (!$errors) {
    try {
        $uploadedImage = upload_image('image_file', 'products');
        if ($uploadedImage !== null) {
            $imageUrl = $uploadedImage;
            $formData['image_url'] = $imageUrl;
        }
        $uploadedGallery = upload_images('gallery_files', 'products', 8);
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
}

if ($selectedPrimaryImage !== '') {
    $imageUrl = $selectedPrimaryImage;
    $formData['image_url'] = $imageUrl;
}

$galleryImages = array_values(array_unique(array_filter(array_merge(
    $imageUrl !== '' ? [$imageUrl] : [],
    $remainingGalleryUrls,
    $galleryUrls,
    $uploadedImage !== null ? [$uploadedImage] : [],
    $uploadedGallery
))));
if (count($galleryImages) > 8) {
    $errors[] = 'La galería admite como máximo 8 imágenes en total.';
}
if ($imageUrl === '' && $galleryImages) {
    $imageUrl = $galleryImages[0];
    $formData['image_url'] = $imageUrl;
}
if ($imageUrl !== '') {
    $galleryImages = array_values(array_merge(
        [$imageUrl],
        array_filter($galleryImages, static fn (string $image): bool => $image !== $imageUrl)
    ));
}

if ($errors) {
    $_SESSION['errors'] = $errors;
    $_SESSION['old'] = $formData;
    redirect('admin/producto_form.php' . ($id > 0 ? '?id=' . $id : ''));
}

$parameters = [
    'code' => $code,
    'name' => $name,
    'category' => $category,
    'source_name' => $sourceName,
    'ean' => $ean !== '' ? $ean : null,
    'description' => $description !== '' ? $description : null,
    'supplier_price' => $supplierPriceRaw !== '' ? number_format((float) $supplierPriceRaw, 2, '.', '') : null,
    'suggested_price' => $suggestedPrice !== null ? number_format($suggestedPrice, 2, '.', '') : null,
    'margin_pct' => $marginRaw !== '' ? number_format((float) $marginRaw, 2, '.', '') : null,
    'cost_price' => number_format((float) $costRaw, 2, '.', ''),
    'price' => number_format((float) $priceRaw, 2, '.', ''),
    'stock' => (int) $stockRaw,
    'min_stock' => (int) $minimumRaw,
    'units_per_pack' => (int) $unitsPerPackRaw,
    'pack_price' => $packPriceRaw !== '' ? number_format((float) $packPriceRaw, 2, '.', '') : null,
    'image_url' => $imageUrl !== '' ? $imageUrl : null,
    'featured' => $featured,
    'entrega_inmediata' => $entregaInmediata,
    'active' => $active,
    'sale_enabled' => $saleEnabled,
    'restricted' => $restricted,
];

try {
    db()->beginTransaction();

    $categoryStatement = db()->prepare(
        'INSERT INTO categories (name, icon, active) VALUES (:name, :icon, 1)
         ON DUPLICATE KEY UPDATE active = VALUES(active)'
    );
    $categoryStatement->execute(['name' => $category, 'icon' => mb_strtoupper(mb_substr($category, 0, 1)) ?: 'D']);

    if ($id > 0) {
        $parameters['id'] = $id;
        $statement = db()->prepare(
            "UPDATE products
             SET code = :code, name = :name, category = :category, source_name = :source_name, ean = :ean, description = :description,
                 supplier_price = :supplier_price, suggested_price = :suggested_price, margin_pct = :margin_pct,
                 cost_price = :cost_price, price = :price, stock = :stock, min_stock = :min_stock,
                 units_per_pack = :units_per_pack, pack_price = :pack_price,
                 image_url = :image_url, featured = :featured, entrega_inmediata = :entrega_inmediata, active = :active,
                 sale_enabled = :sale_enabled, restricted = :restricted
             WHERE id = :id AND catalog_scope = 'master'"
        );
        $statement->execute($parameters);
        $productId = $id;
    } else {
        $statement = db()->prepare(
            "INSERT INTO products (code, name, category, source_name, ean, description, supplier_price, suggested_price, margin_pct, cost_price, price, stock, min_stock, units_per_pack, pack_price, image_url, featured, entrega_inmediata, active, sale_enabled, restricted, catalog_scope)
             VALUES (:code, :name, :category, :source_name, :ean, :description, :supplier_price, :suggested_price, :margin_pct, :cost_price, :price, :stock, :min_stock, :units_per_pack, :pack_price, :image_url, :featured, :entrega_inmediata, :active, :sale_enabled, :restricted, 'master')"
        );
        $statement->execute($parameters);
        $productId = (int) db()->lastInsertId();
    }

    $deleteGalleryStatement = db()->prepare('DELETE FROM product_images WHERE product_id = :product_id');
    $deleteGalleryStatement->execute(['product_id' => $productId]);
    $insertGalleryStatement = db()->prepare(
        'INSERT INTO product_images (product_id, image_url, sort_order)
         VALUES (:product_id, :image_url, :sort_order)'
    );
    foreach ($galleryImages as $sortOrder => $galleryImage) {
        $insertGalleryStatement->execute([
            'product_id' => $productId,
            'image_url' => $galleryImage,
            'sort_order' => $sortOrder,
        ]);
    }
    $nutritionStatement = db()->prepare(
        'INSERT INTO product_nutrition (product_id, applicability, energy_kcal_100g, serving_size, serving_unit, energy_kcal_serving, protein_g, carbohydrate_g, fat_g, source_type, source_ref, confidence, verified_at, verified_by)
         VALUES (:product_id,:applicability,:energy_kcal_100g,:serving_size,:serving_unit,:energy_kcal_serving,:protein_g,:carbohydrate_g,:fat_g,:source_type,:source_ref,:confidence,NOW(),:verified_by)
         ON DUPLICATE KEY UPDATE applicability=VALUES(applicability), energy_kcal_100g=VALUES(energy_kcal_100g), serving_size=VALUES(serving_size), serving_unit=VALUES(serving_unit), energy_kcal_serving=VALUES(energy_kcal_serving), source_type=VALUES(source_type), source_ref=VALUES(source_ref), confidence=VALUES(confidence), verified_at=VALUES(verified_at), verified_by=VALUES(verified_by)'
    );
    $nutritionStatement->execute([
        'product_id' => $productId,
        'applicability' => $nutrition['applicability'],
        'energy_kcal_100g' => $nutrition['energy_kcal_100g'],
        'serving_size' => $nutrition['serving_size'],
        'serving_unit' => $nutrition['serving_unit'],
        'energy_kcal_serving' => $nutrition['energy_kcal_serving'],
        'protein_g' => $nutrition['protein_g'],
        'carbohydrate_g' => $nutrition['carbohydrate_g'],
        'fat_g' => $nutrition['fat_g'],
        'source_type' => $nutrition['source_type'],
        'source_ref' => $nutrition['source_ref'],
        'confidence' => $nutrition['confidence'],
        'verified_by' => isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null,
    ]);

    db()->commit();
    flash('success', $id > 0 ? 'Producto y galería actualizados correctamente.' : 'Producto y galería registrados correctamente.');
} catch (Throwable) {
    if (db()->inTransaction()) db()->rollBack();
    $_SESSION['errors'] = ['No se pudo guardar el producto. Verifica que hayas importado la base de datos actualizada.'];
    $_SESSION['old'] = $formData;
    redirect('admin/producto_form.php' . ($id > 0 ? '?id=' . $id : ''));
}

redirect('admin/todos_productos.php');
