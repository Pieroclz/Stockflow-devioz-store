<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nutrition_lookup.php';

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
if ((int) ($_SESSION['nutrition_lookup_last'] ?? 0) === time()) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Espera un segundo antes de buscar otra vez.']);
    exit;
}
$_SESSION['nutrition_lookup_last'] = time();

try {
    $match = nutrition_lookup_open_food_facts((string) ($_POST['ean'] ?? ''));
    echo json_encode(['success' => true, 'match' => $match, 'message' => $match ? 'Datos encontrados para revisar.' : 'Sin datos nutricionales confiables.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
