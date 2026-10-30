<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/storefront.php';
require_once __DIR__ . '/includes/ai_recommendations.php';

$pageTitle = 'Productos y ofertas';
$catalog = storefront_catalog(db(), ['per_page' => 24, 'page' => 1, 'sort' => 'featured']);
$products = $catalog['items'];
$storefront = ['offers'=>[],'recommended'=>[],'bestsellers'=>[],'categories'=>[],'banners'=>[]];
try { $storefront = storefront_sections(db(),12); } catch (PDOException) {}
$offerProducts = storefront_exclude_age_restricted($storefront['offers']);
$recommendedProducts = storefront_exclude_age_restricted($storefront['recommended']);
$bestsellerProducts = storefront_exclude_age_restricted($storefront['bestsellers']);
$catalogProducts = $products;
$heroBanner = $storefront['banners'][0] ?? null;
$heroProducts = array_slice(array_values(array_filter(
    $products,
    static fn (array $product): bool => !empty($product['image_url'])
)), 0, 3);

$combos = db()->query(
    "SELECT c.id, c.name, c.description, c.price, c.image_url, c.featured,
            COALESCE(SUM(ci.quantity * p.price), 0) AS regular_price,
            GROUP_CONCAT(CONCAT(ci.quantity, '× ', p.name) ORDER BY p.name SEPARATOR ' · ') AS item_names,
            GROUP_CONCAT(p.description ORDER BY p.name SEPARATOR ' · ') AS item_descriptions,
            GROUP_CONCAT(p.image_url ORDER BY p.name SEPARATOR '|||') AS item_images,
            LEAST(COALESCE(c.stock, 4294967295), MIN(FLOOR(p.stock / ci.quantity))) AS available
     FROM combos c INNER JOIN combo_items ci ON ci.combo_id = c.id INNER JOIN products p ON p.id = ci.product_id
     WHERE c.active = 1 AND c.price > 0 AND " . public_combo_text_visibility_sql('c') . "
       AND ci.quantity > 0 AND " . master_product_visibility_sql('p') . "
       AND " . public_product_text_visibility_sql('p') . "
       AND NOT EXISTS (
           SELECT 1 FROM combo_items ci_block
           INNER JOIN products p_block ON p_block.id = ci_block.product_id
           WHERE ci_block.combo_id = c.id
             AND (ci_block.quantity <= 0 OR NOT (" . master_product_visibility_sql('p_block') . "
                  AND " . public_product_text_visibility_sql('p_block') . "))
       )
     GROUP BY c.id ORDER BY c.featured DESC, c.created_at DESC LIMIT 6"
)->fetchAll();
$combos = array_values(array_filter($combos, static function (array $combo): bool {
    $comboSafe = !is_age_restricted_product([
        'name' => $combo['name'],
        'category' => 'Combos',
        'description' => $combo['description'] ?? '',
    ]);
    $componentsSafe = !is_age_restricted_product([
        'name' => $combo['item_names'] ?? '',
        'category' => '',
        'description' => $combo['item_descriptions'] ?? '',
    ]);
    return $comboSafe && $componentsSafe && (float)$combo['price'] > 0;
}));

$comboDetails = [];
$comboIds = array_map(static fn(array $combo): int => (int)$combo['id'], $combos);
if ($comboIds) {
    $comboPlaceholders = implode(',', array_fill(0, count($comboIds), '?'));
    $comboItemsStatement = db()->prepare(
        "SELECT ci.combo_id, ci.quantity, p.id, p.name, p.category, p.description, p.price, p.image_url
         FROM combo_items ci
         INNER JOIN products p ON p.id = ci.product_id
         INNER JOIN combos c ON c.id = ci.combo_id
         WHERE c.id IN ($comboPlaceholders) AND c.active = 1 AND c.price > 0
           AND " . public_combo_text_visibility_sql('c') . "
           AND ci.quantity > 0 AND " . master_product_visibility_sql('p') . "
           AND " . public_product_text_visibility_sql('p') . "
         ORDER BY ci.combo_id, p.name"
    );
    $comboItemsStatement->execute($comboIds);
    foreach ($comboItemsStatement->fetchAll() as $comboItem) {
        if (is_age_restricted_product($comboItem)) continue;
        $comboDetails[(int) $comboItem['combo_id']][] = [
            'id' => (int) $comboItem['id'],
            'name' => (string) $comboItem['name'],
            'description' => (string) ($comboItem['description'] ?: 'Producto incluido en este combo.'),
            'quantity' => (int) $comboItem['quantity'],
            'price' => (float) $comboItem['price'],
            'image_url' => $comboItem['image_url'] ? image_src((string) $comboItem['image_url']) : '',
        ];
    }
}

