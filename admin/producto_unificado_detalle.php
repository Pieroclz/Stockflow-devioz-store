<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/unified_catalog.php';
require_admin();
$key = parse_item_key((string)($_GET['item'] ?? ''));
$statement = db()->prepare('SELECT * FROM (' . unified_catalog_base_sql() . ') rows_all WHERE source_type=? AND source_id=? LIMIT 1');
$statement->execute([$key['source_type'],$key['source_id']]);
$item = $statement->fetch();
if (!$item) { http_response_code(404); exit('Producto no encontrado.'); }
$pageTitle = 'Detalle del producto'; $pageSubtitle = 'Información financiera y de origen'; $activePage = 'all-products';
$movements=[]; $comparisons=[];
if ($key['source_type']==='internal') {
    $s=db()->prepare('SELECT * FROM inventory_movements WHERE product_id=? ORDER BY moved_at DESC,id DESC LIMIT 20');$s->execute([$key['source_id']]);$movements=$s->fetchAll();
} else {
    $s=db()->prepare('SELECT p2.id_producto,p2.producto,p2.precio_actual,p2.supermercado FROM productos p1 INNER JOIN productos p2 ON p2.ean=p1.ean AND p2.id_producto<>p1.id_producto WHERE p1.id_producto=? AND p1.ean IS NOT NULL AND p1.ean<>"" ORDER BY p2.precio_actual LIMIT 10');$s->execute([$key['source_id']]);$comparisons=$s->fetchAll();
}
require __DIR__ . '/../includes/admin_header.php';
?>
<section class="detail-hero panel"><div><span class="source-chip source-chip-<?= e($item['source_type']) ?>"><?= e($item['source_name']) ?></span><h2><?= e($item['name']) ?></h2><p><?= e($item['category']) ?> · <?= e($item['code']) ?> · <?= e($item['presentation']) ?></p></div><a class="btn btn-secondary" href="<?= url('admin/todos_productos.php') ?>">← Volver</a></section>
<section class="unified-metrics"><article><span>Costo</span><strong><?= $item['cost']!==null?money($item['cost']):'Sin precio' ?></strong></article><article><span>Precio de venta</span><strong><?= $item['sale_price']!==null?money($item['sale_price']):'Sin precio' ?></strong></article><article><span><?= $item['profit_type']==='real'?'Ganancia real':'Ganancia estimada' ?></span><strong><?= $item['unit_profit']!==null?money($item['unit_profit']):'—' ?></strong></article><article><span>Margen</span><strong><?= $item['margin']!==null?number_format((float)$item['margin'],1).' %':'—' ?></strong></article></section>
<?php if($item['source_type']==='external'): ?>
<div class="admin-two-columns"><section class="panel"><h2>Precio sugerido</h2><form method="post" action="<?= url('admin/producto_unificado_guardar.php') ?>" class="stack-form"><?= csrf_field() ?><input type="hidden" name="action" value="external_price"><input type="hidden" name="external_id" value="<?= (int)$item['source_id'] ?>"><label>Precio personalizado<input type="number" name="price" min="0" step="0.01" placeholder="Vacío usa el margen global"></label><label>Margen personalizado (%)<input type="number" name="margin" min="0" max="500" step="0.01"></label><button class="btn btn-primary">Guardar sugerencia</button></form></section>
<section class="panel"><h2>Agregar a inventario Devioz</h2><form method="post" action="<?= url('admin/producto_unificado_guardar.php') ?>" class="stack-form" data-confirm-convert><?= csrf_field() ?><input type="hidden" name="action" value="convert"><input type="hidden" name="external_id" value="<?= (int)$item['source_id'] ?>"><label>Costo<input type="number" name="cost_price" min="0" step="0.01" value="<?= e($item['cost']) ?>" required></label><label>Precio de venta<input type="number" name="price" min="0.01" step="0.01" value="<?= e($item['sale_price']) ?>" required></label><label>Stock inicial<input type="number" name="stock" min="0" value="0" required></label><button class="btn btn-primary">Agregar a inventario</button></form></section></div>
<?php else: ?><section class="panel"><h2>Movimientos recientes</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha</th><th>Tipo</th><th>Unidades</th><th>Ingreso</th><th>Ganancia</th></tr></thead><tbody><?php foreach($movements as $movement): ?><tr><td><?= e($movement['moved_at']) ?></td><td><?= e($movement['movement_type']) ?></td><td><?= number_format((int)$movement['units_changed']) ?></td><td><?= money($movement['total_income']) ?></td><td><?= money($movement['profit']) ?></td></tr><?php endforeach; ?><?php if(!$movements): ?><tr><td colspan="5">Sin movimientos registrados.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>
<?php if($comparisons): ?><section class="panel"><h2>Comparación entre proveedores</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Proveedor</th><th>Producto</th><th>Precio</th></tr></thead><tbody><?php foreach($comparisons as $row): ?><tr><td><?= e($row['supermercado']) ?></td><td><?= e($row['producto']) ?></td><td><?= money($row['precio_actual']) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
