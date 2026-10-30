<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/functions.php';
require_admin();
require_once __DIR__.'/../includes/shopping_agent.php';
$config=require __DIR__.'/../config/shopping_agent.php';
$ready=shopping_sources_ready(db()); $message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(!$ready)throw new InvalidArgumentException('Importa primero database/upgrade_shopping_sources.sql en tu base de datos.');
        foreach(['action','source','name','slug','branch'] as $k)if(isset($_POST[$k])&&!is_string($_POST[$k]))throw new InvalidArgumentException('Datos inválidos.');
        $action=$_POST['action']??'';
        if($action==='source'){
            $name=trim($_POST['name']??'');$slug=trim($_POST['slug']??'');$branch=trim($_POST['branch']??'');
            if($name===''||mb_strlen($name)>100||mb_strlen($branch)>120||!preg_match('/^[a-z0-9-]{2,60}$/D',$slug))throw new InvalidArgumentException('Revisa nombre, identificador y sede.');
            $st=db()->prepare('INSERT INTO shopping_sources(slug,name,branch,active)VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name),branch=VALUES(branch)');
            $st->execute([$slug,$name,$branch]);$message='Fuente guardada. Usa un identificador distinto para cada sede.';
        }elseif($action==='import'){
            $source=filter_var($_POST['source']??'',FILTER_VALIDATE_INT);
            if(!$source || $source<1)throw new InvalidArgumentException('Selecciona la fuente.');
            $file=$_FILES['catalog']??null;
            if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Selecciona un archivo JSON válido.');
            if(filesize($file['tmp_name'])>2000000)throw new InvalidArgumentException('Máximo 2 MB.');
            $rows=shopping_validate_import((string)file_get_contents($file['tmp_name']));
            $count=shopping_import(db(),$source,$rows);$message='Carga completa: '.$count.' ofertas procesadas. Las ofertas de más de '.(int)$config['max_age_hours'].' horas no aparecen en el asistente.';
        }else{throw new InvalidArgumentException('Acción inválida.');}
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
     catch(Throwable $e){error_log('Shopping import failed: '.$e->getMessage());$error='No se pudo guardar. Revisa la estructura de la base y vuelve a intentarlo.';}
}
$sources=$ready?db()->query('SELECT s.*,COUNT(o.id) AS offers,MAX(o.verified_at) AS latest FROM shopping_sources s LEFT JOIN shopping_offers o ON o.source_id=s.id GROUP BY s.id ORDER BY s.name')->fetchAll():[];
$pageTitle='Catálogos para compras';$pageSubtitle='Organiza proveedores e importa ofertas desde un solo lugar';$activePage='shopping-sources';
require __DIR__.'/../includes/admin_header.php';
?>
<div class="shopping-sources-page">
    <div class="shopping-sources-intro"><div><span>PROVEEDORES</span><h2>Organiza tus catálogos</h2><p>Guarda cada tienda y sede, luego carga sus ofertas. Sus precios no modifican el stock de Devioz.</p></div><a class="btn btn-secondary" href="<?= url('asistente_compras.php') ?>">Abrir asistente →</a></div>
    <?php if($message): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif ?>
    <?php if($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif ?>
    <?php if(!$ready): ?>
        <div class="alert alert-warning">Importa <code>database/upgrade_shopping_sources.sql</code> en la base de datos actual para activar esta pantalla.</div>
    <?php else: ?>
    <div class="shopping-sources-grid">
        <section class="panel shopping-source-card" aria-labelledby="sourceAddTitle">
            <div class="shopping-step"><span>1</span><div><h3 id="sourceAddTitle">Agregar una tienda</h3><p>También puedes seleccionar una tienda existente para actualizar su sede.</p></div></div>
            <form method="post" class="shopping-source-form" data-shopping-source-form>
                <?= csrf_field() ?><input type="hidden" name="action" value="source">
                <?php if($sources): ?><label>Editar tienda existente<select data-shopping-existing-source><option value="">Crear una tienda nueva</option><?php foreach($sources as $shop): ?><option value="<?= (int)$shop['id'] ?>" data-slug="<?= e($shop['slug']) ?>" data-name="<?= e($shop['name']) ?>" data-branch="<?= e($shop['branch']) ?>"><?= e($shop['name'].' · '.$shop['branch']) ?></option><?php endforeach ?></select></label><?php endif ?>
                <label>Nombre de la tienda<input name="name" maxlength="100" placeholder="Ej. Plaza Vea" autocomplete="organization" required></label>
                <label>Sede o zona<input name="branch" maxlength="120" placeholder="Ej. Santa Clara" required></label>
                <label>Identificador único<input name="slug" pattern="[a-z0-9-]{2,60}" maxlength="60" placeholder="Ej. plaza-vea-santa-clara" required><small>Usa letras minúsculas, números y guiones. Para otra sede, crea otro identificador.</small></label>
                <button class="btn btn-primary" type="submit">Guardar tienda</button>
            </form>
        </section>
        <section class="panel shopping-source-card" aria-labelledby="sourceImportTitle">
            <div class="shopping-step"><span>2</span><div><h3 id="sourceImportTitle">Importar ofertas</h3><p>Elige la tienda y el archivo JSON que contiene sus ofertas.</p></div></div>
            <form method="post" enctype="multipart/form-data" class="shopping-source-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="import">
                <label>Tienda y sede<select name="source" required><option value="">Selecciona una tienda</option><?php foreach($sources as $shop): ?><option value="<?= (int)$shop['id'] ?>"><?= e($shop['name'].' · '.$shop['branch']) ?></option><?php endforeach ?></select></label>
                <label>Archivo JSON<input type="file" name="catalog" accept=".json,application/json" required><small>Hasta 2 MB y 1000 filas por carga.</small></label>
                <details class="shopping-import-help"><summary>¿Cómo debe ser el archivo?</summary><p>Consulta <code>HOSTING_Y_CATALOGOS.md</code>. Cada oferta se actualiza por su identificador externo. Para retirarla, usa <code>available: false</code>.</p></details>
                <button class="btn btn-primary" type="submit" <?= !$sources ? 'disabled' : '' ?>>Validar e importar</button>
                <?php if(!$sources): ?><small class="shopping-source-hint">Primero registra una tienda en el paso 1.</small><?php endif ?>
            </form>
        </section>
    </div>
    <section class="panel shopping-source-list"><div class="panel-heading"><div><h2>Tiendas registradas</h2><p><?= count($sources) ?> fuentes configuradas · ofertas actualizadas durante las últimas <?= (int)$config['max_age_hours'] ?> horas.</p></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Tienda / sede</th><th>Ofertas guardadas</th><th>Última verificación (UTC)</th></tr></thead><tbody><?php foreach($sources as $shop): ?><tr><td><strong><?= e($shop['name']) ?></strong><small><?= e($shop['branch']) ?></small></td><td><?= number_format((int)$shop['offers']) ?></td><td><?= e($shop['latest']??'Sin datos cargados') ?></td></tr><?php endforeach ?><?php if(!$sources): ?><tr><td colspan="3">Aún no has configurado ninguna tienda.</td></tr><?php endif ?></tbody></table></div></section>
    <?php endif ?>
</div>
<script>
document.querySelector('[data-shopping-existing-source]')?.addEventListener('change', function () {
    const form = this.closest('form');
    const selected = this.selectedOptions[0];
    for (const field of ['slug', 'name', 'branch']) {
        form.elements.namedItem(field).value = selected?.value ? (selected.dataset[field] || '') : '';
    }
});
</script>
<?php require __DIR__.'/../includes/admin_footer.php'; ?>