$categories = storefront_categories(db());
require __DIR__ . '/includes/public_header.php';
?>

<section class="hero storefront-hero" id="inicio" aria-labelledby="storefrontHeroTitle">
    <div class="hero-orb hero-orb-one"></div>
    <div class="hero-orb hero-orb-two"></div>
    <div class="container hero-grid">
        <div class="hero-copy">
            <span class="eyebrow"><i></i> DEVIOZ STORE · CATÁLOGO ONLINE</span>
            <h1 id="storefrontHeroTitle"><?= e($heroBanner['title'] ?? 'Todo lo que buscas, en un solo lugar.') ?></h1>
            <p><?= e($heroBanner['subtitle'] ?? 'Explora productos disponibles, encuentra ofertas y prepara tu compra con ayuda inteligente.') ?></p>
            <div class="hero-actions">
                <a class="btn btn-primary" href="<?= e($heroBanner ? storefront_safe_link($heroBanner['link_url']) : '#catalogo') ?>">Ver productos <span>→</span></a>
                <a class="hero-list-button" href="#categorias"><span>✦</span> Ver categorías</a>
            </div>
            <div class="hero-proof">
                <span><i>✓</i> Fotografías del producto</span>
                <span><i>✓</i> Precios claros</span>
                <span><i>✓</i> Lista sin registro</span>
            </div>
        </div>
        <div class="hero-merch" aria-hidden="true">
            <?php if ($heroBanner && !empty($heroBanner['image_url'])): ?>
                <img src="<?= e(image_src($heroBanner['image_url'])) ?>" alt="" loading="eager" decoding="async" data-product-image>
            <?php elseif ($heroProducts): ?>
                <?php foreach ($heroProducts as $position => $heroProduct): ?>
                    <span class="hero-product hero-product-<?= $position + 1 ?>"><img src="<?= e(image_src($heroProduct['image_url'])) ?>" alt="" loading="eager" decoding="async" data-product-image></span>
                <?php endforeach; ?>
            <?php else: ?><span class="hero-bag">D</span><?php endif; ?>
        </div>
    </div>
</section>

<section class="category-shortcuts" id="categorias" aria-label="Categorías de la tienda">
    <div class="container">
        <div class="category-surface">
            <div class="shortcut-heading">
                <div><span>Compra más rápido</span><strong>Explora por categoría</strong></div>
                <small><i></i> <?= count($categories) ?> categorías disponibles</small>
            </div>
            <div class="category-pills category-circles" id="categoryPills">
                <button class="category-pill is-active" type="button" data-category-pick="">
                    <i aria-hidden="true">✦</i><span>Todo</span>
                </button>
                <?php foreach (array_slice($categories, 0, 10) as $category): ?>
                    <button class="category-pill" type="button" data-category-pick="<?= e($category) ?>">
                        <i aria-hidden="true"><?= e(category_icon($category)) ?></i><span><?= e($category) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <a class="category-all-link" href="<?= url('catalogo_completo.php') ?>">Ver todas las categorías <span aria-hidden="true">→</span></a>
        </div>
    </div>
</section>

