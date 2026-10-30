<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/unified_catalog.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método no permitido.'); }
verify_csrf();
$action = (string)($_POST['action'] ?? '');
if (!in_array($action,['global_margin','external_price','convert'],true)) { http_response_code(400); exit('Acción no válida.'); }
try {
    if ($action === 'global_margin') {
        save_store_setting('external_default_margin_pct', number_format(validate_margin($_POST['margin'] ?? ''),2,'.',''));
        flash('success','Margen global actualizado.');
        redirect('admin/precios.php');
    }
    $id = max(0,(int)($_POST['external_id'] ?? 0));
    if ($action === 'external_price') {
        save_external_price(db(),$id,validate_money($_POST['price'] ?? '',true),trim((string)($_POST['margin'] ?? ''))===''?null:validate_margin($_POST['margin']));
        flash('success','Precio sugerido actualizado.');
    } else {
        $newId = convert_external_to_inventory(db(),$id,$_POST);
        flash('success','Producto agregado al inventario Devioz.');
        redirect('admin/producto_form.php?id='.$newId);
    }
} catch (Throwable $exception) { flash('danger',$exception->getMessage()); }
redirect('admin/producto_unificado_detalle.php?item=external:'.max(1,(int)($_POST['external_id'] ?? 1)));
