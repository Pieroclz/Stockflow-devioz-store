<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$orderId = max(0, (int) ($_GET['id'] ?? 0));
$providedToken = is_string($_GET['token']??null) ? $_GET['token'] : '';
$sessionOrderId = (int) ($_SESSION['customer_order_id'] ?? 0);
$allowedByToken = $orderId > 0 && $providedToken !== ''
    && hash_equals(receipt_access_token($orderId), $providedToken);
$allowed = is_admin() || $sessionOrderId === $orderId || $allowedByToken;
if (!$allowed || $orderId <= 0) {
    http_response_code(403);
    exit('No tienes acceso a este recibo. Ingresa nuevamente con el código y PIN del pedido.');
}

$statement = db()->prepare('SELECT * FROM yape_orders WHERE id = ? LIMIT 1');
$statement->execute([$orderId]);
$order = $statement->fetch();
if (!$order) {
    http_response_code(404);
    exit('El pedido no existe.');
}
if ((string) $order['status'] !== 'aprobado' || (string) $order['fulfillment_status'] !== 'entregado') {
    http_response_code(409);
    exit('El recibo estará disponible cuando el pedido sea entregado.');
}
$itemsStatement = db()->prepare('SELECT item_name, quantity, presentation, units_per_item, unit_price, subtotal FROM yape_order_items WHERE order_id = ? ORDER BY id');
$itemsStatement->execute([$orderId]);
$items = $itemsStatement->fetchAll();
$paymentMethod = (string) ($order['metodo_pago'] ?? 'yape');
require_once __DIR__.'/includes/receipt_layout.php';
$business=receipt_business();
$number=receipt_number($order);
$receiptLink=receipt_url($orderId);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if(($_GET['download']??'')==='txt'){
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$number.'.txt"');
    echo receipt_plain_text($business,$order,$items);exit;
}
$autoPrint = isset($_GET['print']);
require __DIR__.'/includes/receipt_ticket.php';
