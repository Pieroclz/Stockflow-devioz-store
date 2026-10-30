<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/master_catalog.php';
require_admin();

$pageTitle='Productos'; $pageSubtitle='Decide qué se muestra, ajusta el stock y crea productos nuevos'; $activePage='all-products';
$filters=master_catalog_filters($_GET);
try { $result=fetch_master_catalog(db(),$filters); $options=master_catalog_options(db()); $summary=master_catalog_summary(db()); $schemaReady=true; }
catch(PDOException $exception) { $result=['items'=>[],'total'=>0,'pages'=>1,'page'=>1]; $options=['sources'=>[],'categories'=>[]]; $summary=[]; $schemaReady=false; }
function master_catalog_query(array $filters,array $changes=[]): string { return http_build_query(array_filter(array_merge($filters,$changes),static fn($v)=>$v!==''&&$v!==null)); }
function master_catalog_status_label(array $item): string { return master_product_simple_status($item)['label']; }
require __DIR__ . '/../includes/admin_header.php';
?>
<?php if(!$schemaReady): ?><div class="alert alert-warning"><span>Importa <strong>database/upgrade_catalogo_maestro_11604.sql</strong> para activar esta pantalla.</span></div><?php endif; ?>
<?php if($schemaReady && (int)($summary['published']??0)===0): ?><div class="alert alert-warning"><strong>La tienda aún no tiene productos comprables.</strong> Los importados tienen precio referencial y normalmente stock 0. Busca un producto que sí tengas, abre «Preparar venta», registra costo, precio y stock reales, y marca «Activo» y «Habilitado para venta». Luego aparecerá el botón Comprar.</div><?php endif; ?>
<section class="unified-metrics" aria-label="Resumen del catálogo maestro">
 <article><span>Productos maestros</span><strong><?= number_format((int)($summary['total']??0)) ?></strong><small>sin filas históricas</small></article>
 <article><span>Publicados</span><strong><?= number_format((int)($summary['published']??0)) ?></strong><small>listos para vender</small></article>
 <article><span>Por completar</span><strong><?= number_format((int)($summary['missing_pricing']??0)) ?></strong><small>costo o precio faltante</small></article>
 <article><span>Ganancia potencial</span><strong><?= money((float)($summary['potential_profit']??0)) ?></strong><small><?= number_format((int)($summary['low_stock']??0)) ?> con stock bajo</small></article>
