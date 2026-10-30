<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
$driver = driver_current();
if (!$driver) { http_response_code(401); echo json_encode(['error'=>'Sesión caducada']); exit; }
$q = db()->prepare("SELECT o.id, a.delivery_status FROM delivery_assignments a JOIN yape_orders o ON o.id=a.order_id WHERE a.driver_id=? AND a.finished_at IS NULL AND o.status='aprobado' AND o.fulfillment_status NOT IN ('entregado','cancelado') ORDER BY a.claimed_at DESC");
$q->execute([(int)$driver['id']]);
$orders = [];
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $orders[(string)$row['id']] = ['assigned'=>true, 'status'=>(string)$row['delivery_status']];
echo json_encode(['assigned_count'=>count($orders),'orders'=>$orders], JSON_UNESCAPED_UNICODE);
