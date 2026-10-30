<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';
require_once __DIR__ . '/../includes/ai_recommendations.php';
require_admin();

$movementTypes = ['entrada', 'salida', 'ajuste_entrada', 'ajuste_salida'];
$paymentMethods = ['efectivo', 'yape', 'plin', 'tarjeta', 'transferencia', 'otro'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $productId = max(0, (int) ($_POST['product_id'] ?? 0));
    $type = (string) ($_POST['movement_type'] ?? '');
    $presentation = (string) ($_POST['presentation'] ?? 'unidad');
    $quantityRaw = trim((string) ($_POST['quantity'] ?? ''));
    $costRaw = trim((string) ($_POST['unit_cost'] ?? '0'));
    $priceRaw = trim((string) ($_POST['sale_price'] ?? '0'));
    $paymentMethod = (string) ($_POST['payment_method'] ?? 'efectivo');
    $reference = mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 100);
    $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 500);
    $movedAt = valid_datetime_local((string) ($_POST['moved_at'] ?? ''));
    $errors = [];

    if ($productId <= 0) $errors[] = 'Selecciona un producto.';
    if (!in_array($type, $movementTypes, true)) $errors[] = 'Selecciona un tipo de movimiento válido.';
    if (!in_array($presentation, ['unidad', 'paquete'], true)) $errors[] = 'Selecciona una presentación válida.';
    if (filter_var($quantityRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) === false) $errors[] = 'La cantidad debe ser un entero mayor que cero.';
    if (!is_numeric($costRaw) || (float) $costRaw < 0 || (float) $costRaw > 999999.99) $errors[] = 'Ingresa un costo válido.';
    if (!is_numeric($priceRaw) || (float) $priceRaw < 0 || (float) $priceRaw > 999999.99) $errors[] = 'Ingresa un precio de venta válido.';
    if (!in_array($paymentMethod, $paymentMethods, true)) $errors[] = 'Selecciona un método de pago válido.';
    if ($movedAt === null) $errors[] = 'Ingresa una fecha y hora válidas.';

    if ($errors) {
        $_SESSION['movement_old'] = $_POST;
        flash('danger', implode(' ', $errors));
        redirect('admin/movimientos.php');
    }

    $connection = db();
    $connection->beginTransaction();
    try {
        $productStatement = $connection->prepare("SELECT * FROM products WHERE id = :id AND catalog_scope = 'master' FOR UPDATE");
        $productStatement->execute(['id' => $productId]);
        $product = $productStatement->fetch();
        if (!$product) {
            throw new RuntimeException('El producto seleccionado ya no existe.');
        }

        $packSize = $presentation === 'paquete' ? (int) $product['units_per_pack'] : 1;
        if ($presentation === 'paquete' && $packSize <= 1) {
            throw new RuntimeException('Este producto no tiene configurada una presentación por paquete.');
        }

        $quantity = (int) $quantityRaw;
        $unitsChanged = $quantity * $packSize;
        $isIncoming = in_array($type, ['entrada', 'ajuste_entrada'], true);
        if ($type === 'salida' && (is_age_restricted_product($product) || !sale_eligible_product($product, $unitsChanged))) {
            throw new RuntimeException('El producto no está habilitado para venta o no tiene stock suficiente.');
        }
        $newStock = (int) $product['stock'] + ($isIncoming ? $unitsChanged : -$unitsChanged);
        if ($newStock < 0) {
            throw new RuntimeException('Stock insuficiente. Hay ' . (int) $product['stock'] . ' unidades disponibles.');
        }

        $inputCost = (float) $costRaw;
        $inputSalePrice = (float) $priceRaw;
        $unitCost = 0.0;
        $salePrice = 0.0;
        $totalCost = 0.0;
        $totalIncome = 0.0;
        $profit = 0.0;

        if ($type === 'entrada') {
            $unitCost = $inputCost;
            $totalCost = $inputCost * $quantity;
        } elseif ($type === 'salida') {
            $unitCost = (float) $product['cost_price'] * $packSize;
            $salePrice = $presentation === 'paquete' && (float) $product['pack_price'] > 0
                ? (float) $product['pack_price']
                : (float) $product['price'];
            $totalCost = (float) $product['cost_price'] * $unitsChanged;
            $totalIncome = $salePrice * $quantity;
            $profit = $totalIncome - $totalCost;
        }

        $newCostPrice = (float) $product['cost_price'];
        if ($type === 'entrada' && $unitsChanged > 0) {
            $previousValue = (float) $product['cost_price'] * (int) $product['stock'];
            $newCostPrice = ($previousValue + $totalCost) / max(1, $newStock);
        }

        $update = $connection->prepare("UPDATE products SET stock = :stock, cost_price = :cost_price WHERE id = :id AND catalog_scope = 'master'");
        $update->execute(['stock' => $newStock, 'cost_price' => number_format($newCostPrice, 2, '.', ''), 'id' => $productId]);

        $insert = $connection->prepare(
            'INSERT INTO inventory_movements
             (product_id, movement_type, presentation, quantity, units_changed, unit_cost, sale_price, total_cost, total_income, profit, payment_method, reference, notes, moved_at, created_by)
             VALUES (:product_id, :movement_type, :presentation, :quantity, :units_changed, :unit_cost, :sale_price, :total_cost, :total_income, :profit, :payment_method, :reference, :notes, :moved_at, :created_by)'
        );
        $insert->execute([
            'product_id' => $productId,
            'movement_type' => $type,
            'presentation' => $presentation,
            'quantity' => $quantity,
            'units_changed' => $unitsChanged,
            'unit_cost' => number_format($unitCost, 2, '.', ''),
            'sale_price' => number_format($salePrice, 2, '.', ''),
            'total_cost' => number_format($totalCost, 2, '.', ''),
            'total_income' => number_format($totalIncome, 2, '.', ''),
            'profit' => number_format($profit, 2, '.', ''),
            'payment_method' => $paymentMethod,
            'reference' => $reference !== '' ? $reference : null,
            'notes' => $notes !== '' ? $notes : null,
            'moved_at' => $movedAt,
            'created_by' => $_SESSION['admin_id'],
        ]);

        $connection->commit();
        unset($_SESSION['movement_old']);
        flash('success', 'Movimiento registrado. El stock y los resultados financieros fueron actualizados.');
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        $_SESSION['movement_old'] = $_POST;
        flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo registrar el movimiento.');
    }

    redirect('admin/movimientos.php');
}