</section>
<section class="panel unified-catalog-panel">
 <div class="unified-toolbar"><button class="btn btn-secondary unified-filter-toggle" type="button" data-mobile-filters aria-expanded="false">Filtros</button><a class="btn btn-secondary" href="<?= url('admin/exportar_productos.php?'.master_catalog_query($filters)) ?>">Exportar CSV</a><a class="btn btn-primary" href="<?= url('admin/producto_form.php') ?>">＋ Nuevo producto</a></div>
 <form class="unified-filters" method="get" data-filter-panel>
  <label><span>Buscar</span><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nombre, código, EAN o marca"></label>
  <label><span>Origen</span><select name="source"><option value="">Todos</option><?php foreach($options['sources'] as $source): ?><option value="<?= e($source) ?>" <?= $filters['source']===$source?'selected':'' ?>><?= e($source) ?></option><?php endforeach; ?></select></label>
  <label><span>Categoría</span><select name="category"><option value="">Todas</option><?php foreach($options['categories'] as $category): ?><option value="<?= e($category) ?>" <?= $filters['category']===$category?'selected':'' ?>><?= e($category) ?></option><?php endforeach; ?></select></label>
  <label><span>Estado</span><select name="status"><option value="">Todos</option><?php foreach(['pending'=>'Pendiente','ready'=>'Listo','published'=>'Publicado','out_of_stock'=>'Sin stock','restricted'=>'Restringido'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['status']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
  <label><span>Precio mín.</span><input type="number" step="0.01" min="0" name="min_price" value="<?= e($filters['min_price']) ?>"></label><label><span>Precio máx.</span><input type="number" step="0.01" min="0" name="max_price" value="<?= e($filters['max_price']) ?>"></label>
  <label><span>Stock mín.</span><input type="number" min="0" name="min_stock" value="<?= e($filters['min_stock']) ?>"></label><label><span>Stock máx.</span><input type="number" min="0" name="max_stock" value="<?= e($filters['max_stock']) ?>"></label>
  <label><span>Ordenar</span><select name="sort"><?php foreach(['name'=>'Nombre','source_name'=>'Origen','category'=>'Categoría','supplier_price'=>'Costo proveedor','cost_price'=>'Costo','price'=>'Precio final','stock'=>'Stock','updated_at'=>'Actualización'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['sort']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
  <label><span>Dirección</span><select name="dir"><option value="asc" <?= $filters['dir']==='asc'?'selected':'' ?>>Ascendente</option><option value="desc" <?= $filters['dir']==='desc'?'selected':'' ?>>Descendente</option></select></label><label><span>Filas</span><select name="rows"><?php foreach([25,50,100] as $rows): ?><option value="<?= $rows ?>" <?= $filters['rows']===$rows?'selected':'' ?>><?= $rows ?></option><?php endforeach; ?></select></label>
  <div class="unified-filter-actions"><button class="btn btn-primary" type="submit">Aplicar</button><a href="<?= url('admin/todos_productos.php') ?>">Limpiar</a></div>
 </form>
 <div class="table-summary"><p><strong><?= number_format($result['total']) ?></strong> resultados</p><small>Página <?= $result['page'] ?> de <?= $result['pages'] ?></small></div>
 <form id="masterBulkForm" method="post" action="<?= url('admin/productos_masivo.php') ?>" class="admin-inline-form bulk-product-controls">
  <?= csrf_field() ?><input type="hidden" name="action" value="preview">
  <fieldset class="bulk-scope"><legend>Aplicar a</legend><label><input type="radio" name="selection_scope" value="selected" checked> Seleccionados</label><label><input type="radio" name="selection_scope" value="all"> Toda la base de datos</label></fieldset>
  <label>Acción<select name="bulk_action" required><option value="">Selecciona una acción</option><option value="activate">Mostrar cuando tenga stock</option><option value="deactivate">Ocultar manualmente</option><option value="margin_pct">Definir margen (%)</option><option value="min_stock">Definir stock mínimo</option><option value="price">Definir precio</option></select></label>
  <label>Valor<input name="bulk_value" type="number" min="0" max="999999.99" step="0.01" placeholder="Solo para acciones con valor"></label>
  <button class="btn btn-secondary" type="button" data-select-current-page>Seleccionar página</button><button class="btn btn-primary" type="submit">Revisar cambios</button>
 </form>
 <div class="table-wrap unified-desktop-table"><table class="data-table unified-table"><thead><tr><th><input type="checkbox" data-select-page aria-label="Seleccionar todos en esta página"></th><th>Producto</th><th>Origen</th><th>EAN</th><th>Categoría</th><th>Costo proveedor</th><th>Costo</th><th>Sugerido</th><th>Precio final</th><th>Margen</th><th>Stock</th><th>Utilidad</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
 <?php foreach($result['items'] as $item): $utility=(float)$item['price']-(float)$item['cost_price']; $simpleStatus=master_product_simple_status($item); ?><tr data-product-row="<?= (int)$item['id'] ?>">
  <td><input form="masterBulkForm" type="checkbox" name="ids[]" value="<?= (int)$item['id'] ?>" data-master-selection aria-label="Seleccionar <?= e($item['name']) ?>"></td><td><div class="unified-product-cell"><?php if($item['image_url']): ?><img src="<?= e(image_src($item['image_url'])) ?>" alt="" loading="lazy" data-product-image><?php else: ?><img src="<?= url('assets/img/product-placeholder.svg') ?>" alt="" loading="lazy" data-product-image><?php endif; ?><span><strong><?= e($item['name']) ?></strong><small><?= e($item['code']) ?><?= $item['brand']?' · '.e($item['brand']):'' ?></small></span></div></td><td><?= e($item['source_name']) ?></td><td><?= e($item['ean']) ?></td><td><?= e($item['category']) ?></td>
  <td><?= (float)$item['supplier_price']>0?money((float)$item['supplier_price']):'—' ?></td><td><?= (float)$item['cost_price']>0?money((float)$item['cost_price']):'—' ?></td><td><?= $item['suggested_price']!==null&&(float)$item['suggested_price']>0?money((float)$item['suggested_price']):'—' ?></td><td><input class="quick-cell-input" data-quick-field="price" data-product-id="<?= (int)$item['id'] ?>" value="<?= e($item['price']) ?>" type="number" min="0" step="0.01"></td><td><?= $item['margin_pct']!==null?number_format((float)$item['margin_pct'],1).' %':'—' ?></td><td><input class="quick-cell-input" data-quick-field="stock" data-product-id="<?= (int)$item['id'] ?>" value="<?= (int)$item['stock'] ?>" type="number" min="0" step="1"> und.</td><td><strong class="<?= $utility<0?'money-negative':'money-positive' ?>"><?= (float)$item['cost_price']>0&&(float)$item['price']>0?money($utility):'—' ?></strong></td><td><span data-product-status class="simple-status simple-status-<?= e($simpleStatus['key']) ?>"><?= e($simpleStatus['label']) ?></span></td><td><?php if((int)$item['restricted']===0): ?><button class="btn btn-secondary" type="button" data-quick-field="hidden_from_store" data-product-id="<?= (int)$item['id'] ?>" data-value="<?= $simpleStatus['key']==='hidden'?'0':'1' ?>"><?= $simpleStatus['key']==='hidden'?'Mostrar':'Ocultar' ?></button><a class="btn btn-secondary" href="<?= url('admin/producto_form.php?id='.(int)$item['id']) ?>">Editar</a><?php else: ?>—<?php endif; ?></td>
 </tr><?php endforeach; ?>
 <?php if(!$result['items']): ?><tr><td colspan="14"><div class="empty-mini">No hay productos maestros para estos filtros.</div></td></tr><?php endif; ?></tbody></table></div>
 <div class="unified-mobile-list"><?php foreach($result['items'] as $item): ?><article><div><span class="source-chip"><?= e($item['source_name']) ?></span><h3><?= e($item['name']) ?></h3><p><?= e($item['ean']) ?> · <?= e($item['category']) ?></p></div><dl><div><dt>Costo</dt><dd><?= money((float)$item['cost_price']) ?></dd></div><div><dt>Venta</dt><dd><?= money((float)$item['price']) ?></dd></div><div><dt>Stock</dt><dd><?= (int)$item['stock'] ?></dd></div><div><dt>Estado</dt><dd><?= e(master_catalog_status_label($item)) ?></dd></div></dl></article><?php endforeach; ?></div>
 <?php if($result['pages']>1): ?><nav class="pagination"><a class="<?= $result['page']<=1?'disabled':'' ?>" href="?<?= e(master_catalog_query($filters,['page'=>max(1,$result['page']-1)])) ?>">← Anterior</a><span><?= $result['page'] ?> / <?= $result['pages'] ?></span><a class="<?= $result['page']>=$result['pages']?'disabled':'' ?>" href="?<?= e(master_catalog_query($filters,['page'=>min($result['pages'],$result['page']+1)])) ?>">Siguiente →</a></nav><?php endif; ?>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
 const all = document.querySelector('[data-select-page]');
 const boxes = Array.from(document.querySelectorAll('[data-master-selection]'));
 const select = function (checked) { boxes.forEach(function (box) { box.checked = checked; }); if (all) all.checked = checked; };
 if (all) all.addEventListener('change', function () { select(all.checked); });
 document.querySelector('[data-select-current-page]')?.addEventListener('click', function () { select(true); });
 const csrf = document.querySelector('#masterBulkForm input[name="csrf_token"]')?.value || '';
 const saveQuick = async function (control) {
  const body = new FormData();
  body.append('csrf_token', csrf);
  body.append('id', control.dataset.productId || '');
  body.append('field', control.dataset.quickField || '');
  body.append('value', control.dataset.quickField === 'hidden_from_store' ? (control.dataset.value || '0') : control.value);
  control.disabled = true;
  try {
   const response = await fetch('<?= url('api/admin_product_quick_update.php') ?>', {method:'POST', body});
   const result = await response.json();
   if (!response.ok || !result.success) throw new Error(result.message || 'No se pudo guardar.');
   const row = control.closest('[data-product-row]');
   const status = row?.querySelector('[data-product-status]');
   if (status && result.status) {
    status.textContent = result.status.label;
    status.className = 'simple-status simple-status-' + result.status.key;
   }
   if (control.dataset.quickField === 'hidden_from_store') {
    const hidden = result.status?.key === 'hidden';
    control.textContent = hidden ? 'Mostrar' : 'Ocultar';
    control.dataset.value = hidden ? '0' : '1';
   }
  } catch (error) { alert(error.message || 'No se pudo guardar.'); }
  finally { control.disabled = false; }
 };
 document.querySelectorAll('[data-quick-field]').forEach(function (control) {
  if (control.tagName === 'BUTTON') control.addEventListener('click', function () { saveQuick(control); });
  else control.addEventListener('change', function () { saveQuick(control); });
 });
});
</script>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