<?php if ($offerProducts): ?>
<section class="storefront-shelf offer-shelf" id="ofertas" aria-labelledby="offersTitle">
    <div class="container">
        <div class="section-heading shelf-heading">
            <div><span class="section-kicker">OPORTUNIDADES DEVIOZ</span><h2 id="offersTitle">Ofertas para aprovechar</h2><p>Destacados y packs con precio conveniente, siempre sujetos al stock publicado.</p></div>
            <a href="<?= url('catalogo_completo.php?sort=featured') ?>">Ver catálogo <span aria-hidden="true">→</span></a>
        </div>
        <div class="storefront-shelf-grid">
            <?php foreach (array_slice($offerProducts, 0, 6) as $product): ?>
                <?php $payload = storefront_product_payload($product); ?>
                <article class="shelf-product-card">
                    <div class="shelf-product-media">
                        <span class="shelf-badge">Oferta Devioz</span>
                        <?php if ($payload['image_url']): ?><img src="<?= e($payload['image_url']) ?>" alt="<?= e($payload['name']) ?>" loading="lazy" decoding="async" data-product-image><?php else: ?><i><?= e($payload['icon']) ?></i><?php endif; ?>
                    </div>
                    <div class="shelf-product-body"><small><?= e($payload['category']) ?></small><h3><?= e($payload['name']) ?></h3><p><?= e($payload['presentation'] ?: $payload['description']) ?></p><div><strong><?= money($payload['price']) ?></strong><button type="button" data-list-product="<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" aria-label="Agregar <?= e($payload['name']) ?>">＋</button></div></div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($recommendedProducts): ?>
<section class="storefront-shelf recommendation-shelf" aria-labelledby="recommendationsTitle">
    <div class="container">
        <div class="section-heading shelf-heading">
            <div><span class="section-kicker">SELECCIÓN INTELIGENTE</span><h2 id="recommendationsTitle">Recomendaciones para tu compra</h2><p>Una selección segura del inventario publicado; el asistente puede adaptarla a tu presupuesto.</p></div>
            <button type="button" data-open-assistant>Consultar al asistente <span aria-hidden="true">✦</span></button>
        </div>
        <div class="storefront-shelf-grid">
            <?php foreach (array_slice($recommendedProducts, 0, 6) as $product): ?>
                <?php $payload = storefront_product_payload($product); ?>
                <article class="shelf-product-card">
                    <div class="shelf-product-media"><?php if ($payload['image_url']): ?><img src="<?= e($payload['image_url']) ?>" alt="<?= e($payload['name']) ?>" loading="lazy" decoding="async" data-image-fallback data-product-image><i class="media-fallback" hidden><?= e($payload['icon']) ?></i><?php else: ?><i><?= e($payload['icon']) ?></i><?php endif; ?></div>
                    <div class="shelf-product-body"><small><?= e($payload['category']) ?></small><h3><?= e($payload['name']) ?></h3><p><?= e($payload['presentation'] ?: $payload['description']) ?></p><div><strong><?= money($payload['price']) ?></strong><button type="button" data-list-product="<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" aria-label="Agregar <?= e($payload['name']) ?>">＋</button></div></div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if ($bestsellerProducts): ?>
