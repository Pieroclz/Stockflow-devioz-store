<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/storefront.php';

// All catalog rows flow through master_product_visibility_sql(): sale_enabled=1,
// restricted=0, master scope, active state, stock>0 and price>0.
$catalog = storefront_catalog(db(), $_GET);
$filters = $catalog['filters'];
$products = array_values(array_filter(
    $catalog['items'],
    static fn (array $product): bool => !is_age_restricted_product($product)
));
$categories = storefront_categories(db());

$favoriteIds = [];
if (is_customer()) {
    $favoriteStatement = db()->prepare(
        "SELECT f.item_id FROM customer_favorites f INNER JOIN products p ON p.id=f.item_id
         WHERE f.customer_id=? AND f.item_type='product' AND " . master_product_visibility_sql('p')
         . ' AND ' . public_product_text_visibility_sql('p')
    );
    $favoriteStatement->execute([customer_id()]);
    $favoriteIds = array_map('intval', $favoriteStatement->fetchAll(PDO::FETCH_COLUMN));
}

$paginationParams = [
    'q' => $filters['q'],
    'category' => $filters['category'],
    'min_price' => $filters['min_price'],
    'max_price' => $filters['max_price'],
    'fast_delivery' => $filters['fast_delivery'] ? 1 : null,
    'sort' => $filters['sort'],
    'per_page' => $catalog['per_page'],
];
$paginationParams = array_filter($paginationParams, static fn (mixed $value): bool => $value !== null && $value !== '');

$pageTitle = 'Catálogo completo';
require __DIR__ . '/includes/public_header.php';
?>
<section class="master-catalog-hero" aria-labelledby="catalogPageTitle">
    <div class="container">
        <div>
            <span class="section-kicker">DESCUBRE DEVIOZ ✨</span>
            <h1 id="catalogPageTitle">¡Encuentra algo que te encante!</h1>
            <p>Tus favoritos de siempre y nuevos descubrimientos. Explora, elige y disfruta de comprar fácil.</p>
        </div>
        <div class="catalog-hero-stat" aria-label="Productos disponibles">
            <strong><?= number_format($catalog['total']) ?></strong>
            <span>productos en catálogo</span>
        </div>
    </div>
</section>

