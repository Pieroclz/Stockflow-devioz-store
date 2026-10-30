<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $enabled = isset($_POST['yape_enabled']) ? '1' : '0';
    $phone = preg_replace('/\D+/', '', (string) ($_POST['yape_phone'] ?? '')) ?: '';
    $owner = trim((string) ($_POST['yape_owner'] ?? ''));
    $whatsapp = preg_replace('/\D+/', '', (string) ($_POST['order_whatsapp'] ?? '')) ?: '';
    $telegramEnabled = isset($_POST['telegram_enabled']) ? '1' : '0';
    $telegramChatId = trim((string) ($_POST['telegram_chat_id'] ?? ''));
    $telegramTokenInput = trim((string) ($_POST['telegram_bot_token'] ?? ''));
    $telegramToken = $telegramTokenInput !== '' ? $telegramTokenInput : store_setting('telegram_bot_token');
    $action = (string) ($_POST['action'] ?? 'save');
    $errors = [];

    if ($phone !== '' && !preg_match('/^9\d{8}$/', $phone)) {
        $errors[] = 'El número de Yape debe tener 9 dígitos y comenzar con 9.';
    }
    if ($owner !== '' && (mb_strlen($owner) < 2 || mb_strlen($owner) > 100)) {
        $errors[] = 'El titular debe tener entre 2 y 100 caracteres.';
    }
    if ($whatsapp !== '' && !preg_match('/^(51)?9\d{8}$/', $whatsapp)) {
        $errors[] = 'WhatsApp debe tener 9 dígitos o incluir el código 51.';
    }
    $currentQr = store_setting('yape_qr');
    try {
        $uploadedQr = upload_image('yape_qr', 'payments');
        if ($uploadedQr !== null) $currentQr = $uploadedQr;
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
    if ($enabled === '1' && ($owner === '' || ($phone === '' && $currentQr === ''))) {
        $errors[] = 'Para activar Yape registra el titular y un número o código QR.';
    }
    if ($telegramTokenInput !== '' && !preg_match('/^\d{5,15}:[A-Za-z0-9_-]{20,}$/', $telegramTokenInput)) {
        $errors[] = 'El token del bot de Telegram no tiene un formato válido.';
    }
    if ($telegramChatId !== '' && !preg_match('/^-?\d{5,20}$/', $telegramChatId)) {
        $errors[] = 'El Chat ID de Telegram debe contener únicamente números.';
    }
    if ($telegramEnabled === '1' && ($telegramToken === '' || $telegramChatId === '')) {
        $errors[] = 'Para activar Telegram registra el token del bot y el Chat ID.';
    }

    if (!$errors) {
        save_store_setting('yape_enabled', $enabled);
        save_store_setting('yape_phone', $phone);
        save_store_setting('yape_owner', $owner);
        save_store_setting('order_whatsapp', $whatsapp);
        save_store_setting('yape_qr', $currentQr);
        save_store_setting('telegram_enabled', $telegramEnabled);
        save_store_setting('telegram_bot_token', $telegramToken);
        save_store_setting('telegram_chat_id', $telegramChatId);
        if ($action === 'test_telegram') {
            $testResult = send_telegram_message("✅ StockFlow conectado\nLas notificaciones de pedidos están funcionando correctamente.");
            flash($testResult['success'] ? 'success' : 'danger', $testResult['message']);
        } else {
            flash('success', 'La configuración de pagos y notificaciones fue guardada correctamente.');
        }
    } else {
        flash('danger', implode(' ', $errors));
    }
    redirect('admin/pagos.php');
}

$yape = yape_settings();
$telegram = telegram_settings();
$products = db()->query(
    'SELECT id, code, name, price, pack_price, units_per_pack, stock, image_url
     FROM products WHERE active = 1 ORDER BY name'
)->fetchAll();
$combos = db()->query(
    "SELECT c.id, c.name, c.price,
            LEAST(COALESCE(c.stock, 4294967295), MIN(FLOOR(p.stock / ci.quantity))) AS available
     FROM combos c
     INNER JOIN combo_items ci ON ci.combo_id = c.id
     INNER JOIN products p ON p.id = ci.product_id
     WHERE c.active = 1 AND p.active = 1
     GROUP BY c.id, c.name, c.price
     ORDER BY c.name"
)->fetchAll();
$paymentStats = db()->query(
    "SELECT COUNT(*) AS total,
            SUM(status = 'pendiente') AS pending,
            COALESCE(SUM(CASE WHEN status = 'aprobado' THEN expected_amount ELSE 0 END), 0) AS approved_total
     FROM yape_orders"
)->fetch() ?: ['total' => 0, 'pending' => 0, 'approved_total' => 0];
$pageTitle = 'Centro de pagos';
$pageSubtitle = 'Configura Yape y genera cobros con los precios reales del catálogo';
$activePage = 'payments';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="payment-center-hero">
    <div><span class="section-kicker">VENTAS Y COBRANZAS</span><h2>Todo el flujo de pago, en un solo lugar</h2><p>Configura tu cuenta, genera enlaces para productos y revisa los comprobantes sin mezclar información.</p></div>
    <a class="btn btn-primary" href="<?= url('admin/pedidos_yape.php') ?>">Revisar comprobantes <span>→</span></a>
