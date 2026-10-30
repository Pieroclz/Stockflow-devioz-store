<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';require_admin();
require_once __DIR__.'/../includes/receipt_layout.php';
$business=receipt_business();$error='';
$labels=['name'=>'Nombre comercial','tagline'=>'Mensaje de la tienda','legal_name'=>'Razón social (opcional)','ruc'=>'RUC real (opcional)','address'=>'Dirección (opcional)','phone'=>'Teléfono (opcional)','website'=>'Página web'];
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();try{
  foreach($labels as $key=>$label){$v=$_POST[$key]??'';if(!is_string($v)||mb_strlen($v)>180||preg_match('/[\r\n\x00]/',$v))throw new InvalidArgumentException('Revisa '.$label);$business[$key]=trim($v);}
  if($business['name']==='')throw new InvalidArgumentException('El nombre comercial es obligatorio.');
  if($business['ruc']!==''&&!preg_match('/^\d{11}$/D',$business['ruc']))throw new InvalidArgumentException('El RUC debe tener 11 dígitos. Ingresa únicamente el dato real.');
  if($business['website']!==''&&(!filter_var($business['website'],FILTER_VALIDATE_URL)||parse_url($business['website'],PHP_URL_SCHEME)!=='https'))throw new InvalidArgumentException('Ingresa una web HTTPS válida.');
  db()->beginTransaction();foreach($business as $key=>$value)save_store_setting('receipt_business_'.$key,$value);db()->commit();
  flash('success','Datos de empresa actualizados.');redirect('admin/datos_empresa.php');
 }catch(InvalidArgumentException $e){$error=$e->getMessage();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();$error='No se pudieron guardar los datos.';}
}
$pageTitle='Datos de empresa';$activePage='receipt-business';require __DIR__.'/../includes/admin_header.php';
?>
<section class="panel" style="max-width:850px;padding:24px;margin:20px"><h1>Datos de empresa para recibos</h1><p>Los campos vacíos no se imprimen. El recibo es un comprobante interno. Los datos se aplican también al consultar recibos anteriores.</p>
<?php if($error): ?><p role="alert"><?= e($error) ?></p><?php endif ?>
<form method="post"><?= csrf_field() ?><div class="form-grid">
<?php foreach($labels as $key=>$label): ?><label><?= e($label) ?><input class="form-control" name="<?= e($key) ?>" value="<?= e($business[$key]) ?>" maxlength="180" <?= $key==='name'?'required':'' ?> <?= $key==='ruc'?'inputmode="numeric" pattern="[0-9]{11}"':'' ?>></label><?php endforeach ?>
</div><button class="btn btn-primary" type="submit">Guardar datos</button></form></section>
<?php require __DIR__.'/../includes/admin_footer.php'; ?>
