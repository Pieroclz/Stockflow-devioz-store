<?php
declare(strict_types=1);
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/master_catalog.php';
require_once __DIR__.'/includes/ai_recommendations.php';
$ajax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
if ($ajax && !is_customer()) { http_response_code(401); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'Inicia sesión para guardar favoritos']); exit; }
require_customer();
if($_SERVER['REQUEST_METHOD']!=='POST')redirect('mi_cuenta.php');
verify_csrf();
$type=(string)($_POST['item_type']??'');$id=max(0,(int)($_POST['item_id']??0));
if($type!=='product'||$id<1){flash('danger','Favorito no válido.');redirect('mi_cuenta.php');}
$allowed=db()->prepare('SELECT p.name,p.category,p.description FROM products p WHERE p.id=? AND '.master_product_visibility_sql('p').' AND '.public_product_text_visibility_sql('p').' LIMIT 1');
$allowed->execute([$id]);$favoriteProduct=$allowed->fetch();
if(!$favoriteProduct||is_age_restricted_product($favoriteProduct)){flash('danger','Ese producto no está disponible en el catálogo.');redirect('catalogo_completo.php');}
$check=db()->prepare('SELECT id FROM customer_favorites WHERE customer_id=? AND item_type=? AND item_id=?');$check->execute([customer_id(),$type,$id]);
$saved = false;
if($favoriteId=$check->fetchColumn()){db()->prepare('DELETE FROM customer_favorites WHERE id=? AND customer_id=?')->execute([(int)$favoriteId,customer_id()]);flash('success','Producto retirado de favoritos.');}
else{db()->prepare('INSERT INTO customer_favorites(customer_id,item_type,item_id) VALUES(?,?,?)')->execute([customer_id(),$type,$id]);$saved = true;flash('success','Producto guardado en favoritos.');}
if ($ajax) { header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode(['ok'=>true,'favorite'=>$saved], JSON_UNESCAPED_UNICODE); exit; }
$return=(string)($_POST['return']??'mi_cuenta.php');
if(!preg_match('#^[a-zA-Z0-9_./?=&%+-]+$#D',$return))$return='mi_cuenta.php';
header('Location: '.url($return));exit;
