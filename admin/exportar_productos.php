<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';
require_admin();

$filters=master_catalog_filters($_GET);
$where=master_catalog_where($filters);
$order=['name'=>'name','source_name'=>'source_name','category'=>'category','supplier_price'=>'supplier_price','cost_price'=>'cost_price','price'=>'price','stock'=>'stock','updated_at'=>'source_updated_at'][$filters['sort']];
$sql='SELECT source_name,code,name,ean,brand,category,supplier_price,cost_price,suggested_price,price,margin_pct,stock,active,sale_enabled,restricted,source_updated_at FROM products'.$where['sql'].' ORDER BY '.$order.' '.strtoupper($filters['dir']).', id ASC';
$statement=db()->prepare($sql);
master_catalog_bind_params($statement,$where['params']);
$statement->execute();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="devioz-catalogo-maestro-'.date('Ymd-His').'.csv"');
$out=fopen('php://output','wb');
fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,['Origen','Código','Producto','EAN','Marca','Categoría','Costo proveedor','Costo','Precio sugerido','Precio final','Margen','Stock','Estado','Actualización'],';');
$safeCell=static function(mixed $value): mixed { if(!is_string($value)) return $value; return preg_match('/^[=+\-@]/',$value)===1 ? "'".$value : $value; };
while($item=$statement->fetch()) {
    $row=[$item['source_name'],$item['code'],$item['name'],$item['ean'],$item['brand'],$item['category'],$item['supplier_price'],$item['cost_price'],$item['suggested_price'],$item['price'],$item['margin_pct'],$item['stock'],master_product_status($item),$item['source_updated_at']];
    fputcsv($out,array_map($safeCell,$row),';');
}
fclose($out);
exit;
