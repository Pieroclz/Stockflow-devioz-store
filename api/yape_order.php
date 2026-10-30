<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';
require_once __DIR__ . '/../includes/ai_recommendations.php';
require_once __DIR__ . '/../includes/yape_order_snapshots.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function yape_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

final class SaleEligibilityException extends RuntimeException
{
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    yape_response(405, ['success' => false, 'message' => 'Método no permitido.']);
}

$lastSubmission = (int) ($_SESSION['last_order_submission'] ?? 0);
if ($lastSubmission > 0 && time() - $lastSubmission < 12) {
    yape_response(429, ['success' => false, 'message' => 'Espera unos segundos antes de registrar otro pedido.']);
}

$token = (string) ($_POST['csrf_token'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    yape_response(419, ['success' => false, 'message' => 'La sesión expiró. Actualiza la página e inténtalo nuevamente.']);
}

$paymentMethod = (string) ($_POST['metodo_pago'] ?? '');
$pagaConRaw = trim((string) ($_POST['paga_con'] ?? ''));
$customerName = mb_substr(trim((string) ($_POST['cliente'] ?? '')), 0, 120);
$customerPhone = mb_substr(preg_replace('/[^0-9+]/', '', (string) ($_POST['telefono'] ?? '')) ?? '', 0, 20);
$deliveryZoneId = max(0, (int) ($_POST['delivery_zone_id'] ?? 0));
$deliveryAddress = mb_substr(trim((string) ($_POST['delivery_address'] ?? '')), 0, 255);
$deliveryReference = mb_substr(trim((string) ($_POST['delivery_reference'] ?? '')), 0, 255);
$couponCode = strtoupper(mb_substr(trim((string) ($_POST['coupon'] ?? '')), 0, 40));
$customerId = customer_id() ?: null;

if (mb_strlen($customerName) < 3 || strlen(preg_replace('/\D/', '', $customerPhone) ?? '') < 7) {
    yape_response(422, ['success' => false, 'message' => 'Completa tu nombre y un número de celular válido.']);
}

if (!in_array($paymentMethod, ['efectivo', 'yape', 'plin'], true) || !payment_method_available($paymentMethod, payment_settings())) {
    yape_response(422, ['success' => false, 'message' => 'Selecciona un método de pago válido.']);
}

if ($paymentMethod === 'yape' || $paymentMethod === 'plin') {
    if (!isset($_FILES['receipt']) || !is_array($_FILES['receipt'])) {
        yape_response(422, ['success' => false, 'message' => 'Adjunta la captura del pago.']);
    }
}

$itemsJson = (string) ($_POST['items'] ?? $_POST['cart'] ?? '');
$items = json_decode($itemsJson, true);
if (!is_array($items) || !$items || count($items) > 40) {
    yape_response(422, ['success' => false, 'message' => 'Revisa los productos del carrito.']);
}

usort($items, static function (mixed $left, mixed $right): int {
    $leftKind = is_array($left) && ($left['kind'] ?? 'product') === 'combo' ? 'combo' : 'product';
    $rightKind = is_array($right) && ($right['kind'] ?? 'product') === 'combo' ? 'combo' : 'product';
    $leftId = is_array($left) ? (int) ($left['producto_id'] ?? $left['id'] ?? 0) : 0;
    $rightId = is_array($right) ? (int) ($right['producto_id'] ?? $right['id'] ?? 0) : 0;
    return strcmp($leftKind, $rightKind) ?: ($leftId <=> $rightId);
});

$connection = db();

try {
    $connection->beginTransaction();

    $productQuery = $connection->prepare(
        'SELECT p.id, p.name, p.category, p.description, p.price, p.cost_price, p.stock, p.units_per_pack, p.pack_price,
                p.active, p.sale_enabled, p.restricted, p.catalog_scope, p.entrega_inmediata
         FROM products p WHERE p.id = ? AND ' . master_product_visibility_sql('p') . ' LIMIT 1 FOR UPDATE'
    );
    $comboQuery = $connection->prepare(
        'SELECT c.id, c.name, c.description, c.price, c.stock, c.active FROM combos c
         WHERE c.id = ? AND c.active = 1 AND c.price > 0 AND '
         . public_combo_text_visibility_sql('c') . ' LIMIT 1 FOR UPDATE'
    );
    $comboComponentsQuery = $connection->prepare(
        'SELECT p.id, p.name, p.category, p.description, p.price, p.cost_price, p.stock, p.active,
                p.sale_enabled, p.restricted, p.catalog_scope, p.entrega_inmediata, ci.quantity
         FROM combo_items ci
         INNER JOIN products p ON p.id = ci.product_id
         WHERE ci.combo_id = ? AND ci.quantity > 0 AND ' . master_product_visibility_sql('p') . '
           AND ' . public_product_text_visibility_sql('p') . '
         ORDER BY p.id FOR UPDATE'
    );
    $comboComponentCountQuery = $connection->prepare('SELECT COUNT(*) FROM combo_items WHERE combo_id = ?');
    $updateStock = $connection->prepare("UPDATE products SET stock = ? WHERE id = ? AND catalog_scope = 'master'");
    $updateComboStock = $connection->prepare('UPDATE combos SET stock = ? WHERE id = ?');

    $itemsValidados = [];
    $expectedAmount = 0.0;
    $unidadesFisicasDespacho = 0;
    $todoEntregaInmediata = true;

    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new RuntimeException('El carrito contiene datos inválidos.');
        }

        $kind = ($item['kind'] ?? 'product') === 'combo' ? 'combo' : 'product';
        $id = max(0, (int) ($item['producto_id'] ?? $item['id'] ?? 0));
        $quantity = max(0, (int) ($item['cantidad'] ?? $item['quantity'] ?? 0));
        if ($id <= 0 || $quantity <= 0 || $quantity > 99) {
            throw new RuntimeException('Una cantidad del carrito no es válida.');
        }

        if ($kind === 'product') {
            $productQuery->execute([$id]);
            $record = $productQuery->fetch();
            $packSize = max(1, (int) ($record['units_per_pack'] ?? 1));
            $requestedPack = ($item['presentation'] ?? '') === 'paquete';
            $isPack = $requestedPack
                && $packSize > 1
                && (float) ($record['pack_price'] ?? 0) > 0;
            if ($requestedPack && !$isPack) {
                throw new SaleEligibilityException('La presentación por paquete ya no está disponible. Actualiza el carrito.');
            }
            $presentation = $isPack ? 'paquete' : 'unidad';
            $unitsPerItem = $isPack ? $packSize : 1;
            $unitsChanged = $quantity * $unitsPerItem;
            if (!$record || is_age_restricted_product($record) || !sale_eligible_product($record, $unitsChanged)) {
                throw new SaleEligibilityException('Un producto ya no está publicado, está restringido o no tiene stock suficiente. Actualiza el carrito.');
            }
            $stockBefore = max(0, (int) $record['stock']);
            if ($unitsChanged > $stockBefore) {
                throw new RuntimeException('No hay stock suficiente de ' . (string) $record['name'] . '.');
            }

            $stockAfter = $stockBefore - $unitsChanged;
            $unitPrice = round($isPack ? (float) $record['pack_price'] : (float) $record['price'], 2);
            $singleUnitCost = round((float) $record['cost_price'], 2);
            $unitCost = round($singleUnitCost * $unitsPerItem, 2);
            $subtotal = round($unitPrice * $quantity, 2);
            $totalCost = round($singleUnitCost * $unitsChanged, 2);
            $profit = round($subtotal - $totalCost, 2);
            $updateStock->execute([$stockAfter, $id]);

            $itemsValidados[] = [
                'type' => 'product',
                'id' => $id,
                'name' => (string) $record['name'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'subtotal' => $subtotal,
                'profit' => $profit,
                'presentation' => $presentation,
                'units_per_item' => $unitsPerItem,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'entrega_inmediata' => (int) $record['entrega_inmediata'],
                'movements' => [[
                    'product_id' => $id,
                    'presentation' => $presentation,
                    'quantity' => $quantity,
                    'units_changed' => $unitsChanged,
                    'unit_cost' => $singleUnitCost,
                    'sale_price' => $unitPrice,
                    'total_cost' => $totalCost,
                    'total_income' => $subtotal,
                    'profit' => $profit,
                    'notes' => 'Venta registrada desde pedido online',
                ]],
            ];
            $expectedAmount += $subtotal;
            $unidadesFisicasDespacho += $unitsChanged;
            $todoEntregaInmediata = $todoEntregaInmediata && (int) $record['entrega_inmediata'] === 1;
            continue;
        }

        $comboQuery->execute([$id]);
        $combo = $comboQuery->fetch();
        if (!$combo || (int) $combo['active'] !== 1 || (float) $combo['price'] <= 0 || is_age_restricted_product($combo)) {
            throw new SaleEligibilityException('Un combo ya no está publicado o está restringido. Actualiza el carrito.');
        }
        $manualComboStock = $combo['stock'] === null ? null : max(0, (int) $combo['stock']);
        if ($manualComboStock !== null && $quantity > $manualComboStock) {
            throw new SaleEligibilityException('No hay suficientes unidades disponibles de ' . (string) $combo['name'] . '.');
        }
        $comboComponentsQuery->execute([$id]);
        $components = $comboComponentsQuery->fetchAll();
        $comboComponentCountQuery->execute([$id]);
        $configuredComponentCount = (int) $comboComponentCountQuery->fetchColumn();
        if (!$components || count($components) !== $configuredComponentCount) {
            throw new SaleEligibilityException('El combo ya no tiene productos maestros disponibles.');
        }

        $comboCost = 0.0;
        $regularValue = 0.0;
        $comboStockBefore = PHP_INT_MAX;
        $comboImmediate = 1;
        $comboPhysicalUnits = 0;
        foreach ($components as $component) {
            $componentUnits = max(1, (int) $component['quantity']);
            $required = $componentUnits * $quantity;
            if (is_age_restricted_product($component) || !sale_eligible_product($component, $required)) {
                throw new SaleEligibilityException('El combo contiene un producto no publicado, restringido o sin stock suficiente.');
            }
            $comboCost += (float) $component['cost_price'] * $componentUnits;
            $regularValue += (float) $component['price'] * $componentUnits;
            $comboStockBefore = min($comboStockBefore, intdiv((int) $component['stock'], $componentUnits));
            $comboImmediate = min($comboImmediate, (int) $component['entrega_inmediata']);
            $comboPhysicalUnits += $required;
        }
        if ($manualComboStock !== null) {
            $comboStockBefore = min($comboStockBefore, $manualComboStock);
            $updateComboStock->execute([$manualComboStock - $quantity, $id]);
        }

        $unitPrice = round((float) $combo['price'], 2);
        $subtotal = round($unitPrice * $quantity, 2);
        $comboTotalCost = round($comboCost * $quantity, 2);
        $profit = round($subtotal - $comboTotalCost, 2);
        $movements = [];
        $snapshotComponents = [];
        $remainingIncome = round($subtotal, 2);
        $componentCount = count($components);
        foreach ($components as $componentIndex => $component) {
            $componentUnits = max(1, (int) $component['quantity']);
            $required = $componentUnits * $quantity;
            $stockAfter = (int) $component['stock'] - $required;
            $weight = $regularValue > 0
                ? ((float) $component['price'] * $componentUnits) / $regularValue
                : 1 / count($components);
            $lineIncome = $componentIndex === count($components) - 1
                ? $remainingIncome
                : round($subtotal * $weight, 2);
            $lineCost = round((float) $component['cost_price'] * $required, 2);
            $lineProfit = round($lineIncome - $lineCost, 2);
            $remainingIncome = round($remainingIncome - $lineIncome, 2);
            $updateStock->execute([$stockAfter, (int) $component['id']]);
            $movements[] = [
                'product_id' => (int) $component['id'],
                'presentation' => 'unidad',
                'quantity' => $required,
                'units_changed' => $required,
                'unit_cost' => round((float) $component['cost_price'], 2),
                'sale_price' => $required > 0 ? round($lineIncome / $required, 2) : 0.0,
                'total_cost' => $lineCost,
                'total_income' => $lineIncome,
                'profit' => $lineProfit,
                'notes' => 'Producto vendido dentro de combo online',
            ];
            $snapshotComponents[] = [
                'product_id' => (int) $component['id'],
                'product_name' => (string) $component['name'],
                'units_per_combo' => $componentUnits,
                'reserved_units' => $required,
                'unit_cost' => round((float) $component['cost_price'], 2),
                'reference_unit_price' => round((float) $component['price'], 2),
                'allocated_income' => $lineIncome,
                'total_cost' => $lineCost,
                'profit' => $lineProfit,
            ];
        }

        $itemsValidados[] = [
            'type' => 'combo',
            'id' => $id,
            'name' => (string) $combo['name'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost' => round($comboCost, 2),
            'subtotal' => $subtotal,
            'profit' => $profit,
            'presentation' => 'unidad',
            'units_per_item' => 1,
            'stock_before' => $comboStockBefore,
            'stock_after' => max(0, $comboStockBefore - $quantity),
            'entrega_inmediata' => $comboImmediate,
            'manual_combo_stock_reserved' => $manualComboStock === null ? 0 : 1,
            'snapshot_components' => $snapshotComponents,
            'movements' => $movements,
        ];
        $expectedAmount += $subtotal;
        $unidadesFisicasDespacho += $comboPhysicalUnits;
        $todoEntregaInmediata = $todoEntregaInmediata && $comboImmediate === 1;
    }

    $subtotalAmount = round($expectedAmount, 2);
    $zoneStatement = $connection->prepare($deliveryZoneId > 0
        ? 'SELECT id,district,delivery_fee,estimated_minutes FROM delivery_zones WHERE id=? AND active=1 FOR UPDATE'
        : "SELECT id,district,delivery_fee,estimated_minutes FROM delivery_zones WHERE district='Recojo en tienda' AND active=1 FOR UPDATE");
    $zoneStatement->execute($deliveryZoneId > 0 ? [$deliveryZoneId] : []);
    $deliveryZone = $zoneStatement->fetch();
    if (!$deliveryZone) throw new RuntimeException('No hay una opción de recojo o envío disponible.');
    $deliveryZoneId = (int) $deliveryZone['id'];
    if ((string)$deliveryZone['district'] !== 'Recojo en tienda' && mb_strlen($deliveryAddress) < 5) {
        throw new RuntimeException('Completa la dirección de entrega.');
    }
    $deliveryFee = round((float)$deliveryZone['delivery_fee'], 2);
    $couponId = null;
    $discountAmount = 0.0;
    if ($couponCode !== '') {
        $couponStatement = $connection->prepare("SELECT * FROM coupons WHERE code=? AND active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) AND (max_uses IS NULL OR used_count<max_uses) LIMIT 1 FOR UPDATE");
        $couponStatement->execute([$couponCode]);
        $coupon = $couponStatement->fetch();
        if (!$coupon || $subtotalAmount < (float)$coupon['minimum_amount']) throw new RuntimeException('El cupón no es válido o no cumple el monto mínimo.');
        $couponId = (int)$coupon['id'];
        $discountAmount = $coupon['discount_type'] === 'percent'
            ? round($subtotalAmount * min(100, (float)$coupon['discount_value']) / 100, 2)
            : min($subtotalAmount, round((float)$coupon['discount_value'], 2));
    }
    // Aplicar envío gratuito únicamente al cupón válido del 100 %.
    if ($couponId !== null && $couponCode === 'DEVIOZGRATIS100'
        && $coupon['discount_type'] === 'percent'
        && (float) $coupon['discount_value'] >= 100) {
        $discountAmount = round($subtotalAmount + $deliveryFee, 2);
    }
    $expectedAmount = max(0, round($subtotalAmount - $discountAmount + $deliveryFee, 2));
    $pagaCon = null;
    $vuelto = 0.00;
    if ($paymentMethod === 'efectivo') {
        if ($expectedAmount > 0 && ($pagaConRaw === '' || !is_numeric($pagaConRaw))) {
            throw new RuntimeException('Indica con cuánto vas a pagar.');
        }
        $pagaCon = $expectedAmount <= 0 ? 0.0 : round((float) $pagaConRaw, 2);
        if ($pagaCon < $expectedAmount) {
            throw new RuntimeException('El monto con el que pagas no puede ser menor al total.');
        }
        if ($pagaCon > 999999.99) {
            throw new RuntimeException('El monto con el que pagas no es válido.');
        }
        $vuelto = round($pagaCon - $expectedAmount, 2);
    }

    // Flujo express: hasta 3 unidades físicas, máximo 3 líneas y todos los productos habilitados
    // para entrega inmediata. Así un chicle, una bebida o una compra pequeña no pasa por preparación.
    $tipoDespacho = ($todoEntregaInmediata
        && $unidadesFisicasDespacho > 0
        && $unidadesFisicasDespacho <= 3
        && count($itemsValidados) <= 3)
        ? 'entrega_rapida'
        : 'preparacion';

    $requiresPaymentReview = payment_requires_review($paymentMethod);
    $receiptUrl = $requiresPaymentReview
        ? store_uploaded_image($_FILES['receipt'], 'receipts')
        : '';
    $orderCode = strtoupper($paymentMethod) . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $operationCode = ($requiresPaymentReview ? 'CAP-' : 'EFE-') . strtoupper(bin2hex(random_bytes(6)));
    $trackingPin = (string) random_int(100000, 999999);
    $trackingPinHash = password_hash($trackingPin, PASSWORD_DEFAULT);
    $status = payment_initial_status($paymentMethod);
    $fulfillmentStatus = ($status === 'aprobado' && $tipoDespacho === 'entrega_rapida') ? 'listo' : 'recibido';
    $declaredAmount = $paymentMethod === 'efectivo' ? (float) $pagaCon : $expectedAmount;

    $insertOrder = $connection->prepare(
        "INSERT INTO yape_orders
         (customer_id, order_code, customer_name, customer_phone, metodo_pago, tipo_despacho,
          delivery_zone_id, delivery_address, delivery_reference, delivery_fee, coupon_id, coupon_code,
          discount_amount, operation_code, expected_amount, subtotal_amount, declared_amount,
          monto_paga_con, vuelto, receipt_url, status, tracking_pin_hash, fulfillment_status,
          reserved_until, inventory_snapshot_version, status_updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 2, NOW())"
    );
    $insertOrder->execute([
        $customerId,
        $orderCode,
        $customerName,
        $customerPhone,
        $paymentMethod,
        $tipoDespacho,
        $deliveryZoneId,
        $deliveryAddress,
        $deliveryReference,
        $deliveryFee,
        $couponId,
        $couponId ? $couponCode : null,
        $discountAmount,
        $operationCode,
        $expectedAmount,
        $subtotalAmount,
        $declaredAmount,
        $pagaCon,
        $vuelto,
        $receiptUrl,
        $status,
        $trackingPinHash,
        $fulfillmentStatus,
    ]);
    $orderId = (int) $connection->lastInsertId();

    if ($couponId && $status === 'aprobado') {
        $connection->prepare('INSERT INTO coupon_usage(coupon_id,customer_id,order_id,discount_amount) VALUES(?,?,?,?)')->execute([$couponId,$customerId,$orderId,$discountAmount]);
        $connection->prepare('UPDATE coupons SET used_count=used_count+1 WHERE id=?')->execute([$couponId]);
    }

    $insertItem = $connection->prepare(
        'INSERT INTO yape_order_items
         (order_id, item_type, item_id, item_name, quantity, presentation, units_per_item,
          unit_price, unit_cost, subtotal, profit, stock_before, stock_after, manual_combo_stock_reserved)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertMovement = $connection->prepare(
        "INSERT INTO inventory_movements
         (product_id, movement_type, presentation, quantity, units_changed, unit_cost, sale_price,
          total_cost, total_income, profit, payment_method, reference, notes, created_by)
         VALUES (?, 'salida', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)"
    );

    foreach ($itemsValidados as $validatedItem) {
        $insertItem->execute([
            $orderId,
            $validatedItem['type'],
            $validatedItem['id'],
            $validatedItem['name'],
            $validatedItem['quantity'],
            $validatedItem['presentation'],
            $validatedItem['units_per_item'],
            $validatedItem['unit_price'],
            $validatedItem['unit_cost'],
            $validatedItem['subtotal'],
            $validatedItem['profit'],
            $validatedItem['stock_before'],
            $validatedItem['stock_after'],
            $validatedItem['manual_combo_stock_reserved'] ?? 0,
        ]);
        $orderItemId = (int) $connection->lastInsertId();
        if ($validatedItem['type'] === 'combo') {
            $insertComponent = $connection->prepare(
                'INSERT INTO yape_order_item_components
                 (order_item_id, product_id, product_name, units_per_combo, reserved_units,
                  unit_cost, reference_unit_price, allocated_income, total_cost, profit)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($validatedItem['snapshot_components'] as $component) {
                $insertComponent->execute([
                    $orderItemId,
                    $component['product_id'],
                    $component['product_name'],
                    $component['units_per_combo'],
                    $component['reserved_units'],
                    $component['unit_cost'],
                    $component['reference_unit_price'],
                    $component['allocated_income'],
                    $component['total_cost'],
                    $component['profit'],
                ]);
            }
        }
        foreach ($validatedItem['movements'] as $movement) {
            $insertMovement->execute([
                $movement['product_id'],
                $movement['presentation'],
                $movement['quantity'],
                $movement['units_changed'],
                $movement['unit_cost'],
                $movement['sale_price'],
                $movement['total_cost'],
                $movement['total_income'],
                $movement['profit'],
                $paymentMethod,
                $orderCode,
                $movement['notes'],
            ]);
            $connection->prepare("INSERT INTO stock_reservations(order_id,product_id,quantity,status,expires_at) VALUES(?,?,?, ?,DATE_ADD(NOW(),INTERVAL 30 MINUTE)) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity),status=VALUES(status),expires_at=VALUES(expires_at)")
                ->execute([$orderId,$movement['product_id'],$movement['units_changed'],$status==='aprobado'?'converted':'active']);
        }
    }

    $connection->commit();
    $_SESSION['last_order_submission'] = time();
    $_SESSION['customer_order_id'] = $orderId;

    // El pedido ya quedó guardado. Telegram es un aviso adicional y su caída
    // no afecta la compra ni revierte el movimiento de inventario.
    try {
        $telegramResult = send_telegram_order_notification(
            $orderCode,
            $itemsValidados,
            $expectedAmount,
            $paymentMethod,
            $tipoDespacho,
            $pagaCon,
            $vuelto
        );
        if (!$telegramResult['success'] && telegram_settings()['enabled']) {
            error_log('StockFlow Telegram: ' . $telegramResult['message']);
        }
    } catch (Throwable $telegramError) {
        error_log('StockFlow Telegram: no se pudo enviar el aviso adicional.');
    }
} catch (SaleEligibilityException $exception) {
    if ($connection->inTransaction()) $connection->rollBack();
    yape_response(409, ['success' => false, 'message' => $exception->getMessage()]);
} catch (RuntimeException $exception) {
    if ($connection->inTransaction()) $connection->rollBack();
    yape_response(422, ['success' => false, 'message' => $exception->getMessage()]);
} catch (PDOException $exception) {
    if ($connection->inTransaction()) $connection->rollBack();
    $duplicate = (string) $exception->getCode() === '23000';
    yape_response($duplicate ? 409 : 500, [
        'success' => false,
        'message' => $duplicate
            ? 'No se pudo generar el código del pedido. Inténtalo nuevamente.'
            : 'No se pudo guardar el pedido. Importa la migración actualizada e inténtalo nuevamente.',
    ]);
} catch (Throwable) {
    if ($connection->inTransaction()) $connection->rollBack();
    yape_response(500, ['success' => false, 'message' => 'No se pudo registrar el pedido. Inténtalo nuevamente.']);
}

yape_response(201, [
    'success' => true,
    'order_code' => $orderCode,
    'tracking_pin' => $trackingPin,
    'tracking_url' => url('mi_pedido.php'),
    'total' => $expectedAmount,
    'subtotal' => $subtotalAmount,
    'delivery_fee' => $deliveryFee,
    'discount_amount' => $discountAmount,
    'coupon_code' => $couponCode,
    'metodo_pago' => $paymentMethod,
    'paga_con' => $pagaCon,
    'vuelto' => $vuelto,
    'tipo_despacho' => $tipoDespacho,
    'dispatch_message' => $tipoDespacho === 'entrega_rapida'
        ? 'Pase directo a ventanilla rápida'
        : 'Pedido en cola de preparación',
    'status' => $status,
]);
