<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';require_admin();
if(($_SESSION['admin_role']??'admin')!=='admin'){http_response_code(403);exit('Sin permiso.');}
$tables=['products','categories','combos','combo_items','inventory_movements','yape_orders','yape_order_items','customers','customer_addresses','customer_favorites','delivery_zones','coupons','coupon_usage','stock_reservations','store_settings','shopping_sources','shopping_offers','productos','supermercados','categorias','pedidos','detalle_pedido'];
$backup=['generated_at'=>date(DATE_ATOM),'database'=>DB_NAME,'tables'=>[]];
foreach($tables as $table){try{$backup['tables'][$table]=db()->query('SELECT * FROM `'.$table.'`')->fetchAll();}catch(PDOException){$backup['tables'][$table]=[];}}
header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="devioz-respaldo-'.date('Ymd-His').'.json"');header('Cache-Control: no-store');
echo json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
