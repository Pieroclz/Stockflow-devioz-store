<?php
declare(strict_types=1);
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/master_catalog.php';
require_once __DIR__.'/includes/ai_recommendations.php';
$pageTitle='Mi cuenta';$error='';$authAction=(string)($_POST['action']??'login');

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='register'){
            $name=mb_substr(trim((string)($_POST['name']??'')),0,120);$email=mb_strtolower(trim((string)($_POST['email']??'')));$phone=preg_replace('/[^0-9+]/','',(string)($_POST['phone']??''));$password=(string)($_POST['password']??'');
            if(mb_strlen($name)<3||mb_strlen($name)>120)throw new RuntimeException('Escribe tu nombre completo (mínimo 3 caracteres).');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new RuntimeException('Escribe un correo válido.');
            if($phone!==''&&(strlen(preg_replace('/\D/','',$phone))<7||strlen(preg_replace('/\D/','',$phone))>15))throw new RuntimeException('El celular debe tener entre 7 y 15 dígitos.');
            if(strlen($password)<8||strlen($password)>72)throw new RuntimeException('La contraseña debe tener entre 8 y 72 caracteres.');
            if(!hash_equals($password,(string)($_POST['password_confirm']??'')))throw new RuntimeException('Las contraseñas no coinciden.');
            $statement=db()->prepare('INSERT INTO customers(name,email,phone,password) VALUES(?,?,?,?)');$statement->execute([$name,$email,mb_substr($phone,0,20),password_hash($password,PASSWORD_DEFAULT)]);
            $_SESSION['customer_id']=(int)db()->lastInsertId();$_SESSION['customer_name']=$name;session_regenerate_id(true);flash('success','Tu cuenta fue creada.');redirect('mi_cuenta.php');
        }
        if($action==='login'){
            $email=mb_strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');
            $statement=db()->prepare('SELECT id,name,password FROM customers WHERE email=? AND active=1 LIMIT 1');$statement->execute([$email]);$customer=$statement->fetch();
            if(!$customer||!password_verify($password,(string)$customer['password']))throw new RuntimeException('El correo o la contraseña no son correctos.');
            if(password_needs_rehash((string)$customer['password'],PASSWORD_DEFAULT))db()->prepare('UPDATE customers SET password=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),(int)$customer['id']]);
            $_SESSION['customer_id']=(int)$customer['id'];$_SESSION['customer_name']=(string)$customer['name'];session_regenerate_id(true);flash('success','Sesión iniciada correctamente.');redirect('mi_cuenta.php');
        }
        require_customer();
        if($action==='profile_update'){
            $name=mb_substr(trim((string)($_POST['name']??'')),0,120);
            $email=mb_strtolower(trim((string)($_POST['email']??'')));
            $phone=preg_replace('/[^0-9+]/','',(string)($_POST['phone']??''));
            if(mb_strlen($name)<3)throw new RuntimeException('El nombre debe tener al menos 3 caracteres.');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new RuntimeException('Correo electrónico no válido.');
            $digits=preg_replace('/\D/','',$phone);
            if($phone!==''&&(strlen($digits)<7||strlen($digits)>15))throw new RuntimeException('El teléfono debe tener entre 7 y 15 dígitos.');
            $q=db()->prepare('UPDATE customers SET name=?,email=?,phone=? WHERE id=? AND active=1');
            $q->execute([$name,$email,mb_substr($phone,0,20),customer_id()]);
            $_SESSION['customer_name']=$name;
            flash('success','Tus datos se actualizaron correctamente.');redirect('mi_cuenta.php');
        }
        if($action==='password_update'){
            $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');
            $q=db()->prepare('SELECT password FROM customers WHERE id=? AND active=1');$q->execute([customer_id()]);$stored=$q->fetchColumn();
            if(!$stored||!password_verify($current,(string)$stored))throw new RuntimeException('La contraseña actual es incorrecta.');
            if(strlen($new)<8||strlen($new)>72)throw new RuntimeException('La nueva contraseña debe tener entre 8 y 72 caracteres.');
            if(!hash_equals($new,(string)($_POST['new_password_confirm']??'')))throw new RuntimeException('Las nuevas contraseñas no coinciden.');
            db()->prepare('UPDATE customers SET password=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),customer_id()]);
            session_regenerate_id(true);flash('success','Contraseña actualizada.');redirect('mi_cuenta.php');
        }
        if($action==='avatar_upload'){
            if(!isset($_FILES['avatar'])||!is_array($_FILES['avatar'])||($_FILES['avatar']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Selecciona una foto válida.');
            $file=$_FILES['avatar'];
            if((int)$file['size']>2*1024*1024|| (int)$file['size']<1)throw new RuntimeException('La foto debe pesar menos de 2 MB.');
            if(!is_uploaded_file((string)$file['tmp_name']))throw new RuntimeException('Carga de archivo inválida.');
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
            $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if(!isset($extensions[$mime])||@getimagesize((string)$file['tmp_name'])===false)throw new RuntimeException('Usa una imagen JPG, PNG o WebP.');
            $dir=__DIR__.'/uploads/customer_avatars';
            if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear la carpeta de fotos.');
            $filename=bin2hex(random_bytes(18)).'.'.$extensions[$mime];
            $destination=$dir.'/'.$filename;
            if(!move_uploaded_file((string)$file['tmp_name'],$destination))throw new RuntimeException('No se pudo guardar la foto.');
            try{
                $q=db()->prepare('SELECT avatar_path FROM customers WHERE id=?');$q->execute([customer_id()]);$previous=(string)$q->fetchColumn();
                db()->prepare('UPDATE customers SET avatar_path=? WHERE id=?')->execute(['uploads/customer_avatars/'.$filename,customer_id()]);
            }catch(Throwable $e){@unlink($destination);throw $e;}
            if(preg_match('~^uploads/customer_avatars/[a-f0-9]{36}\.(?:jpg|png|webp)$~',$previous))@unlink(__DIR__.'/'.$previous);
            flash('success','Foto de perfil actualizada.');redirect('mi_cuenta.php');
        }
        if($action==='address_add'){
            $label=mb_substr(trim((string)($_POST['label']??'Casa')),0,60);$district=mb_substr(trim((string)($_POST['district']??'')),0,100);$address=mb_substr(trim((string)($_POST['address']??'')),0,255);$reference=mb_substr(trim((string)($_POST['reference']??'')),0,255);
            if($district===''||mb_strlen($address)<5)throw new RuntimeException('Completa el distrito y la dirección.');
            $statement=db()->prepare('INSERT INTO customer_addresses(customer_id,label,district,address,reference,is_default) VALUES(?,?,?,?,?,?)');$statement->execute([customer_id(),$label?:'Casa',$district,$address,$reference,(int)($_POST['is_default']??0)]);flash('success','Dirección guardada.');redirect('mi_cuenta.php');
        }
        if($action==='address_update'){
            $id=(int)($_POST['id']??0);
            $label=mb_substr(trim((string)($_POST['label']??'Casa')),0,60);
            $district=mb_substr(trim((string)($_POST['district']??'')),0,100);
            $address=mb_substr(trim((string)($_POST['address']??'')),0,255);
            $reference=mb_substr(trim((string)($_POST['reference']??'')),0,255);
            if($id<1||$district===''||mb_strlen($address)<5)throw new RuntimeException('Completa correctamente la dirección.');
            $q=db()->prepare('SELECT id FROM customer_addresses WHERE id=? AND customer_id=?');$q->execute([$id,customer_id()]);
            if(!$q->fetchColumn())throw new RuntimeException('Dirección no encontrada.');
            $database=db();$database->beginTransaction();
            try{
                if(!empty($_POST['is_default']))$database->prepare('UPDATE customer_addresses SET is_default=0 WHERE customer_id=?')->execute([customer_id()]);
                $q=$database->prepare('UPDATE customer_addresses SET label=?,district=?,address=?,reference=?,is_default=CASE WHEN ?=1 THEN 1 ELSE is_default END WHERE id=? AND customer_id=?');
                $q->execute([$label?:'Casa',$district,$address,$reference,!empty($_POST['is_default'])?1:0,$id,customer_id()]);
                $database->commit();
            }catch(Throwable $e){if($database->inTransaction())$database->rollBack();throw $e;}
            flash('success','Dirección actualizada.');redirect('mi_cuenta.php#mis-direcciones');
        }
        if($action==='address_delete'){
            db()->prepare('DELETE FROM customer_addresses WHERE id=? AND customer_id=?')->execute([(int)($_POST['id']??0),customer_id()]);flash('success','Dirección eliminada.');redirect('mi_cuenta.php');
        }
    }catch(PDOException $exception){$error=$exception->getCode()==='23000'?'Ese correo ya está registrado.':'No se pudo guardar la información.';}
    catch(RuntimeException $exception){$error=$exception->getMessage();}
}

$flashMessage=pull_flash();$profile=customer_profile();$addresses=[];$orders=[];$favoriteOffers=[];$favoriteProducts=[];
$avatarPath='';if($profile){$avatarQuery=db()->prepare('SELECT avatar_path FROM customers WHERE id=?');$avatarQuery->execute([customer_id()]);$avatarPath=(string)($avatarQuery->fetchColumn()?:'');if(!preg_match('~^uploads/customer_avatars/[a-f0-9]{36}\.(jpg|png|webp)$~',$avatarPath))$avatarPath='';}
$authView=$authAction==='register'||($_SERVER['REQUEST_METHOD']!=='POST'&&($_GET['view']??'')==='register')?'register':'login';
if($profile){
    $statement=db()->prepare('SELECT * FROM customer_addresses WHERE customer_id=? ORDER BY is_default DESC,id DESC');$statement->execute([customer_id()]);$addresses=$statement->fetchAll();
    $statement=db()->prepare('SELECT id,order_code,expected_amount,status,fulfillment_status,created_at FROM yape_orders WHERE customer_id=? ORDER BY id DESC LIMIT 20');$statement->execute([customer_id()]);$orders=$statement->fetchAll();
    $driverByOrder=[];
    if($orders){
        $orderIds=array_map(static fn(array $o):int=>(int)$o['id'],$orders);
        $marks=implode(',',array_fill(0,count($orderIds),'?'));
        $dq=db()->prepare('SELECT a.order_id,d.name,d.avatar_path FROM delivery_assignments a INNER JOIN delivery_drivers d ON d.id=a.driver_id WHERE a.order_id IN ('.$marks.')');
        $dq->execute($orderIds);
        foreach($dq->fetchAll() as $row){$driverByOrder[(int)$row['order_id']]=$row;}
    }
    $orderItemsByOrder=[];
    if($orders){
        $ids=array_map(static fn(array $o):int=>(int)$o['id'],$orders);
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $itemQuery=db()->prepare('SELECT order_id,item_name,quantity FROM yape_order_items WHERE order_id IN ('.$placeholders.') ORDER BY id ASC');
        $itemQuery->execute($ids);
        foreach($itemQuery->fetchAll() as $item){$orderItemsByOrder[(int)$item['order_id']][]=$item;}
    }
    $statement=db()->prepare("SELECT p.id,p.name,p.category,p.description,p.price,p.image_url FROM customer_favorites f INNER JOIN products p ON p.id=f.item_id WHERE f.customer_id=? AND f.item_type='product' AND ".master_product_visibility_sql('p').' AND '.public_product_text_visibility_sql('p')." ORDER BY f.id DESC");$statement->execute([customer_id()]);$favoriteProducts=array_values(array_filter($statement->fetchAll(),static fn(array $product):bool=>!is_age_restricted_product($product)));
}
require __DIR__.'/includes/public_header.php';
?>
<?php if($profile): ?><section class="suite-hero"><div class="container"><span>CLIENTES DEVIOZ</span><h1>Hola, <?= e($profile['name']) ?></h1><p>Guarda favoritos y direcciones, y revisa tus pedidos desde un solo lugar.</p></div></section><?php endif; ?>
<section class="suite-section"><div class="container">
<?php if($flashMessage): ?><div class="alert alert-<?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div><?php endif ?>
<?php if($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif ?>
<?php if(!$profile): ?>
<div class="account-access">
  <nav class="account-access-tabs" aria-label="Acceso a tu cuenta">
    <a href="<?= url('mi_cuenta.php?view=login') ?>" class="<?= $authView==='login'?'is-current':'' ?>" <?= $authView==='login'?'aria-current="page"':'' ?>>Iniciar sesión</a>
    <a href="<?= url('mi_cuenta.php?view=register') ?>" class="<?= $authView==='register'?'is-current':'' ?>" <?= $authView==='register'?'aria-current="page"':'' ?>>Crear cuenta</a>
  </nav>
  <div class="account-access-layout">
    <div class="account-access-intro"><span>DEVIOZ STORE</span><h1><?= $authView==='register'?'Crea tu cuenta':'Qué bueno verte de nuevo' ?></h1><p><?= $authView==='register'?'Guarda tus datos y consulta tus pedidos en un solo lugar.':'Ingresa para revisar tus pedidos, direcciones y favoritos.' ?></p><div class="account-access-benefits"><span>✓ Tus pedidos en un solo lugar</span><span>✓ Direcciones guardadas</span><span>✓ Favoritos a mano</span></div></div>
    <?php if($authView==='login'): ?>
    <form class="suite-card account-access-form" method="post" action="<?= url('mi_cuenta.php?view=login') ?>" id="ingresar"><span class="account-access-eyebrow">MI CUENTA</span><h2>Iniciar sesión</h2><p>Escribe los datos de tu cuenta.</p><?= csrf_field() ?><input type="hidden" name="action" value="login"><label>Correo electrónico<input type="email" name="email" maxlength="190" value="<?= e($authAction==='login'?(string)($_POST['email']??''):'') ?>" placeholder="nombre@correo.com" required autocomplete="email"></label><label>Contraseña<input type="password" name="password" required autocomplete="current-password" data-password-field></label><label class="check-line"><input type="checkbox" data-show-password> Mostrar contraseña</label><button class="btn btn-primary" type="submit">Ingresar a mi cuenta</button><p class="account-access-switch">¿Aún no tienes cuenta? <a href="<?= url('mi_cuenta.php?view=register') ?>">Crear cuenta</a></p></form>
    <?php else: ?>
    <form class="suite-card account-access-form" method="post" action="<?= url('mi_cuenta.php?view=register') ?>" id="crear-cuenta"><span class="account-access-eyebrow">EMPIEZA AQUÍ</span><h2>Crear cuenta</h2><p>Completa tus datos para registrarte.</p><?= csrf_field() ?><input type="hidden" name="action" value="register"><label>Nombre completo<input name="name" maxlength="120" minlength="3" value="<?= e($authAction==='register'?(string)($_POST['name']??''):'') ?>" required autocomplete="name"></label><label>Correo electrónico<input type="email" name="email" maxlength="190" value="<?= e($authAction==='register'?(string)($_POST['email']??''):'') ?>" placeholder="nombre@correo.com" required autocomplete="email"></label><label>Celular (opcional)<input name="phone" maxlength="20" inputmode="tel" autocomplete="tel" value="<?= e($authAction==='register'?(string)($_POST['phone']??''):'') ?>"></label><label>Contraseña<input type="password" name="password" minlength="8" maxlength="72" required autocomplete="new-password" data-password-field><small>Usa entre 8 y 72 caracteres.</small></label><label>Confirmar contraseña<input type="password" name="password_confirm" minlength="8" maxlength="72" required autocomplete="new-password" data-password-field></label><label class="check-line"><input type="checkbox" data-show-password> Mostrar contraseñas</label><button class="btn btn-primary" type="submit">Crear mi cuenta</button><p class="account-access-switch">¿Ya tienes cuenta? <a href="<?= url('mi_cuenta.php?view=login') ?>">Iniciar sesión</a></p></form>
    <?php endif; ?>
  </div>
</div>
<script>document.querySelectorAll('[data-show-password]').forEach(toggle=>toggle.addEventListener('change',()=>toggle.closest('form').querySelectorAll('[data-password-field]').forEach(field=>field.type=toggle.checked?'text':'password')));</script>
<?php else: ?>
<style>
.account-profile-hero{background:linear-gradient(120deg,#102c40,#0d786b);border-radius:22px;padding:25px;color:white;display:flex;align-items:center;gap:22px;margin-bottom:18px;flex-wrap:wrap}
.account-avatar{width:100px;height:100px;border-radius:50%;object-fit:cover;border:4px solid #75f1c8;background:#e3fff5;color:#0b6150;display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:800}
.account-profile-hero h2{color:white;margin:0 0 6px;font-size:25px}.account-profile-hero p{color:#d3f8ef;margin:0}
.account-profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin:18px 0}
.account-profile-card{background:#fff;border:1px solid #d9eae4;border-radius:18px;padding:22px;box-shadow:0 10px 25px rgba(12,67,55,.06)}
.account-profile-card h2{font-size:20px;color:#143b40;margin:0 0 16px}.account-profile-card label{display:block;margin:10px 0;font-size:14px;font-weight:600;color:#36565b}
.account-profile-card input:not([type=file]){width:100%;box-sizing:border-box;min-height:44px;margin-top:6px;border:1px solid #c7dcd6;border-radius:10px;padding:10px;font-size:15px}
.account-profile-card input[type=file]{width:100%;margin:12px 0}.account-profile-card button{min-height:45px}
.account-profile-card small{display:block;color:#56716d;margin:8px 0}
.account-address-editor{border-top:1px solid #d9eae4;margin-top:22px;padding-top:18px}.account-address-editor h3{color:#0b7965;font-size:18px;margin:0 0 8px}.account-address-editor p{font-size:14px;color:#52736d}.account-address-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.account-address-actions .btn{min-height:38px}.account-profile-card .check-line input[type=checkbox]{width:auto;min-height:auto;margin:0 8px 0 0}
@media(max-width:740px){.account-profile-grid{grid-template-columns:1fr}.account-profile-hero{padding:18px}}
</style>
<div class="account-profile-hero">
<?php if($avatarPath): ?><img class="account-avatar" src="<?= e(url($avatarPath)) ?>" alt="Foto de perfil"><?php else: ?><div class="account-avatar" aria-label="Avatar sin foto"><?= e(mb_strtoupper(mb_substr((string)$profile['name'],0,1))) ?></div><?php endif; ?>
<div><span style="font-size:12px;letter-spacing:2px;color:#8af4d3">MI ESPACIO DEVIOZ</span><h2>¡Hola, <?= e($profile['name']) ?>!</h2><p>Personaliza tu cuenta y administra tus compras.</p></div>
</div>
<div class="account-profile-grid">
<section class="account-profile-card"><h2>📷 Mi foto de perfil</h2><p>Sube una foto para identificar tu cuenta.</p>
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="avatar_upload"><label for="avatar-upload">Elegir imagen JPG, PNG o WebP (máximo 2 MB)</label><input id="avatar-upload" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required><button class="btn btn-primary" type="submit">Guardar mi foto</button></form></section>
<section class="account-profile-card"><h2>✏️ Editar mis datos</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="profile_update"><label>Nombre completo<input name="name" value="<?= e($profile['name']) ?>" required minlength="3" maxlength="120" autocomplete="name"></label><label>Correo electrónico<input type="email" name="email" value="<?= e($profile['email']) ?>" required maxlength="190" autocomplete="email"></label><label>Celular<input name="phone" value="<?= e($profile['phone']??'') ?>" maxlength="20" autocomplete="tel"></label><button class="btn btn-primary" type="submit">Guardar mis datos</button></form>
<div class="account-address-editor" id="mis-direcciones"><h3>📍 Mi dirección de entrega</h3><p>Agrega una dirección nueva o edita una que ya guardaste.</p>
<form method="post" id="account-address-form"><?= csrf_field() ?><input type="hidden" name="action" id="address-action" value="address_add"><input type="hidden" name="id" id="address-id" value="">
<label>Nombre de la dirección<input name="label" id="address-label" placeholder="Casa, trabajo..." maxlength="60"></label>
<label>Distrito<input name="district" id="address-district" maxlength="100" required placeholder="Ej.: Santa Anita"></label>
<label>Dirección completa<input name="address" id="address-full" maxlength="255" minlength="5" required placeholder="Calle, número, urbanización..."></label>
<label>Referencia (opcional)<input name="reference" id="address-reference" maxlength="255" placeholder="Puerta, piso o punto de referencia"></label>
<label class="check-line"><input type="checkbox" name="is_default" id="address-default" value="1"> Dirección principal</label>
<button class="btn btn-primary" type="submit" id="address-save">Guardar dirección</button>
<button class="btn btn-secondary" type="button" id="address-reset" hidden>Cancelar edición</button>
</form>
<?php if($addresses): ?><div class="account-address-inline"><h4>Direcciones registradas</h4>
<?php foreach($addresses as $address): ?><div class="address-row"><div><strong><?= e($address['label']) ?><?= $address['is_default']?' · Principal':'' ?></strong><span><?= e($address['address'].', '.$address['district']) ?></span></div><div class="account-address-actions"><button type="button" class="btn btn-secondary" data-address-edit data-id="<?= (int)$address['id'] ?>" data-label="<?= e($address['label']) ?>" data-district="<?= e($address['district']) ?>" data-address="<?= e($address['address']) ?>" data-reference="<?= e($address['reference']) ?>" data-default="<?= (int)$address['is_default'] ?>">Editar</button><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="address_delete"><input type="hidden" name="id" value="<?= (int)$address['id'] ?>"><button type="submit" onclick="return confirm('¿Eliminar esta dirección?')">Eliminar</button></form></div></div><?php endforeach ?></div><?php endif; ?>
</div></section>
<section class="account-profile-card"><h2>🔒 Cambiar contraseña</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password_update"><label>Contraseña actual<input type="password" name="current_password" required autocomplete="current-password"></label><label>Nueva contraseña<input type="password" name="new_password" required minlength="8" maxlength="72" autocomplete="new-password"></label><label>Confirmar nueva contraseña<input type="password" name="new_password_confirm" required minlength="8" maxlength="72" autocomplete="new-password"></label><button class="btn btn-primary" type="submit">Actualizar contraseña</button></form></section>
<section class="account-profile-card account-favorites-card" aria-label="Mis favoritos">
<h2>💚 Mis favoritos</h2>
<?php $allFavorites=array_merge($favoriteProducts,$favoriteOffers); ?>
<?php if($allFavorites): ?>
<div class="account-fav-slider" data-fav-slider>
  <div class="account-fav-window" aria-live="polite">
  <?php foreach($allFavorites as $favIndex=>$favorite):
    $name=$favorite['name']??$favorite['producto']??'Producto';
    $price=$favorite['price']??$favorite['precio_actual']??0;
    $image=$favorite['image_url']??$favorite['imagen']??'';
  ?>
  <article class="account-fav-slide" data-fav-slide <?= $favIndex===0?'':'hidden' ?>>
    <?php if($image): ?><img loading="lazy" src="<?= e(image_src($image)) ?>" alt="<?= e($name) ?>"><?php else: ?><div class="account-fav-placeholder" aria-hidden="true">♥</div><?php endif; ?>
    <div class="account-fav-info"><strong><?= e($name) ?></strong><span><?= money($price) ?></span></div>
  </article>
  <?php endforeach; ?>
  </div>
  <div class="account-fav-controls">
    <button type="button" class="account-fav-arrow" data-fav-prev aria-label="Favorito anterior">←</button>
    <span data-fav-counter>1 de <?= count($allFavorites) ?></span>
    <button type="button" class="account-fav-arrow" data-fav-next aria-label="Siguiente favorito">→</button>
  </div>
</div>
<?php else: ?><p class="account-fav-empty">Aún no tienes favoritos. Guarda productos desde el catálogo para verlos aquí.</p><?php endif; ?>
</section>
</div>
<style>
.account-order-history{width:100%;margin:20px 0;background:#fff;border:1px solid #d6e8e2;border-radius:20px;padding:22px;box-sizing:border-box}
.account-order-history h2{font-size:23px;color:#123c40;margin:0 0 5px}
.account-order-history .order-intro{color:#53736f;font-size:14px;margin:0 0 18px}
.account-order-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.account-order-card{display:block;padding:18px;border-radius:16px;border:1px solid #d8e9e3;background:linear-gradient(145deg,#fff,#f5fbf9);color:#173b3d;text-decoration:none!important;transition:transform .2s,box-shadow .2s}
.account-order-card:hover{transform:translateY(-3px);box-shadow:0 12px 24px rgba(17,88,76,.12)}
.account-order-top{display:flex;justify-content:space-between;gap:10px;align-items:start;flex-wrap:wrap}
.account-order-code{font-weight:800;font-size:15px;overflow-wrap:anywhere}
.account-order-date{display:block;font-size:12px;color:#64827c;margin-top:5px}
.account-order-status{font-size:12px;font-weight:800;padding:7px 10px;border-radius:30px;background:#fff1d8;color:#875800}
.account-order-status.is-delivered{background:#d9f9e8;color:#087a54}
.account-order-status.is-cancelled{background:#ffe5e5;color:#a53030}
.account-order-products{font-size:14px;line-height:1.5;margin:14px 0 12px;color:#405e5c}
.account-order-bottom{display:flex;justify-content:space-between;align-items:center;gap:12px;border-top:1px solid #dcece7;padding-top:12px}
.account-order-bottom strong{font-size:17px;color:#087e69}
.account-order-bottom span{font-size:13px;color:#087e69;font-weight:700}
.account-logout-footer{text-align:center;margin:28px 0 12px}
.account-logout-button{display:inline-flex;align-items:center;justify-content:center;min-height:49px;padding:0 35px;border-radius:12px;background:#d93642;color:#fff!important;font-size:15px;font-weight:800;text-decoration:none!important;box-shadow:0 9px 22px rgba(217,54,66,.18)}
.account-logout-button:hover{background:#b92531}
.account-address-inline{margin-top:18px;border-top:1px solid #d9eae4;padding-top:15px}
.account-address-inline h4{margin:0 0 8px;color:#174d46;font-size:15px}
.account-address-inline .address-row{margin:8px 0;padding:10px;border:1px solid #e0ede8;border-radius:12px}
@media(max-width:720px){.account-order-grid{grid-template-columns:1fr}.account-order-history{padding:15px}.account-order-card{padding:14px}}

.account-assigned-driver{display:flex;gap:11px;align-items:center;margin:12px 0;padding:10px;border-radius:12px;background:#fff4e9;border:1px solid #f6d8b8}.account-assigned-driver img,.account-driver-placeholder{width:48px;height:48px;object-fit:cover;border-radius:50%;background:#ffe1bf;display:grid;place-items:center;font-size:24px}.account-assigned-driver small{display:block;color:#995c2c;font-size:12px}.account-assigned-driver strong{display:block;color:#713e1e;font-size:15px}.account-awaiting-driver{color:#995c2c;font-size:13px}
/* Carrusel de favoritos al lado del cambio de contraseña */
.account-profile-grid .account-favorites-card{min-width:0;overflow:hidden}
.account-fav-window{min-height:220px;display:flex;align-items:center;justify-content:center}
.account-fav-slide{width:100%;text-align:center}
.account-fav-slide[hidden]{display:none!important}
.account-fav-slide img,.account-fav-placeholder{width:100%;height:150px;object-fit:contain;border-radius:14px;background:#f2faf7}
.account-fav-placeholder{display:flex;align-items:center;justify-content:center;color:#13a780;font-size:54px}
.account-fav-info{display:flex;flex-direction:column;gap:6px;padding:12px 0 4px;overflow-wrap:anywhere}
.account-fav-info strong{font-size:16px;color:#173d3b}
.account-fav-info span{font-size:17px;font-weight:800;color:#078467}
.account-fav-controls{display:flex;align-items:center;justify-content:center;gap:20px;margin-top:12px}
.account-fav-controls span{font-size:13px;color:#526b65;font-weight:700}
.account-fav-arrow{width:48px;height:48px;border:1px solid #a5e8d5;border-radius:13px;background:#d9f9ee;color:#087e65;font-size:24px;font-weight:800;cursor:pointer}
.account-fav-arrow:hover{background:#15cba0;color:#083b30}
.account-fav-arrow:focus-visible{outline:3px solid #176dd2;outline-offset:2px}
.account-fav-empty{font-size:14px;line-height:1.6;color:#56746d}
</style>
<section class="account-order-history"><h2>📦 Mis pedidos anteriores</h2><p class="order-intro">Revisa qué compraste y si tu pedido ya fue entregado o continúa en espera.</p>
<?php if($orders): ?><div class="account-order-grid">
<?php foreach($orders as $order):
    $state=strtolower((string)($order['fulfillment_status']??''));
    $paymentState=strtolower((string)($order['status']??''));
    $cancelled=$state==='cancelado'||$state==='cancelada'||$paymentState==='cancelado'||$paymentState==='cancelada';
    $delivered=$state==='entregado';
    $statusLabel=$cancelled?'Cancelado':($delivered?'Entregado':(in_array($state,['listo','listo_para_entregar'],true)?'Listo para entregar':(in_array($state,['preparacion','en_preparacion'],true)?'En preparación':'En espera')));
    $statusClass=$cancelled?'is-cancelled':($delivered?'is-delivered':'');
    $items=$orderItemsByOrder[(int)$order['id']]??[];
    $names=[];foreach(array_slice($items,0,3) as $item){$names[]=(int)$item['quantity'].' × '.(string)$item['item_name'];}
    $products=$names?implode(' · ',$names).(count($items)>3?' · +'.(count($items)-3).' más':''):'Consulta los productos en el detalle del pedido';
?>
<a class="account-order-card" href="<?= url('mi_pedido.php?id='.(int)$order['id']) ?>">
<div class="account-order-top"><div><span class="account-order-code"><?= e($order['order_code']) ?></span><small class="account-order-date"><?= e(date('d/m/Y H:i',strtotime((string)$order['created_at']))) ?></small></div><span class="account-order-status <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></div>
<p class="account-order-products">🛒 <?= e($products) ?></p>
<?php $assignedDriver=$driverByOrder[(int)$order['id']]??null; ?>
<?php if($assignedDriver && !$cancelled): ?>
<div class="account-assigned-driver">
<?php $driverPhoto=(string)($assignedDriver['avatar_path']??''); ?>
<?php if(preg_match('~^uploads/driver_avatars/[a-f0-9]{36}\.(jpg|png|webp)$~',$driverPhoto)): ?>
<img src="<?= e(url($driverPhoto)) ?>" alt="Foto del motorizado" loading="lazy">
<?php else: ?><span class="account-driver-placeholder">🛵</span><?php endif; ?>
<div><small>Motorizado asignado</small><strong><?= e($assignedDriver['name']) ?></strong></div>
</div>
<?php elseif(!$cancelled&&!$delivered): ?><p class="account-awaiting-driver">🛵 Esperando asignación de motorizado</p><?php endif; ?>
<div class="account-order-bottom"><strong><?= money($order['expected_amount']) ?></strong><span>Ver detalles →</span></div>
</a>
<?php endforeach ?></div><?php else: ?><p>Aún no tienes pedidos asociados a tu cuenta.</p><?php endif ?></section>


<script>
(function(){
  const slider=document.querySelector('[data-fav-slider]');
  if(!slider)return;
  const slides=Array.from(slider.querySelectorAll('[data-fav-slide]'));
  const counter=slider.querySelector('[data-fav-counter]');
  let active=0;
  function show(index){
    active=(index+slides.length)%slides.length;
    slides.forEach((slide,i)=>{slide.hidden=i!==active;});
    counter.textContent=(active+1)+' de '+slides.length;
  }
  slider.querySelector('[data-fav-prev]').addEventListener('click',()=>show(active-1));
  slider.querySelector('[data-fav-next]').addEventListener('click',()=>show(active+1));
  show(0);
})();
</script>
<div class="account-logout-footer"><a class="account-logout-button" href="<?= url('cliente_salir.php') ?>">Cerrar sesión</a></div>
<?php endif ?>
<script>
(function(){
 const form=document.getElementById('account-address-form');if(!form)return;
 const fields={id:document.getElementById('address-id'),action:document.getElementById('address-action'),label:document.getElementById('address-label'),district:document.getElementById('address-district'),address:document.getElementById('address-full'),reference:document.getElementById('address-reference'),default:document.getElementById('address-default')};
 const save=document.getElementById('address-save'),reset=document.getElementById('address-reset');
 function clear(){form.reset();fields.id.value='';fields.action.value='address_add';save.textContent='Guardar dirección';reset.hidden=true;}
 document.querySelectorAll('[data-address-edit]').forEach(button=>button.addEventListener('click',()=>{
   fields.id.value=button.dataset.id;fields.action.value='address_update';fields.label.value=button.dataset.label||'';
   fields.district.value=button.dataset.district||'';fields.address.value=button.dataset.address||'';
   fields.reference.value=button.dataset.reference||'';fields.default.checked=button.dataset.default==='1';
   save.textContent='Guardar cambios de dirección';reset.hidden=false;
   document.getElementById('mis-direcciones').scrollIntoView({behavior:'smooth',block:'start'});fields.address.focus({preventScroll:true});
 }));
 reset.addEventListener('click',clear);
})();
</script>
</div></section>
<?php require __DIR__.'/includes/public_footer.php'; ?>
