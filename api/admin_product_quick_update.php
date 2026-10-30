<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';

header('Content-Type: application/json; charset=utf-8');
require_admin();

function quick_update_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') quick_update_response(405, ['success' => false, 'message' => 'Metodo no permitido.']);
$token = (string) ($_POST['csrf_token'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    quick_update_response(419, ['success' => false, 'message' => 'Sesion expirada.']);
}

$id = max(0, (int) ($_POST['id'] ?? 0));
$field = (string) ($_POST['field'] ?? '');
$value = $_POST['value'] ?? null;
$allowed = ['price', 'stock', 'min_stock', 'hidden_from_store'];
if ($id <= 0 || !in_array($field, $allowed, true)) quick_update_response(422, ['success' => false, 'message' => 'Campo no permitido.']);

try {
    $pdo = db();
    if ($field === 'hidden_from_store') {
        $hidden = in_array((string) $value, ['1', 'true', 'on'], true) ? 1 : 0;
        $statement = $pdo->prepare("UPDATE products SET sale_enabled=?, active=1 WHERE id=? AND catalog_scope='master'");
        $statement->execute([$hidden ? 0 : 1, $id]);
    } elseif ($field === 'price') {
        $price = master_bulk_money_cents(['price' => $value]['price']);
        $statement = $pdo->prepare("UPDATE products SET price=? WHERE id=? AND catalog_scope='master'");
        $statement->execute([$price, $id]);
    } else {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]) === false) {
            throw new InvalidArgumentException('El valor no es valido.');
        }
        $statement = $pdo->prepare("UPDATE products SET {$field}=? WHERE id=? AND catalog_scope='master'");
        $statement->execute([(int) $value, $id]);
    }
    $row = $pdo->prepare("SELECT id,price,stock,min_stock,active,sale_enabled,restricted,catalog_scope,updated_at FROM products WHERE id=?");
    $row->execute([$id]);
    $product = $row->fetch();
    quick_update_response(200, ['success' => true, 'field' => $field, 'value' => $value, 'status' => master_product_simple_status($product ?: []), 'updated_at' => (string) ($product['updated_at'] ?? '')]);
} catch (Throwable $exception) {
    quick_update_response(422, ['success' => false, 'message' => $exception->getMessage()]);
}
