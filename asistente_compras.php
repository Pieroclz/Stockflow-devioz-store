<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/shopping_agent.php';
$config = require __DIR__ . '/config/shopping_agent.php';
$pageTitle = 'Asistente de compras';
header('Cache-Control: no-store, private');
$source=0; $sourceLabel='Devioz Store'; $reserve='0';
$plan = null; $error = ''; $budget = '20'; $request = ''; $category = ''; $limit = 2;
$reference=''; $comparison=null; $reserved=0; $confirmation=null;
// POST/Redirect/GET: recargar no vuelve a generar ni a enviar el formulario.
$view=$_GET['view']??'';
if ($_SERVER['REQUEST_METHOD']==='GET' && is_string($view) && preg_match('/^[a-f0-9]{24}$/D',$view)) {
    $saved=$_SESSION['shopping_views'][$view]??null;
    if (is_array($saved) && ($saved['expires']??0)>=time()) {
        foreach (['source','sourceLabel','reserve','reserved','plan','error','budget','request','category','limit','reference','comparison','confirmation'] as $key) {
            if(array_key_exists($key,$saved)) $$key=$saved[$key];
        }
    } else {$error='La propuesta guardada venció. Genera otra para consultar precios y stock actuales.';}
}
if ((int)$source !== 0) {
    $source = 0;
    $sourceLabel = 'Devioz Store';
    $plan = null;
    $confirmation = null;
    $error = 'La propuesta anterior usaba una fuente externa y ya no está disponible en el asistente público.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        foreach (['budget','request','category','limit','source','reserve','reference'] as $key) if (isset($_POST[$key]) && !is_string($_POST[$key])) throw new InvalidArgumentException('Datos no válidos.');
        $budget = $_POST['budget'] ?? ''; $request = mb_substr(trim($_POST['request'] ?? ''),0,500);
        $category = $_POST['category'] ?? ''; $limit = filter_var($_POST['limit'] ?? '', FILTER_VALIDATE_INT);
        if (!$limit || $limit < 1 || $limit > 20) throw new InvalidArgumentException('El máximo por producto debe estar entre 1 y 20.');
        if (!in_array($category,array_merge([''],shopping_categories()),true)) throw new InvalidArgumentException('Categoría inválida.');
        $cents = shopping_cents($budget);
        $reference=trim($_POST['reference']??'');
        $referenceCents=$reference===''?null:shopping_cents($reference);
        $reserve=$_POST['reserve']??'0';
        $reserved=in_array(trim($reserve),['0','0.0','0.00','0,0','0,00'],true)?0:shopping_cents($reserve);
        if($reserved >= $cents)throw new InvalidArgumentException('La reserva debe ser menor que tu presupuesto.');
        $source=filter_var($_POST['source']??'0',FILTER_VALIDATE_INT);
        if($source!==0)throw new InvalidArgumentException('El asistente público solo consulta productos disponibles de Devioz Store.');
        if (time() - (int)($_SESSION['shopping_agent_last'] ?? 0) < 3) throw new InvalidArgumentException('Espera unos segundos antes de generar otra propuesta.');
        $_SESSION['shopping_agent_last'] = time();
        $products = shopping_load_catalog(db(),$source,$category,$config,$request);
        $plan = shopping_plan($products,$cents-$reserved,$limit,$request,$category,$config,db());
        $plan['remaining'] += $reserved;
        $confirmation = recommendation_confirmation_create($plan['items'], ['budget'=>($cents-$reserved)/100, 'category'=>$category, 'line_limit'=>RECOMMENDATION_MAX_LINES, 'quantity_limit'=>$limit, 'total_quantity_limit'=>100]);
        if($referenceCents!==null && $plan['items'])$comparison=shopping_compare_reference($referenceCents,$plan['total']);
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
      catch (Throwable $e) { error_log('Shopping agent: '.$e->getMessage()); $error = 'No se pudo consultar el catálogo. Inténtalo nuevamente.'; }
    if (($_POST['chat'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>$error,'plan'=>$plan,'source'=>$source,'sourceLabel'=>$sourceLabel,'reserved'=>$reserved,'comparison'=>$comparison,'confirmation'=>$confirmation], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $view=bin2hex(random_bytes(12));
    $_SESSION['shopping_views']=array_filter($_SESSION['shopping_views']??[],static fn($v)=>is_array($v)&&($v['expires']??0)>=time());
    if(count($_SESSION['shopping_views'])>=5)array_shift($_SESSION['shopping_views']);
    $_SESSION['shopping_views'][$view]=compact('source','sourceLabel','reserve','reserved','plan','error','budget','request','category','limit','reference','comparison','confirmation')+['expires'=>time()+900];
    header('Location: '.url('asistente_compras.php').'?view='.$view,true,303);
    exit;
}
require __DIR__ . '/includes/public_header.php';
?>
<style>
.agent{max-width:960px;margin:36px auto;padding:24px}.agent h1{font-size:clamp(28px,5vw,44px);margin:12px 0}.agent-card{background:white;border:1px solid #dce5df;border-radius:20px;padding:24px;margin:20px 0;color:#19372a}.agent-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.agent label{display:block;font-weight:600}.agent input,.agent select,.agent textarea{display:block;width:100%;padding:12px;border:1px solid #b9c9c1;border-radius:10px;margin:8px 0 16px;font:inherit}.agent button{background:#14613f;color:white;padding:13px 20px;border:0;border-radius:10px;cursor:pointer;font:inherit}.agent table{width:100%;border-collapse:collapse}.agent td,.agent th{padding:12px 6px;text-align:left;border-bottom:1px solid #e4eae6}.agent-summary{display:flex;flex-wrap:wrap;gap:25px;margin:20px 0;font-size:20px}.agent small{display:block;margin:12px 0;color:#52665a}.agent-error{color:#9b1c1c}.agent-table{overflow-x:auto}@media(max-width:650px){.agent-grid{grid-template-columns:1fr}.agent{padding:12px}.agent-card{padding:16px}}@media print{header,footer,.store-topbar,.agent form,.agent button{display:none}}
</style>
<section class="agent assistant-page">
<link rel="stylesheet" href="<?= e(url('assets/css/shopping_chat.css')) ?>">
<div class="assistant-shell">
    <div class="assistant-tabs" aria-label="Secciones de compra">
        <a href="<?= e(url('index.php#catalogo')) ?>"><span aria-hidden="true">⌕</span> Buscar</a>
        <span class="active"><span aria-hidden="true">✦</span> Devioz IA <b>Beta</b></span>
    </div>
    <div id="shoppingChat" data-catalog="<?= e(url('index.php#catalogo')) ?>">
        <div class="chat-heading"><span>Asistente de compras</span><button type="button" id="chatReset">Nueva conversación</button></div>
        <div id="chatMessages" role="log" aria-live="polite" aria-label="Conversación"></div>
        <div id="chatWelcome" class="chat-welcome">
            <div class="chat-orbit" aria-hidden="true">✦</div>
            <h1>Bienvenido a Devioz IA <span>Beta</span></h1>
            <p>Hola, soy tu asistente de compras. Dime qué necesitas y cuánto deseas gastar; consultaré los productos y el stock disponible.</p>
        </div>
        <div class="chat-bottom">
            <div class="chat-prompt-title"><span aria-hidden="true">✣</span> Sugerencias para comenzar</div>
            <div class="chat-suggestions">
                <button type="button">Tengo S/20 para comprar snacks</button>
                <button type="button">Recomiéndame bebidas y galletas</button>
                <button type="button">¿Qué puedo comprar con S/10?</button>
                <button type="button">Quiero una compra variada</button>
            </div>
            <form id="chatForm">
                <label class="sr-only" for="chatInput">Escribe tu consulta</label>
                <div class="chat-compose"><textarea id="chatInput" rows="1" maxlength="350" placeholder="Pregunta qué productos puedes comprar…" required></textarea><button id="chatSend" type="submit" aria-label="Enviar mensaje">➤</button></div>
                <small id="chatStatus" role="status"></small>
            </form>
            <p class="chat-note">Las recomendaciones usan precios y stock registrados. Confirma la disponibilidad antes de finalizar la compra.</p>
        </div>
    </div>
</div>
<details class="chat-settings"><summary>Ajustar presupuesto y preferencias</summary>
<form method="post" action="<?= e(url('asistente_compras.php')) ?>" class="agent-card" id="agentForm">
<?= csrf_field() ?>
<div class="agent-grid">
<label>Reserva para envío u otros gastos (S/)<input name="reserve" type="number" min="0" max="9999" step="0.01" value="<?= e($reserve) ?>" required></label>
<label>Presupuesto (S/)<input name="budget" type="number" min="0.01" max="10000" step="0.01" required value="<?= e($budget) ?>"></label>
<label>Categoría<select name="category"><?php foreach ([''=>'Variado'] + array_combine(shopping_categories(),shopping_categories()) as $value=>$label): ?><option value="<?= e($value) ?>" <?= $category === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
<label>Precio de referencia de la misma lista (opcional, S/)<input name="reference" type="number" min="0.01" max="10000" step="0.01" value="<?= e($reference) ?>" placeholder="Solo si conoces su precio en otra tienda"></label>
<label>Máximo por producto<input name="limit" type="number" min="1" max="20" required value="<?= e($limit) ?>"></label>
</div>
<label>¿Qué te gustaría comprar? (opcional)<textarea name="request" maxlength="500" rows="2" placeholder="Quiero galletas y agua; sin chicles."><?= e($request) ?></textarea></label>
<small><?= shopping_ai_ready($config) ? 'IA configurada ('.e((string)$config['model']).'): tus preferencias y productos candidatos se procesan con el servicio configurado.' : 'Modo básico activo: usa categoría y presupuesto. El texto libre requiere activar la IA en el servidor.' ?></small>
<button type="submit">Preparar mi compra</button>
</form>
</details>
<script src="<?= e(url('assets/js/shopping_chat.js')) ?>" defer></script>
<?php if ($error): ?><p role="alert" class="agent-error"><?= e($error) ?></p><?php endif ?>
<?php if ($plan): ?>
<section class="agent-card" aria-label="Propuesta de compra">
<h2>Tu propuesta · <?= e($sourceLabel) ?></h2><small><?= e($plan['mode']) ?></small>
<?php if (!$plan['items']): ?><p>No hay productos que cumplan la selección y entren en el presupuesto. Esta tienda también puede estar pendiente de carga o tener precios vencidos.</p><?php else: ?>
<div class="agent-table"><table><thead><tr><th>Producto</th><th>Cantidad</th><th>Precio</th><th>Subtotal</th></tr></thead><tbody>
<?php foreach ($plan['items'] as $item): ?><tr><td><?= e($item['name']) ?></td><td><?= (int)$item['quantity'] ?></td><td><?= money($item['unit_cents']/100) ?></td><td><?= money($item['unit_cents']*$item['quantity']/100) ?></td></tr><?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<div class="agent-summary"><strong>Total: <?= money($plan['total']/100) ?></strong><span>Te quedan: <?= money($plan['remaining']/100) ?></span></div>
<?php if($comparison!==null): ?>
<p><strong><?= $comparison['difference']>=0?'Diferencia a favor frente a tu referencia:':'Esta propuesta supera tu referencia en:' ?> <?= money(abs($comparison['difference'])/100) ?> (<?= e(number_format(abs($comparison['percent']),1)) ?> %)</strong></p>
<small>Calculado con la referencia de <?= money($comparison['reference']/100) ?> que ingresaste. Solo representa ahorro si corresponde a los mismos productos, presentaciones y cantidades. No incluye envío ni es una comparación verificada por la tienda.</small>
<?php else: ?><small>Ahorro entre tiendas: pendiente de precios comparables. El saldo del presupuesto no equivale a ahorro.</small><?php endif ?>
<small>Propuesta guardada durante 15 minutos; al recargar no se consulta nuevamente el stock. Pulsa Preparar mi compra para actualizarla.</small>
<small>Reserva incluida en el saldo: <?= money($reserved/100) ?>. El envío real no está calculado. Propuesta por presentaciones, hasta 100 artículos. Favorece variedad; no incluye descuentos por packs ni combos. Precios y stock pueden cambiar antes de confirmar el pedido.</small>
<?php $planCanMutate=is_string($confirmation)&&preg_match('/^[a-f0-9]{48}$/D',$confirmation)===1&&count($plan['items'])<=RECOMMENDATION_MAX_LINES; if ($plan['items']&&$planCanMutate): ?><button type="button" id="usePlan">Usar esta propuesta en Mi lista</button> <button type="button" onclick="window.print()">Imprimir</button>
<small>Usar la propuesta reemplaza tu lista anterior. El pago se confirma después.</small>
<script type="application/json" id="agentPlan"><?= json_encode(array_map(static fn($p)=>['kind'=>'product','id'=>(int)$p['id'],'code'=>$p['code'],'name'=>$p['name'],'category'=>$p['category'],'price'=>$p['unit_cents']/100,'stock'=>(int)$p['stock'],'image_url'=>'','icon'=>'🛍️','presentation'=>'unidad','presentation_label'=>'Unidad','units_per_item'=>1,'quantity'=>$p['quantity']],$plan['items']), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script type="application/json" id="agentConfirmation"><?= json_encode(['token'=>$confirmation,'items'=>array_map(static fn($p)=>['id'=>(int)$p['id'],'quantity'=>(int)$p['quantity'],'price'=>$p['unit_cents']/100],$plan['items'])], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script>document.getElementById('usePlan').addEventListener('click',()=>{if(!confirm('¿Reemplazar Mi lista con esta propuesta?'))return;try{const pending=JSON.parse(document.getElementById('agentConfirmation').textContent);pending.cartItems=JSON.parse(document.getElementById('agentPlan').textContent);if(typeof pending.token!=='string'||!/^[a-f0-9]{48}$/.test(pending.token)||!Array.isArray(pending.cartItems)||pending.cartItems.length>12)throw new Error('La propuesta ya no tiene una confirmación válida.');localStorage.setItem('devioz-ai-confirmation-v1',JSON.stringify(pending));location.href=<?= json_encode(url('index.php#catalogo'), JSON_HEX_TAG) ?>;}catch(e){alert('La propuesta ya no tiene una confirmación válida. Genera una nueva.')}});</script>
<?php endif ?>
</section>
<?php endif ?>
</section>
<script>document.getElementById('agentForm').addEventListener('submit',function(){const b=this.querySelector('button');b.disabled=true;b.textContent='Preparando propuesta…';});</script>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