<section class="master-catalog-section" aria-label="Explorar productos">
    <div class="container master-catalog-layout">
        <aside class="catalog-filter-card" aria-labelledby="catalogFiltersTitle">
            <form method="get">
                <div class="catalog-filter-heading">
                    <div><span>ENCUENTRA LO TUYO</span><h2 id="catalogFiltersTitle">Filtros</h2></div>
                    <a href="<?= url('catalogo_completo.php') ?>">Limpiar</a>
                </div>
                <label>Buscar
                    <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nombre, marca o código">
                </label>
                <label>Categoría
                    <select name="category">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category) ?>" <?= $filters['category'] === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Ordenar por
                    <select name="sort">
                        <option value="featured" <?= $filters['sort'] === 'featured' ? 'selected' : '' ?>>Destacados</option>
                        <option value="popular" <?= $filters['sort'] === 'popular' ? 'selected' : '' ?>>Más vendidos</option>
                        <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Más recientes</option>
                        <option value="name" <?= $filters['sort'] === 'name' ? 'selected' : '' ?>>Nombre A–Z</option>
                        <option value="price_asc" <?= $filters['sort'] === 'price_asc' ? 'selected' : '' ?>>Menor precio</option>
                        <option value="price_desc" <?= $filters['sort'] === 'price_desc' ? 'selected' : '' ?>>Mayor precio</option>
                    </select>
                </label>
                <details class="catalog-extra-filters" <?= $filters['min_price'] !== null || $filters['max_price'] !== null || $filters['fast_delivery'] || $catalog['per_page'] === 48 ? 'open' : '' ?>>
                    <summary>Más filtros <span aria-hidden="true">⌄</span></summary>
                    <div class="catalog-price-fields">
                        <label>Precio mínimo<input type="number" name="min_price" value="<?= $filters['min_price'] !== null ? e((string) $filters['min_price']) : '' ?>" min="0" step="0.01" placeholder="S/ 0"></label>
                        <label>Precio máximo<input type="number" name="max_price" value="<?= $filters['max_price'] !== null ? e((string) $filters['max_price']) : '' ?>" min="0" step="0.01" placeholder="Sin límite"></label>
                    </div>
                    <label>Resultados por página<select name="per_page"><option value="24" <?= $catalog['per_page'] === 24 ? 'selected' : '' ?>>24 productos</option><option value="48" <?= $catalog['per_page'] === 48 ? 'selected' : '' ?>>48 productos</option></select></label>
                    <label class="catalog-check"><input type="checkbox" name="fast_delivery" value="1" <?= $filters['fast_delivery'] ? 'checked' : '' ?>><span><b>Entrega rápida</b><small>Solo productos con despacho inmediato</small></span></label>
                </details>
                <button class="btn btn-primary" type="submit">Aplicar filtros</button>
            </form>
        </aside>

        <div class="master-catalog-results">
            <div class="catalog-result-toolbar">
                <div><strong><?= number_format($catalog['total']) ?> productos disponibles</strong><span>Página <?= $catalog['page'] ?> de <?= $catalog['pages'] ?></span></div>
                <a href="<?= url('index.php#catalogo') ?>">Volver al inicio</a>
            </div>

            <div class="master-product-grid">
                <?php foreach ($products as $product): ?>
                    <?php
                    $payload = storefront_product_payload($product);
                    $presentation = trim(implode(' · ', array_filter([$payload['brand'], $payload['presentation']])));
                    $isFavorite = in_array((int) $product['id'], $favoriteIds, true);
                    ?>
                    <article class="master-product-card">
                        <div class="master-product-media">
                            <?php if ($payload['image_url'] !== ''): ?>
                                <img src="<?= e($payload['image_url']) ?>" alt="<?= e($payload['name']) ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-image-fallback data-product-image>
                                <span class="media-fallback" hidden><?= e($payload['icon']) ?></span>
                            <?php else: ?><span class="master-product-symbol"><?= e($payload['icon']) ?></span><?php endif; ?>
                            <div class="master-product-badges">
                                <?php if ($payload['featured']): ?><span>Destacado</span><?php endif; ?>
                                <?php if ($payload['fast_delivery']): ?><span>Entrega rápida</span><?php endif; ?>
                            </div>
                        </div>
                        <div class="master-product-body">
                            <small><?= e($payload['category']) ?></small>
                            <h2><?= e($payload['name']) ?></h2>
                            <p><?= e($presentation !== '' ? $presentation : $payload['description']) ?></p>
                            <div class="master-product-price"><span>Precio</span><strong><?= money($payload['price']) ?></strong></div>
                            <div class="master-product-actions">
                                <button type="button" data-list-product="<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">¡Lo quiero! <span aria-hidden="true">＋</span></button>
                                <?php if (is_customer()): ?>
                                    <form method="post" action="<?= url('favorito.php') ?>" data-ajax-favorite>
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="item_type" value="product">
                                        <input type="hidden" name="item_id" value="<?= (int) $product['id'] ?>">
                                        <input type="hidden" name="return" value="catalogo_completo.php?<?= e(http_build_query($paginationParams + ['page' => $catalog['page']])) ?>">
                                        <button type="submit" aria-pressed="<?= $isFavorite ? 'true' : 'false' ?>" aria-label="<?= $isFavorite ? 'Quitar de favoritos' : 'Guardar en favoritos' ?>"><?= $isFavorite ? '♥' : '♡' ?></button>
                                    </form>
                                <?php else: ?><a href="<?= url('mi_cuenta.php') ?>" aria-label="Inicia sesión para guardar favoritos">♡</a><?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if (!$products): ?>
                <div class="suite-empty"><span>⌕</span><h2>No encontramos productos</h2><p>Prueba otra palabra, categoría o rango de precio.</p></div>
            <?php endif; ?>

            <?php if ($catalog['pages'] > 1): ?>
                <nav class="master-pagination" aria-label="Paginación del catálogo">
                    <?php if ($catalog['page'] > 1): ?><a href="?<?= e(http_build_query($paginationParams + ['page' => $catalog['page'] - 1])) ?>" rel="prev">← Anterior</a><?php endif; ?>
                    <?php for ($number = max(1, $catalog['page'] - 2); $number <= min($catalog['pages'], $catalog['page'] + 2); $number++): ?>
                        <a href="?<?= e(http_build_query($paginationParams + ['page' => $number])) ?>" class="<?= $number === $catalog['page'] ? 'is-current' : '' ?>" <?= $number === $catalog['page'] ? 'aria-current="page"' : '' ?>><?= $number ?></a>
                    <?php endfor; ?>
                    <?php if ($catalog['page'] < $catalog['pages']): ?><a href="?<?= e(http_build_query($paginationParams + ['page' => $catalog['page'] + 1])) ?>" rel="next">Siguiente →</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</section>
<div id="favoriteNotice" class="devioz-toast" role="status" aria-live="polite" hidden></div>
<script src="<?= url('assets/js/favoritos_ajax.js?v=1') ?>" defer></script>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