<section class="storefront-shelf bestseller-shelf" aria-labelledby="bestsellersTitle">
    <div class="container">
        <div class="section-heading shelf-heading"><div><span class="section-kicker">LOS MÁS ELEGIDOS</span><h2 id="bestsellersTitle">Productos más vendidos</h2><p>Favoritos de clientes, ordenados con ventas aprobadas y disponibilidad actual.</p></div><a href="<?= url('catalogo_completo.php?sort=popular') ?>">Explorar más <span aria-hidden="true">→</span></a></div>
        <div class="storefront-shelf-grid">
            <?php foreach (array_slice($bestsellerProducts, 0, 6) as $rank => $product): ?>
                <?php $payload = storefront_product_payload($product); ?>
                <article class="shelf-product-card"><span class="bestseller-rank" aria-label="Puesto <?= $rank + 1 ?>"><?= $rank + 1 ?></span><div class="shelf-product-media"><?php if ($payload['image_url']): ?><img src="<?= e($payload['image_url']) ?>" alt="<?= e($payload['name']) ?>" loading="lazy" decoding="async" data-image-fallback data-product-image><i class="media-fallback" hidden><?= e($payload['icon']) ?></i><?php else: ?><i><?= e($payload['icon']) ?></i><?php endif; ?></div><div class="shelf-product-body"><small><?= e($payload['category']) ?></small><h3><?= e($payload['name']) ?></h3><p><?= e($payload['presentation'] ?: $payload['description']) ?></p><div><strong><?= money($payload['price']) ?></strong><button type="button" data-list-product="<?= e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" aria-label="Agregar <?= e($payload['name']) ?>">＋</button></div></div></article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="combo-showcase" id="combos" aria-label="Combos de la tienda">
    <div class="container">
        <div class="section-heading"><div><span class="section-kicker">COMBOS DEVIOZ</span><h2>Más productos, mejor precio</h2><p>Promociones preparadas para comprar de manera sencilla.</p></div></div>
        <div class="public-combos">
            <?php foreach ($combos as $combo): ?>
                <?php
                    $saving = max(0, (float) $combo['regular_price'] - (float) $combo['price']);
                    $comboAvailable = max(0, (int) $combo['available']);
                    $comboImages = array_values(array_filter(explode('|||', (string) ($combo['item_images'] ?? ''))));
                    $comboMainImage = $combo['image_url'] ? image_src($combo['image_url']) : (isset($comboImages[0]) ? image_src($comboImages[0]) : '');
                    $comboData = [
                        'kind' => 'combo',
                        'id' => (int) $combo['id'],
                        'code' => 'COMBO-' . str_pad((string) $combo['id'], 3, '0', STR_PAD_LEFT),
                        'name' => $combo['name'],
                        'category' => 'Combos',
                        'price' => (float) $combo['price'],
                        'stock' => $comboAvailable,
                        'image_url' => $comboMainImage,
                        'icon' => '✦',
                    ];
                    $comboViewData = [
                        'id' => (int) $combo['id'],
                        'name' => (string) $combo['name'],
                        'description' => (string) ($combo['description'] ?: 'Una selección especial de Devioz Store.'),
                        'price' => (float) $combo['price'],
                        'regular_price' => (float) $combo['regular_price'],
                        'saving' => $saving,
                        'available' => $comboAvailable,
                        'products' => $comboDetails[(int) $combo['id']] ?? [],
                    ];
                ?>
                <article class="public-combo-card">
                    <div class="combo-visual">
                        <?php if ($combo['image_url']): ?>
                            <img src="<?= e(image_src($combo['image_url'])) ?>" alt="<?= e($combo['name']) ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-image-fallback data-product-image>
                            <span class="media-fallback" hidden>✦</span>
                        <?php elseif ($comboImages): ?>
                            <div class="combo-mosaic" aria-label="Productos del combo">
                                <?php foreach (array_slice($comboImages, 0, 3) as $comboImage): ?>
                                    <span><img src="<?= e(image_src($comboImage)) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-image-fallback data-product-image><i class="media-fallback" hidden>✦</i></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?><span class="media-fallback">✦</span><?php endif; ?>
                        <?= (int) $combo['featured'] === 1 ? '<b>DESTACADO</b>' : '' ?>
                    </div>
                    <div class="combo-content">
                        <span class="combo-label">COMBO</span><h3><?= e($combo['name']) ?></h3>
                        <p><?= e($combo['description'] ?: 'Una selección especial de Devioz Store.') ?></p>
                        <small><?= e($combo['item_names']) ?></small>
                        <div class="combo-price-row"><div><del><?= money($combo['regular_price']) ?></del><strong><?= money($combo['price']) ?></strong></div><span>Ahorra <?= money($saving) ?></span></div>
                        <div class="combo-footer combo-footer-actions">
                            <button class="combo-detail-button" type="button" data-combo-details="<?= e(json_encode($comboViewData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"><span>▦</span> Ver productos</button>
                            <button class="combo-add-button" type="button"
                                    data-buy-now="1"
                                    data-list-combo="<?= e(json_encode($comboData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                    <?= $comboAvailable <= 0 ? 'disabled' : '' ?>>
                                <?= $comboAvailable <= 0 ? 'Agotado' : '<span>🛒</span> Agregar combo' ?>
                            </button>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$combos): ?><div class="empty-mini">Pronto encontrarás nuevos combos.</div><?php endif; ?>
        </div>
    </div>
