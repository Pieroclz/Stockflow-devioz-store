<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/culqi.php';

$publicCartYape = yape_settings();
$publicCartPlin = plin_settings();
$publicCartCulqi = culqi_public_config(culqi_config());
$publicCartCustomer = customer_profile();
$publicCartDeliveryZones = [];
$publicCartAddresses = [];
try {
    $publicCartDeliveryZones = db()->query(
        'SELECT id,district,delivery_fee,estimated_minutes FROM delivery_zones WHERE active=1 ORDER BY delivery_fee,district'
    )->fetchAll();
    if ($publicCartCustomer) {
        $publicCartAddressStatement = db()->prepare(
            'SELECT * FROM customer_addresses WHERE customer_id=? ORDER BY is_default DESC,id DESC'
        );
        $publicCartAddressStatement->execute([(int)$publicCartCustomer['id']]);
        $publicCartAddresses = $publicCartAddressStatement->fetchAll();
    }
} catch (PDOException) {
    $publicCartDeliveryZones = [];
    $publicCartAddresses = [];
}
?>
<div class="list-backdrop" id="listDrawer" hidden>
    <aside class="shopping-drawer" role="dialog" aria-modal="true" aria-labelledby="listDrawerTitle">
        <header class="drawer-header">
            <div>
                <span>DEVIOZ STORE</span>
                <h2 id="listDrawerTitle">Mi lista de compra</h2>
            </div>
            <button type="button" data-list-close aria-label="Cerrar lista">×</button>
        </header>
        <div class="drawer-items" id="listItems"></div>
        <div class="drawer-empty" id="listEmpty">
            <span>🛒</span>
            <h3>Tu lista está vacía</h3>
            <p>Agrega productos del catálogo para calcular el total.</p>
        </div>
        <footer class="drawer-footer">
            <div><span>Total estimado</span><strong id="listTotal">S/ 0.00</strong></div>
            <p>La lista se guarda únicamente en este navegador.</p>
            <button class="yape-checkout-button" id="yapeCheckoutButton" type="button" disabled>
                <span class="yape-mark">S/</span><span>Finalizar compra</span><b>→</b>
            </button>
            <button class="clear-list-button" id="clearListButton" type="button">Vaciar lista</button>
        </footer>
    </aside>
</div>

