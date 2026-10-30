<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/culqi.php';

header('Content-Type: application/json; charset=utf-8');
$payload = file_get_contents('php://input') ?: '';
$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$eventId = (string) ($event['id'] ?? '');
if ($eventId === '') {
    http_response_code(422);
    echo json_encode(['success' => false]);
    exit;
}

try {
    db()->exec("CREATE TABLE IF NOT EXISTS culqi_payment_events (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      provider_event_id VARCHAR(120) NOT NULL,
      provider_charge_id VARCHAR(120) NULL,
      order_id BIGINT UNSIGNED NULL,
      status VARCHAR(40) NOT NULL DEFAULT 'received',
      payload_hash CHAR(64) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_culqi_event (provider_event_id),
      UNIQUE KEY uq_culqi_charge (provider_charge_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $statement = db()->prepare('INSERT IGNORE INTO culqi_payment_events (provider_event_id, provider_charge_id, status, payload_hash) VALUES (?,?,?,?)');
    $statement->execute([$eventId, (string) ($event['data']['id'] ?? ''), (string) ($event['type'] ?? 'received'), hash('sha256', $payload)]);
    echo json_encode(['success' => true]);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['success' => false]);
}