</section>

<div class="modal-backdrop" id="comboDetailModal" hidden>
    <section class="modal combo-detail-modal" role="dialog" aria-modal="true" aria-labelledby="comboDetailName">
        <button class="modal-close" type="button" data-modal-close aria-label="Cerrar">×</button>
        <header class="combo-detail-header">
            <span class="combo-label">DETALLE DEL COMBO</span>
            <h2 id="comboDetailName">Productos incluidos</h2>
            <p id="comboDetailDescription"></p>
        </header>
        <div class="combo-detail-products" id="comboDetailProducts"></div>
        <footer class="combo-detail-footer">
            <div><small>Precio normal</small><del id="comboDetailRegular"></del></div>
            <div><small>Precio del combo</small><strong id="comboDetailPrice"></strong></div>
            <span id="comboDetailSaving"></span>
            <button class="btn btn-primary" id="comboDetailAdd" type="button">Agregar combo al carrito</button>
        </footer>
    </section>
</div>

<section class="catalog-section" id="catalogo">
    <div class="container">
        <div class="section-heading">
            <div>
                <span class="section-kicker">CATÁLOGO DEVIOZ STORE</span>
                <h2>Nuestros productos</h2>
                <p>Imágenes claras, descripciones sencillas y precios visibles.</p>
            </div>
            <?php $catalogTotal = $catalog['total']; ?>
            <span class="result-count" id="resultCount"><?= number_format($catalogTotal) ?> resultado<?= $catalogTotal === 1 ? '' : 's' ?></span>
        </div>

        <div class="catalog-toolbar">
            <a class="catalog-stock-shortcut" href="<?= url('catalogo_completo.php?available_only=1') ?>">Ver productos disponibles para comprar →</a>
            <div class="search-control">
                <span aria-hidden="true">⌕</span>
                <input id="productSearch" type="search" placeholder="Buscar por nombre o descripción..." autocomplete="off" aria-label="Buscar productos">
                <button class="search-clear" id="searchClear" type="button" aria-label="Limpiar búsqueda" hidden>×</button>
                <kbd>⌘ K</kbd>
            </div>
            <label class="select-control" for="categoryFilter">
                <span>Filtrar:</span>
                <select id="categoryFilter">
                    <option value="">Todas las categorías</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category) ?>"><?= e($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="select-control" for="productSort">
                <span>Ordenar:</span>
                <select id="productSort">
                    <option value="featured">Destacados</option>
                    <option value="name">Nombre A-Z</option>
                    <option value="price_asc">Menor precio</option>
                    <option value="price_desc">Mayor precio</option>
                </select>
            </label>
        </div>

        <div class="product-grid" id="productGrid" aria-live="polite">
            <?php foreach ($catalogProducts as $product): ?>
                <?php
                    $publicProduct = storefront_product_payload($product);
                    $displayName = $publicProduct['name'];
                    $displayCategory = $publicProduct['category'];
                    $displayImage = $publicProduct['image_url'];
                    $displayDescription = $publicProduct['presentation'] ?: $publicProduct['description'];
                    $displayPrice = $publicProduct['price'];
                ?>
                <article class="product-card public-product-card">
                    <button class="product-visual catalog-product-visual product-detail-btn" type="button" data-product="<?= e(json_encode($publicProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" aria-label="Ver imágenes de <?= e($displayName) ?>">
                        <?php if (!empty($displayImage)): ?>
                            <div class="product-image-frame">
                                <img src="<?= e($displayImage) ?>" alt="<?= e($displayName) ?>" loading="lazy" decoding="async" referrerpolicy="no-referrer" data-image-fallback data-product-image>
                                <span class="media-fallback" hidden><?= e(category_icon($displayCategory)) ?></span>
                            </div>
                        <?php else: ?>
                            <span class="product-symbol"><?= e(category_icon($displayCategory)) ?></span>
                        <?php endif; ?>
                        <?php if (count($product['images'] ?? []) > 1): ?><span class="gallery-hint">Ver galería</span><?php endif; ?>
                    </button>
                    <div class="product-content">
                        <h3><?= e($displayName) ?></h3>
                        <p><?= e($displayDescription) ?></p>
                        <div class="catalog-price"><span><?= $publicProduct['purchasable'] ? 'Precio Devioz' : 'Precio referencial' ?></span><div><strong><?= money($displayPrice) ?></strong></div></div>
                        <?php if ($publicProduct['purchasable']): ?>
                            <button class="catalog-add-button" type="button"
                                    data-buy-now="1"
                                    data-list-product="<?= e(json_encode($publicProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                                <span>🛒</span> Agregar al carrito
                            </button>
                        <?php else: ?>
                            <button class="catalog-add-button" type="button" disabled>Consultar disponibilidad</button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="empty-state" id="emptyState" <?= $catalogProducts ? 'hidden' : '' ?>>
            <span>⌕</span>
            <h3>No encontramos productos</h3>
            <p>Prueba con otro nombre o descripción.</p>
        </div>
        <div class="catalog-more"><a class="btn btn-secondary" href="<?= url('catalogo_completo.php') ?>">Ver catálogo completo <span aria-hidden="true">→</span></a></div>
    </div>
