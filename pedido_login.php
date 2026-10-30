<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$error = '';
$rawOrderCode = trim((string) ($_POST['order_code'] ?? ''));
$orderCode = strtoupper($rawOrderCode);
$orderCode = preg_replace('/^\s*(?:PEDIDO|C[ÓO]DIGO(?:\s+DEL\s+PEDIDO)?)\s*:\s*/iu', '', $orderCode) ?? $orderCode;
$orderCode = preg_replace('/\s+/', '', $orderCode) ?? $orderCode;
$lockedUntil = (int) ($_SESSION['tracking_locked_until'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pin = preg_replace('/\D+/', '', (string) ($_POST['tracking_pin'] ?? ''));
    if ($lockedUntil > time()) {
        $error = 'Espera ' . ($lockedUntil - time()) . ' segundos antes de intentarlo nuevamente.';
    } elseif (!preg_match('/^(?:YAPE|EFECTIVO)-[A-Z0-9-]{8,24}$/', $orderCode) || !preg_match('/^\d{6}$/', $pin)) {
        $error = 'Revisa el código del pedido y el PIN de seis dígitos.';
    } else {
        $statement = db()->prepare('SELECT id, tracking_pin_hash FROM yape_orders WHERE order_code = ? LIMIT 1');
        $statement->execute([$orderCode]);
        $order = $statement->fetch();
        if ($order && !empty($order['tracking_pin_hash']) && password_verify($pin, (string) $order['tracking_pin_hash'])) {
            session_regenerate_id(true);
            $_SESSION['customer_order_id'] = (int) $order['id'];
            $_SESSION['tracking_attempts'] = 0;
            unset($_SESSION['tracking_locked_until']);
            redirect('mi_pedido.php');
        }
        $attempts = (int) ($_SESSION['tracking_attempts'] ?? 0) + 1;
        $_SESSION['tracking_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['tracking_locked_until'] = time() + 60;
            $_SESSION['tracking_attempts'] = 0;
            $error = 'Demasiados intentos. Espera un minuto y vuelve a intentarlo.';
        } else {
            $error = 'El código o el PIN no coinciden.';
        }
    }
}

$pageTitle = 'Consultar mi pedido';
require __DIR__ . '/includes/public_header.php';
?>

<section class="tracking-login-section">
    <div class="tracking-login-card">
        <div class="tracking-login-brand"><span aria-hidden="true">✓</span><div><small>SEGUIMIENTO RÁPIDO</small><h1>Consulta tu pedido</h1></div></div>
        <p>Revisa el avance de tu compra con los datos que recibiste al finalizar el pago.</p>
        <div class="tracking-security-note"><span aria-hidden="true">⌁</span><div><strong>Acceso privado y rápido</strong><small>No necesitas registrarte. Tu código y PIN identifican únicamente tu pedido.</small></div></div>
        <?php if ($error !== ''): ?><div class="tracking-error" id="trackingServerError" role="alert"><span>!</span><?= e($error) ?></div><?php endif; ?>
        <div class="tracking-error" id="trackingClientError" role="alert" hidden><span>!</span><b></b></div>
        <form method="post" class="tracking-login-form" id="trackingLoginForm" autocomplete="off" novalidate>
            <?= csrf_field() ?>
            <label for="trackingOrderCode"><span>Código del pedido</span><small>YAPE- o EFECTIVO-</small></label>
            <div class="tracking-input-shell"><span aria-hidden="true">#</span><input id="trackingOrderCode" name="order_code" value="<?= e($orderCode) ?>" placeholder="YAPE-260903-ABC123" maxlength="36" required autocapitalize="characters" spellcheck="false"><button id="pasteTrackingData" type="button">Pegar datos</button></div>
            <label for="trackingPin"><span>PIN de acceso</span><small>6 números</small></label>
            <div class="tracking-input-shell tracking-pin-shell"><span aria-hidden="true">••</span><input id="trackingPin" name="tracking_pin" type="password" inputmode="numeric" maxlength="6" placeholder="000000" required autocomplete="one-time-code"><button id="toggleTrackingPin" type="button" aria-label="Mostrar PIN">Ver</button></div>
            <p class="tracking-field-help">Copia el código y el PIN exactamente como aparecen en la confirmación de tu pedido.</p>
            <button class="btn btn-primary tracking-submit" type="submit"><span>Ver estado de mi pedido</span><b aria-hidden="true">→</b></button>
        </form>
        <small class="tracking-help"><span>?</span> ¿No guardaste tus datos? Comunícate con la tienda e indica la hora y el monto del pago.</small>
    </div>
</section>

<script>
(() => {
    const form = document.getElementById('trackingLoginForm');
    const codeInput = document.getElementById('trackingOrderCode');
    const pinInput = document.getElementById('trackingPin');
    const pasteButton = document.getElementById('pasteTrackingData');
    const toggleButton = document.getElementById('toggleTrackingPin');
    const clientError = document.getElementById('trackingClientError');
    if (!form || !codeInput || !pinInput || !clientError) return;

    const normalizeCode = (value) => String(value || '')
        .toUpperCase().replace(/^\s*(?:PEDIDO|C[ÓO]DIGO(?:\s+DEL\s+PEDIDO)?)\s*:\s*/i, '')
        .replace(/\s+/g, '').trim();
    const readAccessData = (text) => {
        const value = String(text || '');
        const code = value.match(/(?:YAPE|EFECTIVO)-[A-Z0-9-]{8,24}/i)?.[0] || '';
        const pin = value.match(/PIN(?:\s+DE\s+ACCESO)?\s*:\s*(\d{6})/i)?.[1] || '';
        if (code) codeInput.value = normalizeCode(code);
        if (pin) pinInput.value = pin;
        return Boolean(code || pin);
    };
    const showError = (message, input) => {
        clientError.querySelector('b').textContent = message;
        clientError.hidden = false;
        input?.focus();
    };
    const clearError = () => { clientError.hidden = true; };

    codeInput.addEventListener('input', () => {
        if (/\n|PIN\s*:/i.test(codeInput.value)) {
            const pasted = codeInput.value;
            readAccessData(pasted);
        } else codeInput.value = normalizeCode(codeInput.value);
        clearError();
    });
    codeInput.addEventListener('paste', (event) => {
        const text = event.clipboardData?.getData('text') || '';
        if (readAccessData(text)) event.preventDefault();
    });
    pinInput.addEventListener('input', () => {
        pinInput.value = pinInput.value.replace(/\D/g, '').slice(0, 6);
        clearError();
    });
    pasteButton?.addEventListener('click', async () => {
        try {
            const text = await navigator.clipboard.readText();
            if (!readAccessData(text)) showError('No encontramos un código o PIN válido en el texto copiado.', codeInput);
            else { clearError(); pinInput.focus(); }
        } catch (_) {
            codeInput.focus();
            showError('Mantén presionado el campo y elige “Pegar”.', codeInput);
        }
    });
    toggleButton?.addEventListener('click', () => {
        const showing = pinInput.type === 'text';
        pinInput.type = showing ? 'password' : 'text';
        toggleButton.textContent = showing ? 'Ver' : 'Ocultar';
        toggleButton.setAttribute('aria-label', showing ? 'Mostrar PIN' : 'Ocultar PIN');
        pinInput.focus();
    });
    form.addEventListener('submit', (event) => {
        codeInput.value = normalizeCode(codeInput.value);
        if (!/^(?:YAPE|EFECTIVO)-[A-Z0-9-]{8,24}$/.test(codeInput.value)) {
            event.preventDefault();
            showError('Ingresa el código completo que empieza con YAPE- o EFECTIVO-.', codeInput);
        } else if (!/^\d{6}$/.test(pinInput.value)) {
            event.preventDefault();
            showError('El PIN debe tener exactamente 6 números. No uses las últimas letras del código.', pinInput);
        }
    });
})();
</script>

<?php require __DIR__ . '/includes/public_footer.php'; ?>
