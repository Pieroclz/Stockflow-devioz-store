<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function reply(int $code,string $error=''): never {http_response_code($code);echo json_encode($error?['error'=>$error]:['ok'=>true]);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')reply(405,'Método no permitido');
$driver=driver_current();if(!$driver)reply(401,'Sesión caducada');
$csrf=(string)($_POST['csrf_token']??'');if(!hash_equals((string)($_SESSION['csrf_token']??''),$csrf))reply(403,'Formulario caducado');
$orderId=max(0,(int)($_POST['order_id']??0));if($orderId<1)reply(400,'Falta indicar el pedido');
$coords=driver_coordinates($_POST['lat']??null,$_POST['lng']??null);if(!$coords)reply(400,'Coordenadas inválidas');
$mode=(string)($_POST['mode']??'');if(!in_array($mode,['gps','manual'],true))reply(400,'Modo inválido');
$accuracy=isset($_POST['accuracy'])&&is_numeric($_POST['accuracy'])?max(0,min(10000,(float)$_POST['accuracy'])):null;
try{
 $db=db();$db->beginTransaction();
 $q=$db->prepare("SELECT a.order_id FROM delivery_assignments a JOIN yape_orders o ON o.id=a.order_id WHERE a.driver_id=? AND a.order_id=? AND a.finished_at IS NULL AND o.status='aprobado' AND o.fulfillment_status NOT IN ('entregado','cancelado') LIMIT 1 FOR UPDATE");$q->execute([(int)$driver['id'],$orderId]);$authorizedOrderId=$q->fetchColumn();
 if(!$authorizedOrderId){$db->rollBack();reply(409,'No tienes una entrega activa');}
 $check=$db->prepare("SELECT order_id FROM delivery_tracking WHERE order_id=?");$check->execute([(int)$orderId]);if(!$check->fetchColumn()){
   $db->prepare('INSERT INTO delivery_tracking (order_id,driver_token_hash) VALUES (?,?)')->execute([(int)$orderId,hash('sha256',bin2hex(random_bytes(32)))]);
 }
 $q=$db->prepare("UPDATE delivery_tracking SET driver_lat=?,driver_lng=?,accuracy_m=?,tracking_mode=?,updated_at=NOW() WHERE order_id=?");$q->execute([$coords[0],$coords[1],$accuracy,$mode,(int)$orderId]);
 $db->commit();reply(200);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();reply(500,'Error al guardar ubicación');}
