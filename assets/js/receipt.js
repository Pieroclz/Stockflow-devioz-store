(() => {
'use strict';
const dataNode=document.getElementById('receiptData');if(!dataNode)return;
const data=JSON.parse(dataNode.textContent);
const feedback=document.getElementById('receiptFeedback');
const fallback=()=>{document.getElementById('receiptLinkFallback').hidden=false;const input=document.getElementById('receiptLink');input.focus();input.select();feedback.textContent='Selecciona y copia el enlace para compartirlo.';};
const copy=async()=>{try{if(!navigator.clipboard||!window.isSecureContext){fallback();return;}await navigator.clipboard.writeText(data.url);feedback.textContent='Enlace copiado.';}catch(e){fallback();}};
document.getElementById('paperWidth').addEventListener('change',event=>{document.body.dataset.paper=event.target.value;});
document.getElementById('printReceipt').addEventListener('click',()=>{feedback.textContent='Elige el mismo tamaño de papel en la impresora. Para PDF, usa Guardar como PDF o las opciones de compartir de tu navegador.';window.print();});
document.getElementById('copyReceipt').addEventListener('click',copy);
document.getElementById('shareReceipt').addEventListener('click',async()=>{if(navigator.share&&window.isSecureContext){try{await navigator.share({title:data.title,text:'Recibo de mi compra',url:data.url});feedback.textContent='Opciones de compartir abiertas.';}catch(e){if(e.name!=='AbortError')fallback();}}else{await copy();}});
if(data.autoPrint)window.addEventListener('load',()=>window.print(),{once:true});
})();
