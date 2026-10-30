<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/storefront.php';

$pageTitle = 'Comparar productos';
$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$rows = [];
if ($query !== '') {
    // storefront_catalog() applies master_product_visibility_sql() before search:
    // master scope, sale_enabled, restricted=0, stock>0 and price>0.
    $result = storefront_catalog(db(), ['q' => $query, 'sort' => 'price_asc', 'per_page' => 48]);
    $rows = array_values(array_filter(
        $result['items'],
        static fn (array $product): bool => !is_age_restricted_product($product)
    ));
}
$minimum = $rows ? min(array_map(static fn (array $row): float => (float) $row['price'], $rows)) : 0.0;
$maximum = $rows ? max(array_map(static fn (array $row): float => (float) $row['price'], $rows)) : 0.0;
require __DIR__ . '/includes/public_header.php';
?>
<section class="suite-hero"><div class="container"><span>COMPARADOR DEVIOZ</span><h1>Compara productos disponibles</h1><p>Revisa coincidencias del inventario publicado, ordenadas desde el menor precio Devioz.</p></div></section>
<section class="suite-section"><div class="container">
<form class="compare-search" method="get"><input type="search" name="q" value="<?= e($query) ?>" placeholder="Ejemplo: leche, arroz o código" required><button class="btn btn-primary">Comparar</button></form>
<?php if($rows): ?><div class="compare-summary"><div><span>Menor precio</span><strong><?= money($minimum) ?></strong></div><div><span>Precio mayor</span><strong><?= money($maximum) ?></strong></div><div><span>Coincidencias</span><strong><?= number_format(count($rows)) ?></strong></div></div>
<div class="compare-table-wrap"><table class="compare-table"><thead><tr><th>Producto</th><th>Categoría</th><th>Presentación</th><th>Disponibilidad</th><th>Precio Devioz</th><th>Acción</th></tr></thead><tbody><?php foreach($rows as $index=>$row): $payload=storefront_product_payload($row); ?><tr class="<?= $index===0?'best-price':'' ?>"><td><strong><?= e($row['name']) ?></strong><small><?= e($row['brand'] ?: $row['code']) ?></small></td><td><?= e($row['category']) ?></td><td><?= e($row['presentation'] ?: 'Unidad') ?></td><td><?= (int)$row['stock'] ?> en stock</td><td><strong><?= money($row['price']) ?></strong><?= $index===0?'<b class="best-label">Menor precio</b>':'' ?></td><td><button class="btn btn-primary btn-sm" type="button" data-list-product="<?= e(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>">Agregar</button></td></tr><?php endforeach ?></tbody></table></div>
<p class="suite-note">Compara nombre, marca y presentación antes de elegir. Solo se muestran productos habilitados para venta.</p>
<?php elseif($query!==''): ?><div class="suite-empty"><span>⌕</span><h2>No encontramos productos</h2><p>Prueba con una palabra más corta, una marca o un código.</p></div><?php else: ?><div class="suite-empty"><span>⇄</span><h2>Escribe un producto para comenzar</h2><p>Verás hasta 48 coincidencias disponibles del catálogo Devioz.</p></div><?php endif ?>
</div></section>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
