<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/ai_recommendations.php';
$items=[
 ['id'=>1,'name'=>'Galletas','price'=>5.0,'stock'=>3,'category'=>'Snacks','active'=>1,'sale_enabled'=>1,'restricted'=>0],
 ['id'=>2,'name'=>'Cerveza','price'=>7.0,'stock'=>20,'category'=>'Bebidas alcohólicas','active'=>1,'sale_enabled'=>1,'restricted'=>0],
 ['id'=>3,'name'=>'Jugo','price'=>4.0,'stock'=>0,'category'=>'Bebidas','active'=>1,'sale_enabled'=>1,'restricted'=>0],
 ['id'=>4,'name'=>'Galletas ocultas','price'=>4.0,'stock'=>5,'category'=>'Snacks','active'=>1,'sale_enabled'=>0,'restricted'=>0],
 ['id'=>5,'name'=>'Producto restringido','price'=>3.0,'stock'=>5,'category'=>'Snacks','active'=>1,'sale_enabled'=>1,'restricted'=>1],
 ['id'=>6,'name'=>'Producto inactivo','price'=>2.0,'stock'=>5,'category'=>'Snacks','active'=>0,'sale_enabled'=>1,'restricted'=>0],
];
$result=rule_based_recommendations($items,['query'=>'para compartir','budget'=>12.0,'category'=>'','limit'=>6]);
if(array_column($result,'id')!==[1]) throw new RuntimeException('Debe excluir restringidos y sin stock');
$budgetItems=[
 ['id'=>7,'name'=>'A','price'=>5.0,'stock'=>2,'category'=>'Snacks','active'=>1,'sale_enabled'=>1,'restricted'=>0],
 ['id'=>8,'name'=>'B','price'=>5.0,'stock'=>2,'category'=>'Snacks','active'=>1,'sale_enabled'=>1,'restricted'=>0],
 ['id'=>9,'name'=>'C','price'=>5.0,'stock'=>2,'category'=>'Snacks','active'=>1,'sale_enabled'=>1,'restricted'=>0],
];
$budgetResult=rule_based_recommendations($budgetItems,['query'=>'','budget'=>12.0,'category'=>'','limit'=>6]);
if(count($budgetResult)!==2) throw new RuntimeException('La suma debe respetar el presupuesto');
$validated=validate_model_recommendations(
 [['id'=>999,'qty'=>1],['id'=>4,'qty'=>1],['id'=>5,'qty'=>1],['id'=>1,'qty'=>9]],
 array_column($items,null,'id'),
 12.0
);
if($validated!==[['id'=>1,'qty'=>2]]) throw new RuntimeException('Debe rechazar inventados y limitar stock/presupuesto');
$duplicate=validate_model_recommendations(
 [['id'=>1,'qty'=>2],['id'=>1,'qty'=>2],['id'=>'1','qty'=>1],['id'=>1,'qty'=>0]],
 [1=>$items[0]],
 20.0
);
if($duplicate!==[['id'=>1,'qty'=>3]]) throw new RuntimeException('Debe acumular sin superar stock y rechazar tipos o cantidades inválidos');
$bounded=validate_model_recommendations(
 [['id'=>10,'qty'=>100000],['id'=>7,'qty'=>100000]],
 [10=>['id'=>10,'name'=>'A','price'=>1.0,'stock'=>1000,'category'=>'Snacks','active'=>1,'sale_enabled'=>1,'restricted'=>0],7=>['id'=>7,'name'=>'B','price'=>1.0,'stock'=>1000,'category'=>'Snacks','active'=>1,'sale_enabled'=>1,'restricted'=>0]],
 null
);
if($bounded!==[['id'=>10,'qty'=>99]]) throw new RuntimeException('La cantidad debe estar limitada por stock y el contrato del carrito');
$prompt=recommendation_prompt_catalog([array_merge($items[0],[
 'code'=>'SKU-1','description'=>'interno','cost_price'=>1.0,'supplier_price'=>0.8,
 'catalog_scope'=>'master','api_key'=>'secret','sql'=>'SELECT * FROM products',
])]);
if($prompt!==[['id'=>1,'name'=>'Galletas','category'=>'Snacks','price'=>5.0,'stock'=>3]]) {
    throw new RuntimeException('El prompt solo debe incluir campos públicos necesarios');
}
$_SESSION=[];
$confirmationItems=[['id'=>1,'quantity'=>1,'price'=>5.0]];
$confirmation=recommendation_confirmation_create($confirmationItems,['budget'=>12.0,'limit'=>1]);
if(!is_string($confirmation)||recommendation_confirmation_consume($confirmation,[['id'=>1,'quantity'=>2,'price'=>5.0]])||!recommendation_confirmation_consume($confirmation,$confirmationItems)||recommendation_confirmation_consume($confirmation,$confirmationItems)) {
    throw new RuntimeException('La confirmación debe estar vinculada al payload exacto y ser de un solo uso');
}
echo "ai_recommendations_test: OK\n";
