<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (!is_admin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesión administrativa no válida.']);
    exit;
}

try {
    $summary = db()->query(
        "SELECT COUNT(*) AS pending_count, COALESCE(MAX(id), 0) AS latest_id
         FROM yape_orders WHERE status = 'pendiente'"
    )->fetch() ?: ['pending_count' => 0, 'latest_id' => 0];

    $latest = db()->query(
        "SELECT id, order_code, expected_amount, created_at
         FROM yape_orders WHERE status = 'pendiente'
         ORDER BY id DESC LIMIT 1"
    )->fetch() ?: null;

    echo json_encode([
        'success' => true,
        'pending_count' => (int) $summary['pending_count'],
        'latest_id' => (int) $summary['latest_id'],
        'latest' => $latest ? [
            'id' => (int) $latest['id'],
            'order_code' => (string) $latest['order_code'],
            'total' => (float) $latest['expected_amount'],
            'created_at' => (string) $latest['created_at'],
        ] : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'No se pudo consultar los pagos.']);
}
