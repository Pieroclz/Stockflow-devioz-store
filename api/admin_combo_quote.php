<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/combo_builder.php';

header('Content-Type: application/json; charset=utf-8');
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido.']);
    exit;
}
$token = (string) ($_POST['csrf_token'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Sesion expirada.']);
    exit;
}

try {
    $items = json_decode((string) ($_POST['items'] ?? '[]'), true);
    $items = combo_builder_normalize_items(is_array($items) ? $items : []);
    $ids = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = db()->prepare("SELECT id,name,stock,cost_price,price,restricted,active,sale_enabled FROM products WHERE id IN ($placeholders) AND catalog_scope='master'");
    $statement->execute($ids);
    $products = [];
    foreach ($statement->fetchAll() as $row) $products[(int) $row['id']] = $row;
    echo json_encode(['success' => true, 'quote' => combo_builder_quote($products, $items)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
