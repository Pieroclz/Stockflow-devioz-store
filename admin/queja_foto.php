<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_admin();
if ((string)($_SESSION['admin_role']??'')!=='admin') {http_response_code(403);exit;}
$id=(int)($_GET['id']??0);
if($id<=0){http_response_code(404);exit;}
$q=db()->prepare('SELECT photo_data,photo_mime FROM customer_complaints WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
if(!$row || !$row['photo_data'] || !in_array($row['photo_mime'],['image/jpeg','image/png','image/webp'],true)){http_response_code(404);exit;}
header('Content-Type: '.$row['photo_mime']);header('Content-Disposition: inline; filename="evidencia-'.$id.'.jpg"');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');echo $row['photo_data'];
