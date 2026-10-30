<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_admin();

$query=mb_substr(trim((string)($_GET['q']??'')),0,100);
$category=mb_substr(trim((string)($_GET['category']??'')),0,150);
$store=max(0,(int)($_GET['store']??0));
$availability=mb_substr(trim((string)($_GET['availability']??'')),0,50);
$page=max(1,(int)($_GET['page']??1));$perPage=40;
$where=['p.activo=1',safe_external_catalog_condition('p')];$params=[];
if($query!==''){$where[]='(p.producto LIKE :q1 OR p.marca LIKE :q2 OR p.ean LIKE :q3 OR p.presentacion LIKE :q4)';$term='%'.$query.'%';$params+=['q1'=>$term,'q2'=>$term,'q3'=>$term,'q4'=>$term];}
if($category!==''){$where[]='p.categoria=:category';$params['category']=$category;}
if($store>0){$where[]='p.id_supermercado=:store';$params['store']=$store;}
if($availability!==''){$where[]='p.estado_disponibilidad=:availability';$params['availability']=$availability;}
$whereSql=' WHERE '.implode(' AND ',$where);
$count=db()->prepare('SELECT COUNT(*) FROM productos p'.$whereSql);$count->execute($params);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
$statement=db()->prepare('SELECT p.*,s.nombre tienda_nombre FROM productos p LEFT JOIN supermercados s ON s.id_supermercado=p.id_supermercado'.$whereSql.' ORDER BY p.descuento_pct DESC,p.producto LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));$statement->execute($params);$products=$statement->fetchAll();
$categories=db()->query('SELECT DISTINCT categoria FROM productos p WHERE p.activo=1 AND '.safe_external_catalog_condition('p').' ORDER BY categoria')->fetchAll(PDO::FETCH_COLUMN);
$stores=db()->query('SELECT id_supermercado,nombre FROM supermercados WHERE activo=1 ORDER BY nombre')->fetchAll();
$availabilityOptions=db()->query('SELECT DISTINCT estado_disponibilidad FROM productos p WHERE p.activo=1 AND '.safe_external_catalog_condition('p').' ORDER BY estado_disponibilidad')->fetchAll(PDO::FETCH_COLUMN);
$pageTitle='Catálogo completo';$pageSubtitle='Consulta toda la información importada sin mezclarla con tu inventario';$activePage='external-catalog';
require __DIR__.'/../includes/admin_header.php';
?>
<section class="panel admin-catalog-panel">
    <div class="panel-heading"><div><h2>Productos de supermercados</h2><p><?= number_format($total) ?> registros seguros disponibles.</p></div><a class="btn btn-secondary" href="<?= url('catalogo_completo.php') ?>" target="_blank" rel="noopener">Ver como cliente</a></div>
    <form class="suite-filters" method="get">
        <label>Buscar<input name="q" value="<?= e($query) ?>" placeholder="Nombre, marca, EAN o presentación"></label>
        <label>Tienda<select name="store"><option value="0">Todas</option><?php foreach($stores as $item): ?><option value="<?= (int)$item['id_supermercado'] ?>" <?= $store===(int)$item['id_supermercado']?'selected':'' ?>><?= e($item['nombre']) ?></option><?php endforeach ?></select></label>
        <label>Categoría<select name="category"><option value="">Todas</option><?php foreach($categories as $item): ?><option <?= $category===$item?'selected':'' ?>><?= e($item) ?></option><?php endforeach ?></select></label>
        <label>Disponibilidad<select name="availability"><option value="">Todas</option><?php foreach($availabilityOptions as $item): ?><option <?= $availability===$item?'selected':'' ?>><?= e($item) ?></option><?php endforeach ?></select></label>
        <button class="btn btn-primary">Filtrar</button><a class="btn btn-secondary" href="<?= url('admin/catalogo_externo.php') ?>">Limpiar</a>
    </form>
    <div class="table-wrap"><table class="data-table external-admin-table"><thead><tr><th>Producto</th><th>Marca / EAN</th><th>Categoría</th><th>Presentación</th><th>Precio</th><th>Descuento</th><th>Tienda</th><th>Disponibilidad</th><th>Actualización</th></tr></thead><tbody>
    <?php foreach($products as $product): ?><tr>
        <td><div class="admin-catalog-product"><?php if(!empty($product['imagen'])): ?><img src="<?= e($product['imagen']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif ?><strong><?= e($product['producto']) ?></strong></div></td>
        <td><?= e($product['marca']?:'—') ?><small><?= e($product['ean']?:'Sin EAN') ?></small></td><td><?= e($product['categoria']?:'—') ?></td><td><?= e($product['presentacion']?:$product['unidad']?:'—') ?></td>
        <td><strong><?= money($product['precio_actual']) ?></strong><?php if((float)$product['precio_regular']>(float)$product['precio_actual']): ?><small><del><?= money($product['precio_regular']) ?></del></small><?php endif ?></td>
        <td><?= (float)$product['descuento_pct']>0?e(number_format((float)$product['descuento_pct'],2)).'%':'—' ?></td><td><?= e($product['tienda_nombre']?:$product['supermercado']?:'—') ?></td><td><?= e($product['estado_disponibilidad']) ?></td><td><?= e($product['fecha_extraccion']?:$product['fecha_carga']) ?></td>
    </tr><?php endforeach ?><?php if(!$products): ?><tr><td colspan="9">No hay resultados para estos filtros.</td></tr><?php endif ?></tbody></table></div>
    <?php if($pages>1): ?><nav class="suite-pagination" aria-label="Páginas"><?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a class="<?= $i===$page?'active':'' ?>" href="?<?= e(http_build_query(['q'=>$query,'category'=>$category,'store'=>$store,'availability'=>$availability,'page'=>$i])) ?>"><?= $i ?></a><?php endfor ?></nav><?php endif ?>
</section>
<?php require __DIR__.'/../includes/admin_footer.php'; ?>
