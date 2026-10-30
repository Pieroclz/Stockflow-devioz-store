<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/culqi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function culqi_charge_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') culqi_charge_response(405, ['success' => false, 'message' => 'Metodo no permitido.']);
$token = (string) ($_POST['csrf_token'] ?? '');
if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
    culqi_charge_response(419, ['success' => false, 'message' => 'La sesion expiro.']);
}

$config = culqi_config();
if (!$config['enabled']) {
    culqi_charge_response(422, ['success' => false, 'message' => 'La pasarela de tarjeta aun no esta configurada.']);
}

// Culqi real se activa con token del checkout. No aceptamos monto aprobado desde el navegador.
$culqiToken = trim((string) ($_POST['culqi_token'] ?? ''));
if ($culqiToken === '') {
    culqi_charge_response(422, ['success' => false, 'message' => 'Genera el token de Culqi antes de confirmar.']);
}

culqi_charge_response(202, [
    'success' => false,
    'message' => 'Integracion Culqi lista para credenciales: falta completar el cargo con token real del checkout.',
]);
