<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';
$orderId=max(0,(int)($_GET['id']??0));
$token=(string)($_GET['token']??'');
$valid=false;
if($orderId>0&&strlen($token)===64&&ctype_xdigit($token)){
 $s=db()->prepare("SELECT t.driver_token_hash FROM delivery_tracking t
 JOIN yape_orders o ON o.id=t.order_id
 JOIN delivery_assignments a ON a.order_id=t.order_id
 JOIN delivery_drivers d ON d.id=a.driver_id
 WHERE t.order_id=? AND o.status='aprobado'
 AND o.fulfillment_status NOT IN ('entregado','cancelado')
 AND a.finished_at IS NULL AND d.active=1 LIMIT 1");
 $s->execute([$orderId]);
 $hash=$s->fetchColumn();$valid=is_string($hash)&&hash_equals($hash,hash('sha256',$token));
}
if(!$valid){http_response_code(403);exit('Enlace GPS invalido, caducado o correspondiente a una entrega que ya termino. Solicita uno nuevo desde Administracion > Pedidos.');}
header('Referrer-Policy: no-referrer');header('Cache-Control: no-store');
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>GPS del repartidor</title></head><body style="font:16px system-ui;max-width:560px;margin:30px auto;padding:16px"><h1>Compartir ubicación</h1><p>Pedido #<?= $orderId ?>. Mantén esta página abierta durante la entrega. Puedes detener el GPS en cualquier momento.</p><button id="start">Iniciar GPS</button> <button id="stop">Detener GPS</button><p id="state">Sin compartir ubicación.</p><script>
const id=<?= $orderId ?>,token=<?= json_encode($token) ?>;let watch=null,last=0;
const state=document.getElementById('state');
document.getElementById('start').onclick=()=>{if(!navigator.geolocation){state.textContent='Este navegador no tiene GPS';return;}if(watch!==null)return;
watch=navigator.geolocation.watchPosition(async p=>{if(Date.now()-last<5000)return;last=Date.now();
const form=new URLSearchParams({order_id:id,driver_token:token,lat:p.coords.latitude,lng:p.coords.longitude,accuracy:p.coords.accuracy});
try{const r=await fetch('api/delivery_tracking.php',{method:'POST',body:form,cache:'no-store'});const j=await r.json();state.textContent=j.ok?'Ubicación enviada: '+new Date().toLocaleTimeString():j.error||'Error de envío';if(r.status===409)document.getElementById('stop').click();}catch(e){state.textContent='No hay conexión; vuelve a intentarlo';}},e=>{state.textContent='Error de GPS: '+e.message;},{enableHighAccuracy:true,maximumAge:3000,timeout:20000});state.textContent='GPS activado';};
document.getElementById('stop').onclick=()=>{if(watch!==null)navigator.geolocation.clearWatch(watch);watch=null;state.textContent='GPS detenido';};
</script></body></html>
