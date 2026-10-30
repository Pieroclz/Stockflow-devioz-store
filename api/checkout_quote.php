<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';
require_once __DIR__ . '/../includes/ai_recommendations.php';
require_once __DIR__ . '/../includes/public_cart_reconciliation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function checkout_quote_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    checkout_quote_response(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$token = (string) ($_POST['csrf_token'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    checkout_quote_response(419, ['success' => false, 'message' => 'La sesión expiró.']);
}

$rawItems = json_decode((string) ($_POST['items'] ?? ''), true);
$items = cart_reconciliation_request_items(is_array($rawItems) ? $rawItems : []);
$zoneId = max(0, (int) ($_POST['delivery_zone_id'] ?? 0));
$couponCode = strtoupper(mb_substr(trim((string) ($_POST['coupon'] ?? '')), 0, 40));
if (!$items || count($items) > 40) {
    checkout_quote_response(422, ['success' => false, 'message' => 'Revisa los productos del carrito.']);
}

usort($items, static fn (array $left, array $right): int => strcmp($left['type'], $right['type']) ?: ($left['id'] <=> $right['id']));
$connection = db();

try {
    $connection->beginTransaction();
    $productQuery = $connection->prepare(
        "SELECT id, name, category, description, price, cost_price, stock, units_per_pack, pack_price,
                active, sale_enabled, restricted, catalog_scope
         FROM products WHERE id = ? AND catalog_scope = 'master' LIMIT 1 FOR UPDATE"
    );
    $comboQuery = $connection->prepare(
        'SELECT id, name, description, price, stock, active FROM combos WHERE id = ? LIMIT 1 FOR UPDATE'
    );
    $comboComponentsQuery = $connection->prepare(
        "SELECT p.id, p.name, p.category, p.description, p.price, p.cost_price, p.stock,
                p.active, p.sale_enabled, p.restricted, p.catalog_scope, ci.quantity
         FROM combo_items ci INNER JOIN products p ON p.id = ci.product_id
         WHERE ci.combo_id = ? AND p.catalog_scope = 'master' ORDER BY p.id FOR UPDATE"
    );
    $comboComponentCountQuery = $connection->prepare('SELECT COUNT(*) FROM combo_items WHERE combo_id = ?');

    $remainingStock = [];
    $subtotal = 0.0;
    foreach ($items as $item) {
        $quantity = (int) $item['quantity'];
        if ($item['type'] === 'product' || $item['type'] === 'product_pack') {
            $productQuery->execute([(int) $item['id']]);
            $product = $productQuery->fetch();
            if (!$product || is_age_restricted_product($product)) {
                throw new RuntimeException('Un producto ya no está disponible. Actualiza el carrito.');
            }
            $isPack = $item['type'] === 'product_pack';
            $unitsPerItem = $isPack ? max(1, (int) $product['units_per_pack']) : 1;
            $required = $quantity * $unitsPerItem;
            $productId = (int) $product['id'];
            $remaining = $remainingStock[$productId] ?? max(0, (int) $product['stock']);
            $eligibilityRow = $product;
            $eligibilityRow['stock'] = $remaining;
            if (!sale_eligible_product($eligibilityRow, $required)) {
                throw new RuntimeException('Un producto ya no está publicado o no tiene stock suficiente.');
            }
            if ($isPack && ($unitsPerItem <= 1 || (float) $product['pack_price'] <= 0)) {
                throw new RuntimeException('La presentación por paquete ya no está disponible.');
            }
            $remainingStock[$productId] = $remaining - $required;
            $unitPrice = $isPack ? (float) $product['pack_price'] : (float) $product['price'];
            $subtotal += round($unitPrice, 2) * $quantity;
            continue;
        }

        $comboQuery->execute([(int) $item['id']]);
        $combo = $comboQuery->fetch();
        if (!$combo || (int) $combo['active'] !== 1 || (float) $combo['price'] <= 0 || is_age_restricted_product($combo)) {
            throw new RuntimeException('Un combo ya no está disponible. Actualiza el carrito.');
        }
        if ($combo['stock'] !== null && (int) $combo['stock'] < $quantity) {
            throw new RuntimeException('Un combo ya no tiene stock suficiente.');
        }
        $comboComponentsQuery->execute([(int) $item['id']]);
        $components = $comboComponentsQuery->fetchAll();
        $comboComponentCountQuery->execute([(int) $item['id']]);
        if (!$components || count($components) !== (int) $comboComponentCountQuery->fetchColumn()) {
            throw new RuntimeException('El combo contiene productos fuera del catálogo maestro.');
        }
        foreach ($components as $component) {
            $componentId = (int) $component['id'];
            $required = (int) $component['quantity'] * $quantity;
            $remaining = $remainingStock[$componentId] ?? max(0, (int) $component['stock']);
            $eligibilityRow = $component;
            $eligibilityRow['stock'] = $remaining;
            if (is_age_restricted_product($component) || !sale_eligible_product($eligibilityRow, $required)) {
                throw new RuntimeException('El combo contiene un producto no disponible para la venta.');
            }
            $remainingStock[$componentId] = $remaining - $required;
        }
        $subtotal += round((float) $combo['price'], 2) * $quantity;
    }

    $subtotal = round($subtotal, 2);
    if ($subtotal <= 0 || $subtotal > 999999.99) {
        throw new InvalidArgumentException('El subtotal no es válido.');
    }
    $zoneStatement = $connection->prepare($zoneId > 0
        ? 'SELECT id,district,delivery_fee,estimated_minutes FROM delivery_zones WHERE id=? AND active=1 FOR UPDATE'
        : "SELECT id,district,delivery_fee,estimated_minutes FROM delivery_zones WHERE district='Recojo en tienda' AND active=1 FOR UPDATE");
    $zoneStatement->execute($zoneId > 0 ? [$zoneId] : []);
    $zone = $zoneStatement->fetch();
    if (!$zone) throw new InvalidArgumentException('No hay una opción de recojo o envío disponible.');

    $discount = 0.0;
    $couponId = null;
    $couponMessage = '';
    if ($couponCode !== '') {
        $couponStatement = $connection->prepare("SELECT * FROM coupons WHERE code=? AND active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) AND (max_uses IS NULL OR used_count<max_uses) LIMIT 1 FOR UPDATE");
        $couponStatement->execute([$couponCode]);
        $coupon = $couponStatement->fetch();
        if (!$coupon || $subtotal < (float) $coupon['minimum_amount']) {
            $couponMessage = 'El cupón no es válido o no cumple el monto mínimo.';
        } else {
            $couponId = (int) $coupon['id'];
            $discount = $coupon['discount_type'] === 'percent'
                ? round($subtotal * min(100, (float) $coupon['discount_value']) / 100, 2)
                : min($subtotal, round((float) $coupon['discount_value'], 2));
            $couponMessage = 'Cupón aplicado: -' . money($discount);
        }
    }
    $delivery = round((float) $zone['delivery_fee'], 2);
    // Solo el cupón válido DEVIOZGRATIS100 cubre también el envío.
    if ($couponId !== null && $couponCode === 'DEVIOZGRATIS100'
        && $coupon['discount_type'] === 'percent'
        && (float) $coupon['discount_value'] >= 100) {
        $discount = round($subtotal + $delivery, 2);
        $couponMessage = 'Cupón aplicado: -' . money($discount);
    }
    $total = max(0, round($subtotal - $discount + $delivery, 2));
    $connection->commit();
} catch (InvalidArgumentException $exception) {
    if ($connection->inTransaction()) $connection->rollBack();
    checkout_quote_response(422, ['success' => false, 'message' => $exception->getMessage()]);
} catch (RuntimeException $exception) {
    if ($connection->inTransaction()) $connection->rollBack();
    checkout_quote_response(409, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    if ($connection->inTransaction()) $connection->rollBack();
    error_log('Checkout quote failed: ' . $exception->getMessage());
    checkout_quote_response(500, ['success' => false, 'message' => 'No se pudo calcular el total. Inténtalo nuevamente.']);
}

checkout_quote_response(200, [
    'success' => true,
    'subtotal' => $subtotal,
    'delivery_fee' => $delivery,
    'discount' => $discount,
    'total' => $total,
    'coupon_id' => $couponId,
    'coupon_code' => $couponId ? $couponCode : '',
    'message' => $couponMessage,
    'district' => $zone['district'],
    'estimated_minutes' => (int) $zone['estimated_minutes'],
]);
