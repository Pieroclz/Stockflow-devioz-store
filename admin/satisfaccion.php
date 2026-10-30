<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_admin();
$filter = (int) ($_GET['rating'] ?? 0);
if ($filter < 1 || $filter > 5) $filter = 0;
$summary = db()->query('SELECT COUNT(*) total, ROUND(AVG(rating),2) average_rating FROM customer_feedback')->fetch();
$sql = 'SELECT f.rating,f.comment,f.created_at,o.order_code,o.customer_name FROM customer_feedback f INNER JOIN yape_orders o ON o.id=f.order_id';
if ($filter) $sql .= ' WHERE f.rating=?';
$sql .= ' ORDER BY f.created_at DESC LIMIT 200';
$stmt=db()->prepare($sql);$stmt->execute($filter?[$filter]:[]);$reviews=$stmt->fetchAll();
$pageTitle='Satisfacción del cliente';$pageSubtitle='Opiniones de pedidos entregados';$activePage='feedback';
require __DIR__.'/../includes/admin_header.php';
?>
<section class="panel" style="padding:24px">
<h2>Satisfacción del cliente</h2>
<p>Valoraciones recibidas: <strong><?= (int)$summary['total'] ?></strong> · Promedio: <strong><?= e($summary['average_rating'] ?? '—') ?>/5</strong></p>
<form method="get" style="margin:16px 0"><label>Filtrar estrellas <select name="rating"><option value="0">Todas</option><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>" <?= $filter===$i?'selected':'' ?>><?= $i ?> estrellas</option><?php endfor ?></select></label> <button class="btn btn-primary">Filtrar</button></form>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha</th><th>Pedido</th><th>Cliente</th><th>Valoración</th><th>Comentario</th></tr></thead><tbody>
<?php foreach($reviews as $r): ?><tr><td><?= e($r['created_at']) ?></td><td><?= e($r['order_code']) ?></td><td><?= e($r['customer_name']) ?></td><td><?= str_repeat('★',(int)$r['rating']) ?></td><td><?= nl2br(e($r['comment']??'')) ?></td></tr><?php endforeach ?>
<?php if(!$reviews): ?><tr><td colspan="5">Todavía no hay opiniones.</td></tr><?php endif ?>
</tbody></table></div></section>
<?php require __DIR__.'/../includes/admin_footer.php';
