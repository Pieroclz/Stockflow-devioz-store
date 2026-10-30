<?php
// Componente compartido: mapa de administrador y de comprador.
$trackingOrderId = (int)($trackingOrderId ?? 0);
$trackingUid = 'sf-route-' . $trackingOrderId;
$trackingIsAdminPicker = !empty($trackingAdminPicker);
// Factor de calibracion local: no representa trafico en directo.
// Ajustar con varias mediciones reales de la misma ciudad y franja horaria.
$etaCalibrationFile = __DIR__ . '/../config/eta_calibration.php';
$etaCalibration = is_file($etaCalibrationFile) ? require $etaCalibrationFile : [];
$etaFactor = max(1.0, min(3.0, (float)($etaCalibration['traffic_factor'] ?? 1.0)));
$etaExtraMinutes = max(0, min(30, (int)($etaCalibration['extra_minutes'] ?? 0)));
?>
<style>
.sf-map-v3{margin:24px 0;border:1px solid #dbe3ee;border-radius:20px;overflow:hidden;background:white;box-shadow:0 14px 38px rgba(18,40,78,.09);font-family:inherit;color:#23324a;position:relative;isolation:isolate;clear:both;width:100%;box-sizing:border-box}
.sf-map-v3 *{box-sizing:border-box}
.sf-map-v3__top{padding:17px 19px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.sf-map-v3__title{display:flex;align-items:center;gap:11px}.sf-map-v3__logo{background:#e6f1ff;color:#1769dc;display:flex;align-items:center;justify-content:center;border-radius:12px;font-size:25px;width:46px;height:46px}
.sf-map-v3 h3{margin:0!important;font-size:19px!important;color:#152c4d!important}.sf-map-v3__title p{font-size:13px;color:#65758c;margin:3px 0 0}
.sf-map-v3__tools{display:flex;gap:9px;flex-wrap:wrap}.sf-map-v3__btn{appearance:none;border:1px solid #d5e0ed;border-radius:10px;padding:10px 14px;background:white;color:#205081;font-weight:700;font-size:13px;text-decoration:none;cursor:pointer}.sf-map-v3__btn.primary{color:white;background:#1a73e8;border-color:#1a73e8}.sf-map-v3__btn[hidden]{display:none!important}
.sf-map-v3__surface{position:relative;height:425px;min-height:325px;width:100%;overflow:hidden;background:#e8eff7}
.sf-map-v3__surface .leaflet-container{font:inherit;width:100%;height:100%;background:#e8eff7}.sf-map-v3__surface .leaflet-pane,.sf-map-v3__surface .leaflet-map-pane,.sf-map-v3__surface .leaflet-tile-container{position:absolute!important;left:0;top:0}.sf-map-v3__surface .leaflet-tile{position:absolute!important;max-width:none!important;max-height:none!important}.sf-map-v3__surface .leaflet-tile-pane img,.sf-map-v3__surface .leaflet-marker-pane img{max-width:none!important;max-height:none!important;box-shadow:none!important;border-radius:0!important}.sf-map-v3__surface .leaflet-control-zoom a{font-size:20px!important;color:#273a50!important}
.sf-map-v3__overlay{position:absolute;z-index:600;left:16px;top:16px;background:rgba(255,255,255,.96);box-shadow:0 4px 14px #22334422;border:1px solid #dfe5ed;border-radius:13px;padding:10px 13px;display:flex;gap:9px;align-items:center;font-size:12px;font-weight:700;color:#3b4e64;max-width:calc(100% - 32px);pointer-events:none}
.sf-map-v3__pulse{width:9px;height:9px;border-radius:50%;background:#eab308;flex-shrink:0}.sf-map-v3__pulse.live{background:#16a34a;box-shadow:0 0 0 4px #16a34a22}.sf-map-v3__pulse.off{background:#d05757}
.sf-map-v3__bottom{padding:13px 18px;background:#fff;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;border-top:1px solid #e8edf3}
.sf-map-v3__route-info{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 18px;background:#f1f7ff;border-top:1px solid #e1eaf7;color:#244160;font-size:14px}.sf-map-v3__route-info strong{font-size:17px;color:#1257b7}.sf-map-v3__route-info .sf-eta-pill{padding:6px 11px;background:white;border-radius:9px;border:1px solid #d7e5fb;font-weight:700}.sf-map-v3__route-info .sf-route-note{font-size:12px;color:#5c718a} .sf-map-v3__status{color:#50647a;margin:0;font-size:13px;line-height:1.5;flex:1}.sf-map-v3__legend{display:flex;gap:15px;font-size:12px;color:#63758c;flex-wrap:wrap}.sf-map-v3__legend span{white-space:nowrap}.sf-map-v3__hint{font-size:13px;padding:10px 18px;background:#f2f7ff;color:#22518c;margin:0}
.sf-map-v3__marker{width:44px;height:44px;border-radius:100%;border:3px solid white;display:grid;place-items:center;color:white;background:#1a73e8;font-size:23px;box-shadow:0 3px 12px #0005}.sf-map-v3__marker.home{background:#e34e42}
@media(max-width:650px){.sf-map-v3__top{padding:13px}.sf-map-v3__surface{height:350px;min-height:320px}.sf-map-v3__tools{width:100%}.sf-map-v3__btn{flex:1;text-align:center}.sf-map-v3__overlay{top:10px;left:10px}.sf-map-v3__bottom{padding:12px}}
</style>
<section class="sf-map-v3" id="<?= e($trackingUid) ?>-panel" data-map-version="v6-gps-per-order">
 <div class="sf-map-v3__top"><div class="sf-map-v3__title"><span class="sf-map-v3__logo">🛵</span><div><h3>Ubicación de tu entrega</h3><p>Pedido #<?= $trackingOrderId ?> · Seguimiento del motorizado</p></div></div>
 <div class="sf-map-v3__tools"><button type="button" class="sf-map-v3__btn" id="<?= e($trackingUid) ?>-focus">⌖ Centrar</button><a class="sf-map-v3__btn primary" id="<?= e($trackingUid) ?>-external" href="https://www.google.com/maps" rel="noopener noreferrer" target="_blank" hidden>Abrir Google Maps ↗</a></div></div>
 <?php if($trackingIsAdminPicker): ?><p class="sf-map-v3__hint">📍 Toca el mapa para indicar el destino de la compra; después pulsa «Guardar destino».</p><?php endif; ?>
 <div class="sf-map-v3__surface" id="<?= e($trackingUid) ?>-surface"><div id="<?= e($trackingUid) ?>-map" style="width:100%;height:100%" aria-label="Mapa de entrega" role="application"></div><div class="sf-map-v3__overlay"><i class="sf-map-v3__pulse" id="<?= e($trackingUid) ?>-dot"></i><span id="<?= e($trackingUid) ?>-badge">Buscando ubicación del repartidor…</span></div></div>
 <div class="sf-map-v3__route-info" aria-live="polite" id="<?= e($trackingUid) ?>-route-info"><strong>Ruta en preparación</strong><span>Esperando la ubicación del motorizado y el destino.</span></div>
 <div class="sf-map-v3__bottom"><p class="sf-map-v3__status" id="<?= e($trackingUid) ?>-status" aria-live="polite">Consultando el estado del seguimiento…</p><div class="sf-map-v3__legend"><span>🔵 Motorizado</span><span>🔴 Destino</span></div></div>
</section>
<script>
(()=>{
'use strict';
const orderId=<?= $trackingOrderId ?>, admin=<?= $trackingIsAdminPicker?'true':'false' ?>, uid=<?= json_encode($trackingUid) ?>;
const node=document.getElementById(uid+'-map'), status=document.getElementById(uid+'-status'), badge=document.getElementById(uid+'-badge'), dot=document.getElementById(uid+'-dot'), focus=document.getElementById(uid+'-focus'), external=document.getElementById(uid+'-external');
const api=<?= json_encode(url('api/delivery_tracking.php')) ?>;
const routeInfo=document.getElementById(uid+'-route-info');
const etaFactor=<?= json_encode($etaFactor) ?>,etaExtraMinutes=<?= json_encode($etaExtraMinutes) ?>;
let map, rider, home, line, lastBounds=null, hasCentered=false, polling=false, pickedDestination=null;
let routeLastStart=null, routeLastEnd=null, routeLastAt=0, routeBusy=false, routeWaitUntil=0, routeRequestSerial=0, lastRouteStatus='';
const valid=p=>p&&p.lat!==null&&p.lng!==null&&p.lat!==''&&p.lng!==''&&Number.isFinite(+p.lat)&&Number.isFinite(+p.lng)&&Math.abs(+p.lat)<=90&&Math.abs(+p.lng)<=180&&!(+p.lat===0&&+p.lng===0);
const point=p=>[+p.lat,+p.lng];
const icon=(emoji,type)=>L.divIcon({className:'',html:'<div class="sf-map-v3__marker '+type+'">'+emoji+'</div>',iconSize:[44,44],iconAnchor:[22,22]});
const set=(message,detail,kind)=>{badge.textContent=message;status.textContent=detail;dot.className='sf-map-v3__pulse '+(kind||'');};
function center(){if(lastBounds&&lastBounds.length===2)map.fitBounds(lastBounds,{padding:[45,45],maxZoom:16});else if(lastBounds&&lastBounds.length===1)map.setView(lastBounds[0],15);else map.setView([-12.0464,-77.0428],12);}
function showMarker(old,coords,emoji,type,label){if(old){old.setLatLng(coords);return old;}return L.marker(coords,{icon:icon(emoji,type)}).addTo(map).bindPopup(label);}
function ensureLeafletCss(){
 if(window.__sfLeafletCSS)return window.__sfLeafletCSS;
 window.__sfLeafletCSS=new Promise((resolve,reject)=>{
  const url='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
  let css=document.querySelector('link[data-sf-leaflet-v4]');
  if(!css){css=document.createElement('link');css.rel='stylesheet';css.href=url;css.dataset.sfLeafletV4='1';document.head.appendChild(css);}
  if(css.sheet){resolve();return;}
  css.addEventListener('load',()=>resolve(),{once:true});
  css.addEventListener('error',()=>reject(new Error('No se pudo cargar la hoja de estilos del mapa')),{once:true});
  setTimeout(()=>{if(css.sheet)resolve();else reject(new Error('El CSS del mapa no respondió'))},9000);
 });
 return window.__sfLeafletCSS;
}
function loadLeaflet(){
 const css=ensureLeafletCss();
 if(window.L)return css;
 if(!window.__sfLeafletLoading){
  window.__sfLeafletLoading=new Promise((resolve,reject)=>{
   const script=document.createElement('script');
   script.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
   script.onload=resolve;
   script.onerror=()=>reject(new Error('No se pudo cargar Leaflet'));
   document.head.appendChild(script);
  });
 }
 return Promise.all([css,window.__sfLeafletLoading]);
}

// Consulta de rutas por calles. OSRM demo solo para pruebas locales/no comerciales.
// El perfil driving es un aproximado de vehiculo, NO un perfil especializado de motocicleta.
function distanceMeters(a,b){if(!a||!b)return Infinity;const rad=Math.PI/180;const dLat=(b[0]-a[0])*rad,dLon=(b[1]-a[1])*rad;const h=Math.sin(dLat/2)**2+Math.cos(a[0]*rad)*Math.cos(b[0]*rad)*Math.sin(dLon/2)**2;return 12742000*Math.asin(Math.min(1,Math.sqrt(h)));}
function routeMessage(title,detail,hasEta=false){routeInfo.replaceChildren();const b=document.createElement('strong');b.textContent=title;routeInfo.appendChild(b);const d=document.createElement('span');d.className=hasEta?'sf-eta-pill':'sf-route-note';d.textContent=detail;routeInfo.appendChild(d);}
function clearRoute(){if(line){map.removeLayer(line);line=null;}routeLastStart=null;routeLastEnd=null;routeLastAt=0;}
async function updateRoadRoute(a,b,force=false){
 if(!a||!b){clearRoute();routeMessage('Ruta no disponible','Se necesitan las ubicaciones del repartidor y el destino.');return;}
 const now=Date.now();
 // Evitar solicitar una ruta por cada actualizacion GPS o por cada visitante.
 const moved=distanceMeters(routeLastStart,a)>100||distanceMeters(routeLastEnd,b)>35;
 if(!force&&!moved&&now-routeLastAt<60000)return;
 if(routeBusy||now<routeWaitUntil)return;
 routeBusy=true;const serial=++routeRequestSerial;
 routeMessage('Calculando ruta…','Buscando recorrido por calles y tiempo estimado.');
 const ctrl=new AbortController();const timer=setTimeout(()=>ctrl.abort(),8500);
 try{
  const coords=[a,b].map(p=>p[1].toFixed(6)+','+p[0].toFixed(6)).join(';');
  const endpoint='https://router.project-osrm.org/route/v1/driving/'+coords+'?overview=full&geometries=geojson&steps=false&alternatives=false';
  const res=await fetch(endpoint,{signal:ctrl.signal,cache:'no-store'});
  if(!res.ok)throw Error('HTTP '+res.status);
  const data=await res.json();
  if(data.code!=='Ok'||!Array.isArray(data.routes)||!data.routes[0]?.geometry?.coordinates?.length)throw Error('Ruta no encontrada');
  if(serial!==routeRequestSerial)return;
  const route=data.routes[0];
  const path=route.geometry.coordinates.map(([lon,lat])=>[lat,lon]);
  if(line)map.removeLayer(line);
  line=L.polyline(path,{color:'#1a73e8',weight:6,opacity:.94,lineJoin:'round',lineCap:'round'}).addTo(map);
  const km=route.distance/1000,baseMinutes=Math.max(1,Math.ceil(route.duration/60));
  const calibratedMinutes=Math.max(baseMinutes,Math.ceil((route.duration/60)*etaFactor+etaExtraMinutes));
  const kmLabel=km.toLocaleString('es-PE',{maximumFractionDigits:1})+' km por carretera';
  if(etaFactor>1.001||etaExtraMinutes>0){
    routeMessage('Aprox. '+calibratedMinutes+' min (calibrado)',kmLabel+' · Base sin tráfico: '+baseMinutes+' min · No es tráfico en vivo',true);
  }else{
    routeMessage('Aprox. '+baseMinutes+' min',kmLabel+' · Sin tráfico en tiempo real',true);
  }
  routeLastStart=a.slice();routeLastEnd=b.slice();routeLastAt=Date.now();
 }catch(e){
  // Nunca usar linea recta como si fuera una ruta real ni inventar ETA.
  if(line){map.removeLayer(line);line=null;}
  routeMessage('Ruta temporalmente no disponible','No fue posible calcular el recorrido vial. Reintentaré automáticamente.');
  routeWaitUntil=Date.now()+45000;
 }finally{clearTimeout(timer);routeBusy=false;}
}
async function refresh(){if(polling)return;polling=true;try{
const response=await fetch(api+'?order_id='+encodeURIComponent(orderId)+'&_='+Date.now(),{cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json'}});
const data=await response.json();if(!response.ok)throw Error(data.error||'Error HTTP '+response.status);
const current=valid(data.driver)?point(data.driver):null;const target=pickedDestination||(valid(data.destination)?point(data.destination):null);
if(target)home=showMarker(home,target,'⌂','home','Dirección de entrega');
if(current)rider=showMarker(rider,current,'🛵','','Ubicación del motorizado');
else if(rider){map.removeLayer(rider);rider=null;}
lastBounds=[current,target].filter(Boolean);
if(data.status==='entregado'||data.status==='cancelado'){clearRoute();routeMessage('Seguimiento finalizado','El pedido ya no tiene una ruta activa.');}
else updateRoadRoute(current,target);
if(lastBounds.length&&!hasCentered){center();hasCentered=true;}
if(target){external.hidden=false;external.href='https://www.google.com/maps/dir/?api=1&destination='+encodeURIComponent(target.join(','))+(current?'&origin='+encodeURIComponent(current.join(',')):'')+'&travelmode=driving';}
else if(current){external.hidden=false;external.href='https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(current.join(','));}
if(data.status==='entregado'||data.status==='cancelado')set('Seguimiento finalizado','El pedido está '+(data.status==='entregado'?'entregado':'cancelado')+'. Ya no se muestra la ubicación del repartidor.','off');
else if(current){set(data.fresh?'Motorizado en el mapa':'Última posición conocida','Ubicación '+(data.mode==='gps'?'GPS':'manual')+' · última actualización: '+(data.updated_at||'reciente')+(data.fresh?' · Seguimiento activo.':' · Posición antigua; esperando actualización.')+' El tiempo es una estimación vial; si se calibró, no utiliza datos de tráfico en vivo.',data.fresh?'live':'off');}
else if(data.last_seen_at)set('Ubicación sin señal reciente','Última señal recibida: '+data.last_seen_at+'. El motorizado debe mantener abierto «Mi ubicación» y compartir su GPS.','off');
else if(data.active)set('Esperando señal GPS','Hay seguimiento habilitado. El motorizado debe pulsar «Activar GPS» en su panel y permitir el acceso a la ubicación.','');
else set('Seguimiento no iniciado','El mapa estará disponible para seguir al motorizado en cuanto se apruebe y asigne la entrega.','');
}catch(error){set('No se pudo obtener la ubicación',error.message+' · Comprueba que la sesión del pedido esté abierta y que la base tenga la tabla delivery_tracking.','off');}finally{polling=false;}}
loadLeaflet().then(()=>{
 map=L.map(node,{zoomControl:false,scrollWheelZoom:false,preferCanvas:true}).setView([-12.0464,-77.0428],12);
 // OpenStreetMap directo. No necesita API key para uso local de bajo volumen.
 // CARTO se elimina porque algunas teselas responden con "API KEY REQUIRED"
 // como imagen valida, impidiendo detectar el error de tileload.
 const tileLayer=L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{
  attribution:'&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap contributors</a>',
  maxZoom:19,
  tileSize:256,
  crossOrigin:true,
  keepBuffer:2
 });
 let mapTileErrors=0;
 tileLayer.on('tileerror',()=>{
  mapTileErrors++;
  if(mapTileErrors>=3)set('No se cargó la cartografía','Comprueba tu conexión a Internet y el acceso a tile.openstreetmap.org.','off');
 });
 tileLayer.addTo(map);
 L.control.zoom({position:'bottomright'}).addTo(map);
 focus.addEventListener('click',()=>{map.invalidateSize(true);center();});
 if(admin){map.on('click',e=>{const panel=node.closest('.sf-map-v3'),block=panel.parentElement;const form=block.querySelector('form input[name="destination_lat"]')?.form;if(!form)return;form.elements.destination_lat.value=e.latlng.lat.toFixed(7);form.elements.destination_lng.value=e.latlng.lng.toFixed(7);const p=[e.latlng.lat,e.latlng.lng];pickedDestination=p;home=showMarker(home,p,'⌂','home','Destino seleccionado');lastBounds=[p];set('Destino elegido','Pulsa «Guardar destino» para confirmar las coordenadas.','');
updateRoadRoute(rider?rider.getLatLng()&&[rider.getLatLng().lat,rider.getLatLng().lng]:null,p,true);});}
 // Invalidate size after CSS/layout changes, including opening collapsed order cards.
 const resize=()=>{if(map)map.invalidateSize({pan:false,debounceMoveend:true});};
 setTimeout(resize,100);setTimeout(resize,600);setTimeout(resize,1600);
 if(window.ResizeObserver){const observer=new ResizeObserver(resize);observer.observe(node);window.addEventListener('pagehide',()=>observer.disconnect(),{once:true});}
 if(window.IntersectionObserver){const observer=new IntersectionObserver(entries=>{if(entries.some(e=>e.isIntersecting))resize();});observer.observe(node);window.addEventListener('pagehide',()=>observer.disconnect(),{once:true});}
 window.addEventListener('resize',resize);
 refresh();
 const interval=setInterval(()=>{if(!document.hidden){resize();refresh();}},8000);
 window.addEventListener('pagehide',()=>{clearInterval(interval);window.removeEventListener('resize',resize);},{once:true});
}).catch(error=>set('No se pudo cargar el mapa',String(error.message||error)+'. Comprueba la conexión a Internet.','off'));
})();
</script>