</section>

<section class="payment-center-stats">
    <article><small>Estado de Yape</small><strong><?= $yape['enabled'] ? 'Activo' : 'Desactivado' ?></strong><em class="<?= $yape['enabled'] ? 'is-online' : '' ?>"><i></i><?= $yape['enabled'] ? 'Disponible en el catálogo' : 'No visible al cliente' ?></em></article>
    <article><small>Pedidos recibidos</small><strong><?= (int) $paymentStats['total'] ?></strong><em>historial registrado</em></article>
    <article><small>Por revisar</small><strong><?= (int) $paymentStats['pending'] ?></strong><em>comprobantes pendientes</em></article>
    <article><small>Ventas aprobadas</small><strong><?= money($paymentStats['approved_total']) ?></strong><em>ingreso confirmado</em></article>
</section>

<section class="payment-center-grid">
<div class="payment-center-main">
    <article class="panel payment-generator" data-payment-generator data-catalog-url="<?= e(url('index.php')) ?>">
        <div class="panel-heading"><div><span class="step-badge">01</span><h2>Generador de cobros</h2><p>Selecciona un producto y crea un enlace listo para enviar al cliente.</p></div></div>
        <div class="payment-generator-grid">
            <label class="form-group"><span>Producto</span><select id="paymentProduct">
                <option value="">Selecciona un producto o combo</option>
                <optgroup label="Productos">
                <?php foreach ($products as $product): ?>
                    <option value="product:<?= (int) $product['id'] ?>"
                            data-kind="product"
                            data-id="<?= (int) $product['id'] ?>"
                            data-name="<?= e($product['name']) ?>"
                            data-price="<?= e((string) $product['price']) ?>"
                            data-pack-price="<?= e((string) $product['pack_price']) ?>"
                            data-pack-size="<?= (int) $product['units_per_pack'] ?>"
                            data-stock="<?= (int) $product['stock'] ?>">
                        <?= e($product['name']) ?> · <?= money($product['price']) ?>
                    </option>
                <?php endforeach; ?>
                </optgroup>
                <?php if ($combos): ?><optgroup label="Combos">
                <?php foreach ($combos as $combo): ?>
                    <option value="combo:<?= (int) $combo['id'] ?>"
                            data-kind="combo"
                            data-id="<?= (int) $combo['id'] ?>"
                            data-name="<?= e($combo['name']) ?>"
                            data-price="<?= e((string) $combo['price']) ?>"
                            data-pack-price="0"
                            data-pack-size="1"
                            data-stock="<?= max(0, (int) $combo['available']) ?>">
                        <?= e($combo['name']) ?> · <?= money($combo['price']) ?>
                    </option>
                <?php endforeach; ?>
                </optgroup><?php endif; ?>
            </select></label>
            <label class="form-group"><span>Presentación</span><select id="paymentPresentation"><option value="unidad">Unidad</option><option value="paquete">Pack</option></select></label>
            <label class="form-group"><span>Cantidad</span><input id="paymentQuantity" type="number" min="1" value="1"></label>
        </div>
        <div class="payment-generator-result">
            <div><small>Resumen del cobro</small><strong id="paymentDescription">Selecciona un producto</strong><span id="paymentAvailability">El precio se completará automáticamente.</span></div>
            <div class="payment-generated-total"><small>Total calculado</small><strong id="paymentGeneratedTotal">S/ 0.00</strong></div>
        </div>
        <div class="payment-link-box"><input id="paymentGeneratedLink" readonly placeholder="Aquí aparecerá el enlace de compra"><button class="btn btn-secondary" id="copyPaymentLink" type="button" disabled>Copiar enlace</button></div>
        <div class="payment-generator-actions">
            <button class="btn btn-primary" id="copyPaymentMessage" type="button" disabled>Copiar mensaje para WhatsApp</button>
            <a class="btn btn-secondary" id="previewPaymentLink" href="#" target="_blank" rel="noopener" aria-disabled="true">Probar enlace</a>
        </div>
        <p class="form-note">El enlace agrega el producto al carrito del cliente con el precio vigente. El importe final siempre vuelve a validarse en el servidor.</p>
    </article>

    <article class="panel yape-admin-form">
        <div class="panel-heading">
            <div><span class="step-badge">02</span><h2>Cuenta receptora</h2><p>Estos datos solo se muestran al cliente durante el pago.</p></div>
        </div>
        <form method="post" enctype="multipart/form-data" class="stack-form">
            <?= csrf_field() ?>
            <label class="switch-row">
                <div><strong>Activar pagos con Yape</strong><small>Muestra el botón en la lista de compra</small></div>
                <span class="switch"><input type="checkbox" name="yape_enabled" value="1" <?= $yape['enabled'] ? 'checked' : '' ?>><i></i></span>
            </label>
            <div class="form-grid two-columns">
                <label class="form-group"><span>Número de Yape</span><input name="yape_phone" value="<?= e($yape['phone']) ?>" inputmode="numeric" maxlength="9" placeholder="999999999"></label>
                <label class="form-group"><span>Nombre del titular</span><input name="yape_owner" value="<?= e($yape['owner']) ?>" maxlength="100" placeholder="Nombre que verá el cliente"></label>
            </div>
            <label class="form-group"><span>WhatsApp para recibir pedidos</span><input name="order_whatsapp" value="<?= e($yape['whatsapp']) ?>" inputmode="numeric" maxlength="11" placeholder="51999999999"></label>
            <label class="form-group yape-qr-upload"><span>Código QR de Yape</span><input type="file" name="yape_qr" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG o WEBP; máximo 5 MB.</small></label>
            <div class="telegram-settings-block">
                <div class="panel-heading"><div><span class="step-badge">03</span><h2>Notificaciones por Telegram</h2><p>Recibe un aviso adicional cada vez que se registre un pedido.</p></div></div>
                <label class="switch-row">
                    <div><strong>Activar Telegram</strong><small>Envía pedidos de Yape y efectivo al chat configurado</small></div>
                    <span class="switch"><input type="checkbox" name="telegram_enabled" value="1" <?= $telegram['enabled'] ? 'checked' : '' ?>><i></i></span>
                </label>
                <div class="form-grid two-columns">
                    <label class="form-group"><span>Token del bot</span><input type="password" name="telegram_bot_token" autocomplete="new-password" placeholder="<?= $telegram['bot_token'] !== '' ? 'Token guardado; déjalo vacío para conservarlo' : '123456789:ABC...' ?>"><small>El token no se muestra nuevamente por seguridad.</small></label>
                    <label class="form-group"><span>Chat ID</span><input name="telegram_chat_id" value="<?= e($telegram['chat_id']) ?>" inputmode="numeric" maxlength="20" placeholder="123456789"></label>
                </div>
                <button class="btn btn-secondary btn-block" type="submit" name="action" value="test_telegram">Guardar y enviar prueba</button>
            </div>
            <button class="btn btn-primary btn-block" type="submit" name="action" value="save">Guardar configuración</button>
        </form>
    </article>