<div class="yape-backdrop" id="yapeModal" hidden
     data-enabled="<?= $publicCartYape['enabled'] ? '1' : '0' ?>"
     data-phone="<?= e($publicCartYape['phone']) ?>"
     data-owner="<?= e($publicCartYape['owner']) ?>"
     data-has-qr="<?= $publicCartYape['qr'] !== '' ? '1' : '0' ?>"
     data-plin-enabled="<?= $publicCartPlin['enabled'] ? '1' : '0' ?>"
     data-plin-phone="<?= e($publicCartPlin['phone']) ?>"
     data-plin-owner="<?= e($publicCartPlin['owner']) ?>"
     data-plin-has-qr="<?= $publicCartPlin['qr'] !== '' ? '1' : '0' ?>"
     data-card-enabled="<?= $publicCartCulqi['enabled'] ? '1' : '0' ?>"
     data-culqi-public-key="<?= e($publicCartCulqi['public_key']) ?>"
     data-whatsapp="<?= e($publicCartYape['whatsapp']) ?>">
    <section class="yape-modal" role="dialog" aria-modal="true" aria-labelledby="yapeModalTitle">
        <button class="yape-modal-close" type="button" data-yape-close aria-label="Cerrar">×</button>
        <header>
            <span class="yape-brand">S/</span>
            <div><small>CHECKOUT SEGURO</small><h2 id="yapeModalTitle">Completa tu pedido</h2></div>
        </header>
        <ol class="checkout-progress" aria-label="Progreso del pedido">
            <li data-checkout-progress="review" aria-current="step">Resumen</li>
            <li data-checkout-progress="delivery">Entrega</li>
            <li data-checkout-progress="payment">Pago</li>
            <li data-checkout-progress="confirmation">Confirmar</li>
        </ol>
        <div class="yape-modal-body checkout-step-layout">
            <div class="yape-qr-panel" id="yapePaymentPanel">
                <span class="yape-step-label"><b>Y</b> Escanea y paga</span>
                <?php if ($publicCartYape['qr'] !== ''): ?>
                    <img src="<?= e(image_src($publicCartYape['qr'])) ?>" alt="Código QR de Yape de Devioz Store">
                <?php else: ?>
                    <span class="yape-qr-placeholder">QR</span>
                <?php endif; ?>
                <small>Escanea el código desde la aplicación Yape</small>
            </div>
            <div class="yape-payment-info">
                <span class="yape-step-label">Total del pedido</span>
                <strong id="yapeTotal">S/ 0.00</strong>
                <div data-checkout-step="review">
                    <h3 tabindex="-1">Resumen</h3>
                    <p>Revisa productos, cantidades y total antes de seguir.</p>
                </div>
                <input id="yapeCsrfToken" type="hidden" value="<?= e(csrf_token()) ?>">
                <div class="checkout-customer-fields" id="checkoutCustomerFields" data-checkout-step="delivery" hidden>
                    <h3 tabindex="-1">Entrega</h3>
                    <label>Nombre del cliente<input id="checkoutCustomer" maxlength="120" value="<?= e($publicCartCustomer['name'] ?? '') ?>" placeholder="Nombre y apellido" required></label>
                    <label>Celular<input id="checkoutPhone" maxlength="20" inputmode="tel" value="<?= e($publicCartCustomer['phone'] ?? '') ?>" placeholder="999 999 999" required></label>
                    <label>Entrega (opcional)<select id="checkoutDeliveryZone"><option value="">Sin entrega · recojo en tienda</option><?php foreach ($publicCartDeliveryZones as $zone): if ($zone['district'] === 'Recojo en tienda') continue; ?><option value="<?= (int)$zone['id'] ?>" data-fee="<?= e($zone['delivery_fee']) ?>" data-minutes="<?= (int)$zone['estimated_minutes'] ?>"><?= e($zone['district']) ?> · <?= money($zone['delivery_fee']) ?></option><?php endforeach; ?></select></label>
                    <div class="checkout-shipping-fields" id="checkoutShippingFields" hidden>
                        <label>Dirección para envío<input id="checkoutAddress" maxlength="255" list="savedAddresses" placeholder="Dirección de entrega"></label>
                        <datalist id="savedAddresses"><?php foreach ($publicCartAddresses as $address): ?><option value="<?= e($address['address']) ?>"><?= e($address['label'] . ' · ' . $address['district']) ?></option><?php endforeach; ?></datalist>
                        <label>Referencia (opcional)<input id="checkoutReference" maxlength="255" placeholder="Referencia para el repartidor"></label>
                    </div>
                    <div class="coupon-line"><input id="checkoutCoupon" maxlength="40" placeholder="Código de cupón"><button id="applyCoupon" type="button">Aplicar</button></div>
                    <small id="checkoutQuoteStatus">Calcularemos el total para recojo en tienda.</small>
                </div>
                <div data-checkout-step="payment" hidden>
                    <h3 tabindex="-1">Pago</h3>
                    <label class="checkout-payment-method">Método de pago
                        <select id="checkoutPaymentMethod">
                            <option value="efectivo">Efectivo</option>
                            <?php if (payment_method_available('yape', ['yape' => $publicCartYape])): ?><option value="yape">Yape</option><?php endif; ?>
                            <?php if (payment_method_available('plin', ['plin' => $publicCartPlin])): ?><option value="plin">Plin</option><?php endif; ?>
                            <?php if ($publicCartCulqi['enabled']): ?><option value="tarjeta">Tarjeta</option><?php endif; ?>
                        </select>
                    </label>
                <div id="cashPaymentFields">
                    <div class="cash-exact-choice" role="group" aria-label="Forma de pago en efectivo">
                        <button class="is-active" id="cashExactButton" type="button" data-cash-mode="exacto">Pago exacto</button>
                        <button id="cashChangeButton" type="button" data-cash-mode="vuelto">Necesito vuelto</button>
                    </div>
                    <div class="cash-exact-summary" id="cashExactSummary">Pagarás exactamente el total.</div>
                    <label id="cashPaysWithLabel" hidden>¿Con cuánto vas a pagar?
                        <input id="cashPaysWith" type="number" min="0" max="999999.99" step="0.01" inputmode="decimal" placeholder="0.00">
                    </label>
                    <div class="cash-change-summary" id="cashChangeSummary" role="status" aria-live="polite">Vuelto: S/ 0.00</div>
                </div>
                <div id="yapeMethodFields" hidden>
                    <div class="yape-account">
                        <small id="digitalPaymentLabel"><?= $publicCartYape['phone'] !== '' ? 'Número Yape' : 'Cuenta Yape' ?></small><b id="yapePhone"><?= e($publicCartYape['phone'] ?: 'Paga escaneando el QR') ?></b>
                        <em id="digitalPaymentOwner"><?= e($publicCartYape['owner']) ?></em>
                        <?php if ($publicCartYape['phone'] !== ''): ?><button id="copyYapePhone" type="button">Copiar número</button><?php endif; ?>
                    </div>
                    <label class="yape-receipt-upload" id="yapeReceiptUpload">
                        <span class="yape-step-label"><b>3</b> Sube tu captura</span>
                        <input id="yapeReceipt" type="file" accept="image/jpeg,image/png,image/webp">
                        <div class="yape-upload-prompt" id="yapeUploadPrompt"><b>＋ Subir captura</b><small>Una foto clara · JPG, PNG o WEBP</small></div>
                        <img id="yapeReceiptPreview" alt="Vista previa del comprobante" hidden>
                    </label>
                </div>
                <div id="cardMethodFields" hidden>
                    <p>El pago con tarjeta se procesa con Culqi. Los datos de la tarjeta no pasan por StockFlow.</p>
                    <button class="btn btn-secondary" id="culqiPayButton" type="button">Pagar con tarjeta</button>
                </div>
                </div>
                <div data-checkout-step="confirmation" hidden>
                    <h3 tabindex="-1">Confirmacion</h3>
                    <p>Confirma el pedido con el total actualizado.</p>
                </div>
                <div class="checkout-step-actions"><button class="btn btn-secondary" id="checkoutBackStep" type="button">Atrás</button><button class="btn btn-primary" id="checkoutNextStep" type="button">Continuar</button></div>
                <button class="yape-confirm-button" id="confirmYapeOrder" type="button" disabled>Confirmar pedido</button>
                <p id="yapeHelpText">Verifica el total antes de confirmar.</p>
                <div class="yape-inline-status" id="yapeInlineStatus" role="status" aria-live="polite" hidden></div>
                <div class="yape-order-access" id="yapeOrderAccess" hidden>
                    <span>✓ PEDIDO REGISTRADO</span><strong>Guarda tus datos de acceso</strong>
                    <div><small>Código del pedido</small><b id="yapeOrderCode"></b></div>
                    <div><small>PIN de acceso</small><b id="yapeTrackingPin"></b></div>
                    <button type="button" id="copyTrackingData">Copiar código y PIN</button>
                    <a id="yapeTrackingLink" href="<?= url('mi_pedido.php') ?>">Ver estado de mi pedido →</a>
                </div>
            </div>
        </div>
    </section>
</div>
