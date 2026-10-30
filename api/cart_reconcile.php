<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';
require_once __DIR__ . '/../includes/ai_recommendations.php';
require_once __DIR__ . '/../includes/public_cart_reconciliation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cart_reconcile_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cart_reconcile_response(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    cart_reconcile_response(400, ['success' => false, 'message' => 'Solicitud inválida.']);
}
$token = (string) ($input['csrf_token'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    cart_reconcile_response(419, ['success' => false, 'message' => 'La sesión expiró.']);
}

$items = cart_reconciliation_request_items(is_array($input['items'] ?? null) ? $input['items'] : []);
if (!$items) {
    cart_reconcile_response(200, ['success' => true, 'items' => []]);
}

try {
    $connection = db();
    $productQuery = $connection->prepare(
        'SELECT p.id,p.code,p.name,p.category,p.description,p.price,p.stock,p.units_per_pack,p.pack_price,p.image_url,p.restricted
         FROM products p WHERE p.id=? AND ' . master_product_visibility_sql('p')
         . ' AND ' . public_product_text_visibility_sql('p') . ' LIMIT 1'
    );
    $comboQuery = $connection->prepare(
        'SELECT c.id,c.name,c.description,c.price,c.stock,c.image_url,c.active FROM combos c
         WHERE c.id = ? AND c.active = 1 AND c.price > 0 AND ' . public_combo_text_visibility_sql('c') . ' LIMIT 1'
    );
    $comboComponentsQuery = $connection->prepare(
        'SELECT p.id,p.name,p.category,p.description,p.stock,p.restricted,ci.quantity
         FROM combo_items ci INNER JOIN products p ON p.id=ci.product_id
         WHERE ci.combo_id=? AND ci.quantity>0 AND ' . master_product_visibility_sql('p')
         . ' AND ' . public_product_text_visibility_sql('p') . ' ORDER BY p.id'
    );
    $comboComponentCountQuery = $connection->prepare('SELECT COUNT(*) FROM combo_items WHERE combo_id=?');

    $candidates = [];
    $stockLedger = [];
    foreach ($items as $item) {
        if ($item['type'] === 'product' || $item['type'] === 'product_pack') {
            $productQuery->execute([$item['id']]);
            $product = $productQuery->fetch();
            if (!$product || (int) $product['restricted'] !== 0 || is_age_restricted_product($product)) continue;

            $isPack = $item['type'] === 'product_pack';
            $packSize = max(1, (int) ($product['units_per_pack'] ?? 1));
            if ($isPack && ($packSize <= 1 || (float) ($product['pack_price'] ?? 0) <= 0)) continue;
            $productId = (int) $product['id'];
            $stockLedger[$productId] = max(0, (int) $product['stock']);
            $candidates[] = [
                'line_type' => 'product',
                'record' => $product,
                'type' => $item['type'],
                'quantity' => $item['quantity'],
                'requirements' => [$productId => $isPack ? $packSize : 1],
            ];
            continue;
        }

        $comboQuery->execute([$item['id']]);
        $combo = $comboQuery->fetch();
        if (!$combo || (int) $combo['active'] !== 1 || (float) $combo['price'] <= 0 || is_age_restricted_product($combo)) continue;
        $comboComponentsQuery->execute([$item['id']]);
        $components = $comboComponentsQuery->fetchAll();
        $comboComponentCountQuery->execute([$item['id']]);
        $configuredComponentCount = (int) $comboComponentCountQuery->fetchColumn();
        if (!$components || count($components) !== $configuredComponentCount) continue;

        $valid = true;
        $requirements = [];
        foreach ($components as $component) {
            $componentUnits = (int) $component['quantity'];
            if ($componentUnits <= 0 || (int) $component['restricted'] !== 0 || is_age_restricted_product($component)) {
                $valid = false;
                break;
            }
            $productId = (int) $component['id'];
            $requirements[$productId] = ($requirements[$productId] ?? 0) + $componentUnits;
            $stockLedger[$productId] = max(0, (int) $component['stock']);
        }
        if (!$valid || !$requirements) continue;
        $candidate = [
            'line_type' => 'combo',
            'record' => $combo,
            'quantity' => $item['quantity'],
            'requirements' => $requirements,
        ];
        if ($combo['stock'] !== null) {
            $candidate['line_stock_limit'] = max(0, (int) $combo['stock']);
        }
        $candidates[] = $candidate;
    }

    $allocation = cart_reconciliation_allocate_candidates($candidates, $stockLedger);
    $canonical = [];
    foreach ($allocation['items'] as $candidate) {
        if ($candidate['line_type'] === 'product') {
            $canonical[] = cart_reconciliation_product_payload(
                $candidate['record'],
                $candidate['type'],
                $candidate['quantity'],
                $candidate['available']
            );
            continue;
        }
        $canonical[] = cart_reconciliation_combo_payload(
            $candidate['record'],
            $candidate['quantity'],
            $candidate['available']
        );
    }
    cart_reconcile_response(200, ['success' => true, 'items' => $canonical]);
} catch (Throwable $exception) {
    error_log('Public cart reconciliation failed: ' . $exception->getMessage());
    cart_reconcile_response(503, ['success' => false, 'message' => 'No se pudo verificar la lista.']);
}
