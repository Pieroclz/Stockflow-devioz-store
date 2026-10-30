<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function tracking_reply(array $data, int $code=200): never { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit; }
try {
 $orderId=max(0,(int)($_POST['order_id']??$_GET['order_id']??0));
 if ($orderId<1) tracking_reply(['error'=>'Pedido inválido'],400);
 $db=db();
 if ($_SERVER['REQUEST_METHOD']==='POST') {
   $token=(string)($_POST['driver_token']??'');
   $lat=filter_var($_POST['lat']??null,FILTER_VALIDATE_FLOAT);
   $lng=filter_var($_POST['lng']??null,FILTER_VALIDATE_FLOAT);
   $accuracy=filter_var($_POST['accuracy']??null,FILTER_VALIDATE_FLOAT);
   if (!is_string($token)||strlen($token)!==64||!ctype_xdigit($token)) tracking_reply(['error'=>'Token inválido'],403);
   if ($lat===false||$lng===false||abs($lat)>90||abs($lng)>180) tracking_reply(['error'=>'Coordenadas inválidas'],400);
   $s=$db->prepare("SELECT t.driver_token_hash,o.status,o.fulfillment_status
 FROM delivery_tracking t JOIN yape_orders o ON o.id=t.order_id
 JOIN delivery_assignments a ON a.order_id=t.order_id
 JOIN delivery_drivers d ON d.id=a.driver_id
 WHERE t.order_id=? AND a.finished_at IS NULL AND d.active=1 LIMIT 1");
   $s->execute([$orderId]);$r=$s->fetch();
   if (!$r||!hash_equals((string)$r['driver_token_hash'],hash('sha256',$token))) tracking_reply(['error'=>'Sin autorización'],403);
   if ($r['status']!=='aprobado'||in_array($r['fulfillment_status'],['entregado','cancelado'],true)) tracking_reply(['error'=>'Seguimiento cerrado'],409);
   $u=$db->prepare("UPDATE delivery_tracking SET driver_lat=?,driver_lng=?,accuracy_m=?,tracking_mode='gps',updated_at=NOW() WHERE order_id=?");
   $u->execute([$lat,$lng,$accuracy===false?null:max(0,min(10000,$accuracy)),$orderId]);
   tracking_reply(['ok'=>true]);
 }
 $admin=is_admin();
 $customer=is_customer();
 $sessionOrder=(int)($_SESSION['customer_order_id']??0);
 if (!$admin && !$customer && $sessionOrder!==$orderId) tracking_reply(['error'=>'Inicia sesión para ver tu pedido'],403);
 $s=$db->prepare('SELECT o.id,o.customer_id,o.status,o.fulfillment_status,t.driver_lat,t.driver_lng,t.destination_lat,t.destination_lng,t.accuracy_m,t.tracking_mode,t.updated_at FROM yape_orders o LEFT JOIN delivery_tracking t ON t.order_id=o.id WHERE o.id=?');
 $s->execute([$orderId]);$r=$s->fetch();
 if (!$r) tracking_reply(['error'=>'No encontrado'],404);
 if (!$admin && !($customer && (int)$r['customer_id']===customer_id()) && $sessionOrder!==$orderId) tracking_reply(['error'=>'Sin autorización'],403);
 $active=$r['status']==='aprobado'&&!in_array($r['fulfillment_status'],['entregado','cancelado'],true);
 $fresh=!empty($r['updated_at']) && (time()-strtotime($r['updated_at'])<=60);
 tracking_reply(['status'=>$r['fulfillment_status'],'active'=>$active,'fresh'=>$fresh,'updated_at'=>$r['updated_at'],
 'driver'=>($active&&$r['driver_lat']!==null&&$r['driver_lng']!==null)?['lat'=>(float)$r['driver_lat'],'lng'=>(float)$r['driver_lng']]:null,
 'destination'=>($r['destination_lat']!==null)?['lat'=>(float)$r['destination_lat'],'lng'=>(float)$r['destination_lng']]:null,
 'last_seen_at'=>$r['updated_at'], 'mode'=>$r['tracking_mode'],'accuracy_m'=>$r['accuracy_m']]);
} catch (Throwable $e) { tracking_reply(['error'=>'No se pudo consultar el seguimiento. Comprueba la instalación SQL.'],500); }