$old = $_SESSION['movement_old'] ?? [];
unset($_SESSION['movement_old']);
$selectedProductId = max(0, (int) ($old['product_id'] ?? 0));
$selectedProduct = null;
if ($selectedProductId > 0) {
    $productStatement = db()->prepare(
        "SELECT id, code, name, stock, cost_price, price, units_per_pack, pack_price
         FROM products WHERE catalog_scope = 'master' AND id = :id LIMIT 1"
    );
    $productStatement->execute(['id' => $selectedProductId]);
    $selectedProduct = $productStatement->fetch() ?: null;
}

$filterType = (string) ($_GET['type'] ?? '');
$filterPayment = (string) ($_GET['payment'] ?? '');
$filterFrom = (string) ($_GET['from'] ?? '');
$filterTo = (string) ($_GET['to'] ?? '');
$where = [];
$parameters = [];
if (in_array($filterType, $movementTypes, true)) { $where[] = 'm.movement_type = :type'; $parameters['type'] = $filterType; }
if (in_array($filterPayment, $paymentMethods, true)) { $where[] = 'm.payment_method = :payment'; $parameters['payment'] = $filterPayment; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) { $where[] = 'm.moved_at >= :date_from'; $parameters['date_from'] = $filterFrom . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) { $where[] = 'm.moved_at <= :date_to'; $parameters['date_to'] = $filterTo . ' 23:59:59'; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$historyStatement = db()->prepare(
    'SELECT m.*, p.code, p.name AS product_name
     FROM inventory_movements m INNER JOIN products p ON p.id = m.product_id AND p.catalog_scope = \'master\'' . $whereSql . '
     ORDER BY m.moved_at DESC, m.id DESC LIMIT 200'
);
$historyStatement->execute($parameters);
$movements = $historyStatement->fetchAll();

$summaryStatement = db()->prepare(
    "SELECT COALESCE(SUM(CASE WHEN movement_type = 'entrada' THEN total_cost ELSE 0 END),0) AS purchases,
            COALESCE(SUM(CASE WHEN movement_type = 'salida' THEN total_cost ELSE 0 END),0) AS cost_sold,
            COALESCE(SUM(total_income),0) AS income,
            COALESCE(SUM(profit),0) AS profit, COUNT(*) AS movements
     FROM inventory_movements m INNER JOIN products p ON p.id = m.product_id AND p.catalog_scope = 'master'" . $whereSql
);
$summaryStatement->execute($parameters);
$summary = $summaryStatement->fetch();

$pageTitle = 'Entradas y salidas';
$pageSubtitle = 'Compras, ventas, gastos, ingresos y ganancias';
$activePage = 'movements';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="metric-grid metric-grid-five finance-metrics">
    <article class="metric-card metric-blue"><div><span>Movimientos</span><strong><?= (int) $summary['movements'] ?></strong><small>Según los filtros</small></div><span class="metric-icon">↕</span></article>
    <article class="metric-card metric-orange"><div><span>Gastos en compras</span><strong><?= money($summary['purchases']) ?></strong><small>Dinero usado para reponer</small></div><span class="metric-icon">−</span></article>
    <article class="metric-card metric-pink"><div><span>Costo vendido</span><strong><?= money($summary['cost_sold']) ?></strong><small>Costo de productos vendidos</small></div><span class="metric-icon">C</span></article>
    <article class="metric-card metric-green"><div><span>Ingresos</span><strong><?= money($summary['income']) ?></strong><small>Ventas registradas</small></div><span class="metric-icon">＋</span></article>
    <article class="metric-card metric-purple"><div><span>Ganancia</span><strong><?= money($summary['profit']) ?></strong><small>Ingreso menos costo</small></div><span class="metric-icon">S/</span></article>
</section>

<section class="panel movement-form-panel">
    <div class="panel-heading"><div><h2>Registrar movimiento</h2><p>El stock se actualiza automáticamente en unidades individuales.</p></div></div>
    <form method="post" class="movement-form" data-movement-form>
        <?= csrf_field() ?>
        <label class="form-group field-span-2"><span>Producto <b>*</b></span>
            <input type="search" autocomplete="off" placeholder="Buscar nombre, código o EAN" data-admin-product-search data-product-mode="movement" data-product-search-url="<?= e(url('api/admin_product_search.php')) ?>" data-product-results="#movementProductResults" data-product-selected="#movementProductSelected">
            <div id="movementProductResults" class="picker-grid" aria-live="polite"></div>
            <small id="movementProductSelected"><?= $selectedProduct ? e($selectedProduct['code'] . ' · ' . $selectedProduct['name']) : 'Escribe al menos 2 caracteres para buscar.' ?></small>
            <select name="product_id" id="movementProduct" required>
                <option value="">Selecciona un producto con el buscador</option>
                <?php if ($selectedProduct): ?>
                    <option value="<?= (int) $selectedProduct['id'] ?>" data-stock="<?= (int) $selectedProduct['stock'] ?>" data-cost="<?= e($selectedProduct['cost_price']) ?>" data-price="<?= e($selectedProduct['price']) ?>" data-pack-size="<?= (int) $selectedProduct['units_per_pack'] ?>" data-pack-price="<?= e($selectedProduct['pack_price']) ?>" selected><?= e($selectedProduct['code'] . ' · ' . $selectedProduct['name'] . ' (' . $selectedProduct['stock'] . ' und.)') ?></option>
                <?php endif; ?>
            </select>
        </label>
        <label class="form-group"><span>Tipo <b>*</b></span><select name="movement_type" id="movementType" required>
            <option value="entrada" <?= ($old['movement_type'] ?? '') === 'entrada' ? 'selected' : '' ?>>Compra / entrada</option>
            <option value="salida" <?= ($old['movement_type'] ?? '') === 'salida' ? 'selected' : '' ?>>Venta / salida</option>
            <option value="ajuste_entrada" <?= ($old['movement_type'] ?? '') === 'ajuste_entrada' ? 'selected' : '' ?>>Ajuste positivo</option>
            <option value="ajuste_salida" <?= ($old['movement_type'] ?? '') === 'ajuste_salida' ? 'selected' : '' ?>>Ajuste negativo</option>
        </select></label>
        <label class="form-group"><span>Presentación <b>*</b></span><select name="presentation" id="movementPresentation" required><option value="unidad">Unidad</option><option value="paquete" <?= ($old['presentation'] ?? '') === 'paquete' ? 'selected' : '' ?>>Paquete / six pack</option></select></label>
        <label class="form-group"><span>Cantidad <b>*</b></span><input type="number" name="quantity" value="<?= e($old['quantity'] ?? 1) ?>" min="1" max="100000" required></label>
        <label class="form-group"><span>Costo por presentación</span><div class="input-prefix"><i>S/</i><input id="movementCost" type="number" name="unit_cost" value="<?= e($old['unit_cost'] ?? 0) ?>" min="0" step="0.01"></div><small>Para compras; en ventas se usa el costo registrado.</small></label>
        <label class="form-group"><span>Precio de venta por presentación</span><div class="input-prefix"><i>S/</i><input id="movementSalePrice" type="number" name="sale_price" value="<?= e($old['sale_price'] ?? 0) ?>" min="0" step="0.01" readonly></div><small>En las ventas se usa siempre el precio vigente de la base de datos.</small></label>
        <label class="form-group"><span>Método de pago</span><select name="payment_method"><?php foreach ($paymentMethods as $method): ?><option value="<?= e($method) ?>" <?= ($old['payment_method'] ?? '') === $method ? 'selected' : '' ?>><?= e(payment_label($method)) ?></option><?php endforeach; ?></select></label>
        <label class="form-group"><span>Fecha y hora <b>*</b></span><input type="datetime-local" name="moved_at" value="<?= e($old['moved_at'] ?? date('Y-m-d\TH:i')) ?>" required></label>
        <label class="form-group"><span>Referencia</span><input name="reference" value="<?= e($old['reference'] ?? '') ?>" maxlength="100" placeholder="Boleta, proveedor o pedido"></label>
        <label class="form-group field-span-2"><span>Nota</span><textarea name="notes" rows="2" maxlength="500" placeholder="Detalle opcional"><?= e($old['notes'] ?? '') ?></textarea></label>
        <div class="movement-live-summary field-span-2" id="movementLiveSummary">Selecciona producto, tipo y cantidad para ver el cálculo.</div>
        <button class="btn btn-primary field-span-2" type="submit">Registrar y actualizar stock →</button>
    </form>
</section>

<section class="panel movement-history-panel">
    <div class="panel-heading"><div><h2>Historial de operaciones</h2><p>Hasta 200 movimientos recientes con fecha y hora.</p></div></div>
    <form method="get" class="admin-filters movement-filters">
        <select name="type"><option value="">Todos los movimientos</option><?php foreach ($movementTypes as $type): ?><option value="<?= e($type) ?>" <?= $filterType === $type ? 'selected' : '' ?>><?= e(movement_label($type)) ?></option><?php endforeach; ?></select>
        <select name="payment"><option value="">Todos los pagos</option><?php foreach ($paymentMethods as $method): ?><option value="<?= e($method) ?>" <?= $filterPayment === $method ? 'selected' : '' ?>><?= e(payment_label($method)) ?></option><?php endforeach; ?></select>
        <input type="date" name="from" value="<?= e($filterFrom) ?>" aria-label="Desde"><input type="date" name="to" value="<?= e($filterTo) ?>" aria-label="Hasta">
        <button class="btn btn-secondary" type="submit">Filtrar</button><a class="clear-filter" href="<?= url('admin/movimientos.php') ?>">Limpiar</a>
    </form>
    <div class="table-wrap"><table class="data-table movement-table"><thead><tr><th>Fecha y hora</th><th>Producto</th><th>Movimiento</th><th>Cantidad</th><th>Pago</th><th>Gasto</th><th>Ingreso</th><th>Ganancia</th></tr></thead><tbody>
        <?php foreach ($movements as $movement): ?>
            <tr><td><strong><?= date('d/m/Y', strtotime($movement['moved_at'])) ?></strong><small class="table-subline"><?= date('H:i', strtotime($movement['moved_at'])) ?></small></td><td><strong><?= e($movement['product_name']) ?></strong><small class="table-subline"><?= e($movement['code']) ?></small></td><td><span class="status-pill status-<?= e(movement_class($movement['movement_type'])) ?>"><i></i><?= e(movement_label($movement['movement_type'])) ?></span></td><td><?= (int) $movement['quantity'] ?> <?= $movement['presentation'] === 'paquete' ? 'paq.' : 'und.' ?><small class="table-subline"><?= (int) $movement['units_changed'] ?> unidades</small></td><td><?= e(payment_label($movement['payment_method'])) ?><small class="table-subline"><?= e($movement['reference'] ?: 'Sin referencia') ?></small></td><td><?= money($movement['total_cost']) ?></td><td><?= money($movement['total_income']) ?></td><td><strong class="<?= (float) $movement['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($movement['profit']) ?></strong></td></tr>
        <?php endforeach; ?>
        <?php if (!$movements): ?><tr><td colspan="8"><div class="empty-mini">No hay movimientos para los filtros seleccionados.</div></td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
