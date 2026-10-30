<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_admin();

$query=mb_substr(trim((string)($_GET['q']??'')),0,100);$page=max(1,(int)($_GET['page']??1));$perPage=30;
$where=' WHERE c.active=1';$params=[];
if($query!==''){$where.=' AND (c.name LIKE :name OR c.email LIKE :email OR c.phone LIKE :phone)';$term='%'.$query.'%';$params=['name'=>$term,'email'=>$term,'phone'=>$term];}
$count=db()->prepare('SELECT COUNT(*) FROM customers c'.$where);$count->execute($params);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
$sql='SELECT c.id,c.name,c.email,c.phone,c.created_at,COALESCE(o.order_count,0) order_count,COALESCE(o.total_spent,0) total_spent,COALESCE(f.favorite_count,0) favorite_count
      FROM customers c
      LEFT JOIN (SELECT customer_id,COUNT(*) order_count,SUM(CASE WHEN status="aprobado" THEN expected_amount ELSE 0 END) total_spent FROM yape_orders GROUP BY customer_id) o ON o.customer_id=c.id
      LEFT JOIN (SELECT customer_id,COUNT(*) favorite_count FROM customer_favorites GROUP BY customer_id) f ON f.customer_id=c.id'.$where.' ORDER BY c.created_at DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage);
$statement=db()->prepare($sql);$statement->execute($params);$customers=$statement->fetchAll();
$pageTitle='Clientes';$pageSubtitle='Cuentas, contacto, pedidos y productos favoritos';$activePage='customers';require __DIR__.'/../includes/admin_header.php';
?>
<section class="panel"><div class="panel-heading"><div><h2>Clientes registrados</h2><p><?= number_format($total) ?> cuentas activas.</p></div></div>
<form class="yape-order-search" method="get"><span>⌕</span><input name="q" value="<?= e($query) ?>" placeholder="Buscar por nombre, correo o celular"><button>Buscar</button><?php if($query!==''): ?><a href="<?= url('admin/clientes.php') ?>">Limpiar</a><?php endif ?></form>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Cliente</th><th>Correo</th><th>Celular</th><th>Pedidos</th><th>Compras aprobadas</th><th>Favoritos</th><th>Registro</th></tr></thead><tbody><?php foreach($customers as $customer): ?><tr><td><strong><?= e($customer['name']) ?></strong></td><td><?= e($customer['email']) ?></td><td><?= e($customer['phone']?:'No registrado') ?></td><td><?= (int)$customer['order_count'] ?></td><td><?= money($customer['total_spent']) ?></td><td><?= (int)$customer['favorite_count'] ?></td><td><?= e(date('d/m/Y',strtotime($customer['created_at']))) ?></td></tr><?php endforeach ?><?php if(!$customers): ?><tr><td colspan="7">No se encontraron clientes.</td></tr><?php endif ?></tbody></table></div>
<?php if($pages>1): ?><nav class="suite-pagination"><?php for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++): ?><a class="<?= $i===$page?'active':'' ?>" href="?<?= e(http_build_query(['q'=>$query,'page'=>$i])) ?>"><?= $i ?></a><?php endfor ?></nav><?php endif ?></section>
<?php require __DIR__.'/../includes/admin_footer.php'; ?>
