<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_recommendations.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function admin_product_search_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    admin_product_search_response(405, ['items' => [], 'message' => 'Método no permitido.']);
}

if (!is_admin()) {
    admin_product_search_response(401, ['items' => [], 'message' => 'Inicia sesión para buscar productos.']);
}

$role = (string) ($_SESSION['admin_role'] ?? 'admin');
if (!in_array($role, ['admin', 'inventario'], true)) {
    admin_product_search_response(403, ['items' => [], 'message' => 'Tu rol no tiene permiso para buscar productos.']);
}
require_admin();

$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$comboMode = ($_GET['mode'] ?? '') === 'combo';
if ($query === '' && !$comboMode) {
    admin_product_search_response(200, ['items' => []]);
}

$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';

try {
    $statement = db()->prepare(
        "SELECT p.id, p.name, p.code, p.category, p.description, p.stock, p.price, p.suggested_price, p.cost_price, p.units_per_pack, p.pack_price,
                p.active, p.sale_enabled, p.restricted
         FROM products p
         WHERE p.catalog_scope = 'master'
           AND p.restricted = 0 AND p.stock > 0 " . ($comboMode ? '' : 'AND p.price > 0') . "
           " . ($comboMode ? 'AND p.active = 1' : '') . "
           AND " . public_product_text_visibility_sql('p') . "
           AND (p.name LIKE :name_q ESCAPE '\\\\'
                OR p.code LIKE :code_q ESCAPE '\\\\'
                OR p.ean LIKE :ean_q ESCAPE '\\\\')
         ORDER BY " . ($comboMode && $query === '' ? 'p.stock DESC, ' : '') . "p.name ASC, p.id ASC
         LIMIT 20"
    );
    $statement->execute(['name_q' => $like, 'code_q' => $like, 'ean_q' => $like]);
    $items = array_map(static fn (array $product): array => [
        'id' => (int) $product['id'],
        'name' => (string) $product['name'],
        'code' => (string) $product['code'],
        'category' => (string) $product['category'],
        'stock' => (int) $product['stock'],
        'price' => (float) $product['price'],
        'suggested_price' => (float) ($product['suggested_price'] ?? 0),
        'cost_price' => (float) $product['cost_price'],
        'units_per_pack' => (int) $product['units_per_pack'],
        'pack_price' => (float) $product['pack_price'],
        'active' => (int) $product['active'],
        'sale_enabled' => (int) $product['sale_enabled'],
        'restricted' => (int) $product['restricted'],
    ], $statement->fetchAll());
    admin_product_search_response(200, ['items' => $items]);
} catch (Throwable $exception) {
    error_log('Admin product search failed: ' . $exception->getMessage());
    admin_product_search_response(500, ['items' => [], 'message' => 'No se pudo buscar productos en este momento.']);
}