</div>

    <aside class="panel yape-admin-preview">
        <span class="section-kicker">VISTA PREVIA</span>
        <h2>Paga con Yape</h2>
        <div class="yape-preview-qr">
            <?php if ($yape['qr'] !== ''): ?><img src="<?= e(image_src($yape['qr'])) ?>" alt="QR de Yape actual"><?php else: ?><span>QR</span><?php endif; ?>
        </div>
        <small><?= $yape['phone'] !== '' ? 'Número Yape' : 'Pago por QR' ?></small><strong><?= e($yape['phone'] ?: 'QR activo') ?></strong>
        <p><?= e($yape['owner'] ?: 'Agrega el nombre del titular') ?></p>
        <em class="visibility <?= $yape['enabled'] ? 'is-active' : '' ?>"><i></i><?= $yape['enabled'] ? 'Activo en el catálogo' : 'Desactivado' ?></em>
        <div class="payment-preview-flow"><span>1</span><p><strong>El cliente arma su carrito</strong><small>El sistema calcula el total.</small></p></div>
        <div class="payment-preview-flow"><span>2</span><p><strong>Paga con este QR</strong><small>Solo sube su captura.</small></p></div>
        <div class="payment-preview-flow"><span>3</span><p><strong>Revisas y apruebas</strong><small>Se descuenta el stock.</small></p></div>
    </aside>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
