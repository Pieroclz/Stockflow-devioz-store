<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/storefront.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Browsing includes safe master records with a suggested price. The payload's
// purchasable flag still uses master_product_visibility_sql(): sale_enabled=1,
// restricted=0, active stock and a positive sale price.
try {
    $catalog = storefront_catalog(db(), $_GET);
    $safeProducts = array_values(array_filter(
        $catalog['items'],
        static fn (array $product): bool => !is_age_restricted_product($product)
    ));
    $products = array_map('storefront_product_payload', $safeProducts);

    echo json_encode([
        'success' => true,
        'products' => $products,
        'count' => $catalog['total'],
        'page' => $catalog['page'],
        'pages' => $catalog['pages'],
        'per_page' => $catalog['per_page'],
        'limited' => $catalog['page'] < $catalog['pages'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Public catalog API failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'products' => [],
        'count' => 0,
        'message' => 'No se pudo cargar el catálogo en este momento.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