</section>

<section class="benefits">
    <div class="container benefit-grid">
        <article><span>↻</span><div><strong>Catálogo actualizado</strong><p>Productos organizados para comprar con facilidad.</p></div></article>
        <article><span>⌕</span><div><strong>Búsqueda instantánea</strong><p>Encuentra productos en pocos segundos.</p></div></article>
        <article><span>✓</span><div><strong>Información clara</strong><p>Nombre, descripción, imagen y precio en cada producto.</p></div></article>
    </div>
</section>

<div class="modal-backdrop" id="productModal" hidden>
    <section class="modal product-modal" role="dialog" aria-modal="true" aria-labelledby="productModalName">
        <button class="modal-close" type="button" data-modal-close aria-label="Cerrar">×</button>
        <div class="product-gallery-shell">
            <div class="product-gallery-thumbnails" id="productGalleryThumbnails" aria-label="Imágenes del producto"></div>
            <div class="product-gallery-stage">
                <div class="modal-product-visual" id="productModalVisual"><span class="media-fallback">▦</span></div>
                <button class="gallery-arrow gallery-arrow-prev" id="productGalleryPrev" type="button" aria-label="Imagen anterior">‹</button>
                <button class="gallery-arrow gallery-arrow-next" id="productGalleryNext" type="button" aria-label="Imagen siguiente">›</button>
                <button class="gallery-zoom" id="productGalleryZoom" type="button" aria-label="Ampliar imagen">⌕ Ampliar</button>
                <div class="product-gallery-dots" id="productGalleryDots" aria-label="Posición de la galería"></div>
            </div>
        </div>
        <div class="modal-product-body">
            <span class="modal-availability"><i></i> Producto Devioz Store</span>
            <h2 id="productModalName"></h2>
            <p id="productModalDescription"></p>
            <div class="modal-public-price"><span>Precio</span><strong id="productModalPrice"></strong></div>
            <button class="btn btn-primary btn-block" type="button" data-modal-close>Seguir explorando</button>
        </div>
    </section>
</div>

<?php require __DIR__ . '/includes/public_footer.php'; ?>
