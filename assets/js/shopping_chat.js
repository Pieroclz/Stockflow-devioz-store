(() => {
'use strict';
const root=document.getElementById('shoppingChat'); if(!root)return;
const form=document.getElementById('agentForm'), chatForm=document.getElementById('chatForm'), input=document.getElementById('chatInput'), messages=document.getElementById('chatMessages'), welcome=document.getElementById('chatWelcome'), status=document.getElementById('chatStatus'), send=document.getElementById('chatSend');
const key='devioz-shopping-chat-v1';let history=[], context=[], busy=false;
const money=n=>'S/ '+(n/100).toFixed(2);
function save(){try{sessionStorage.setItem(key,JSON.stringify({history:history.slice(-30),context:context.slice(-4),budget:form.elements.budget.value}));}catch{}}
function updateView(){welcome.classList.toggle('hidden',history.length>0);messages.hidden=history.length===0;}
function render(entry){
 const bubble=document.createElement('div');bubble.className='chat-message '+entry.role;
 const text=document.createElement('p');text.textContent=entry.text;bubble.append(text);
 const data=entry.data, plan=data?.plan;
 if(plan){
  const list=document.createElement('ul');
  for(const item of plan.items){const row=document.createElement('li');row.textContent=`${item.quantity} × ${item.name} — ${money(item.unit_cents*item.quantity)}`;list.append(row);}bubble.append(list);
  const total=document.createElement('strong');total.textContent=`Total: ${money(plan.total)} · Saldo: ${money(plan.remaining)}`;bubble.append(total);
  const note=document.createElement('small');note.textContent=plan.mode+' Reserva incluida en el saldo: '+money(data.reserved)+'. Precios y stock sujetos a confirmación. El saldo no equivale a ahorro.';bubble.append(note);
  if(data.comparison){const ref=document.createElement('small');ref.textContent='Diferencia frente a tu referencia: '+money(data.comparison.difference)+'. Solo es ahorro si comparas los mismos productos y cantidades.';bubble.append(ref);}
  if(plan.items.length&&data.source===0&&typeof data.confirmation==='string'&&/^[a-f0-9]{48}$/.test(data.confirmation)&&plan.items.length<=12){const button=document.createElement('button');button.type='button';button.textContent='Usar propuesta en Mi lista';button.addEventListener('click',()=>{
   if(!confirm('¿Reemplazar Mi lista con esta propuesta?'))return;
   const items=plan.items.map(p=>({kind:'product',id:Number(p.id),code:p.code,name:p.name,category:p.category,price:p.unit_cents/100,stock:Number(p.stock),image_url:'',icon:'🛍️',presentation:'unidad',presentation_label:'Unidad',units_per_item:1,quantity:Math.min(99,Math.max(1,Number(p.quantity)||1))}));
   const proposal=plan.items.map(p=>({id:Number(p.id),quantity:Math.min(99,Math.max(1,Number(p.quantity)||1)),price:Number(p.unit_cents)/100}));
   try{localStorage.setItem('devioz-ai-confirmation-v1',JSON.stringify({token:data.confirmation,items:proposal,cartItems:items}));location.href=root.dataset.catalog;}catch{status.textContent='No se pudo guardar la propuesta en este navegador.';}
  });bubble.append(button);}
 }
 messages.append(bubble);messages.scrollTop=messages.scrollHeight;updateView();
}
function add(entry){history.push(entry);while(history.length>30){history.shift();messages.firstElementChild?.remove();}render(entry);save();}
try{const stored=JSON.parse(sessionStorage.getItem(key));if(Array.isArray(stored?.history)){history=stored.history.slice(-30);context=Array.isArray(stored.context)?stored.context.slice(-4):[];if(/^\d+(\.\d{1,2})?$/.test(stored.budget))form.elements.budget.value=stored.budget;history.forEach(render);}}catch{}
updateView();
async function submit(event){
 event.preventDefault();if(busy)return;const message=input.value.trim();if(!message)return;
 add({role:'user',text:message});input.value='';
 if(/^(hola|buenas|gracias)[!.\s]*$/i.test(message)){add({role:'assistant',text:'Dime qué deseas comprar y cuánto quieres gastar. También puedes abrir los ajustes para elegir una categoría o tienda.'});return;}
 const amount=message.match(/(?:s\s*\/\s*|presupuesto\s*(?:de\s*)?|tengo\s*)(\d+(?:[.,]\d{1,2})?)/i)||message.match(/(\d+(?:[.,]\d{1,2})?)\s*soles/i);
 if(amount)form.elements.budget.value=amount[1].replace(',','.');
 if(!form.reportValidity()){input.value=message;add({role:'assistant',text:'Revisa el presupuesto y los ajustes antes de continuar.'});document.querySelector('.chat-settings').open=true;return;}
 const nextContext=[...context,message].slice(-4);
 const data=new FormData(form);data.set('chat','1');data.set('request',('Conversación en orden; la petición más reciente modifica las anteriores:\n'+nextContext.join('\n')).slice(-500));
 busy=true;send.disabled=true;document.getElementById('chatReset').disabled=true;status.textContent='Consultando productos y preparando tu propuesta…';
 try{
  const response=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
  if(!response.ok)throw Error();const result=await response.json();
  if(result.error){add({role:'assistant',text:result.error});input.value=message;return;}
  context=nextContext;add({role:'assistant',text:result.plan.items.length?'Esta es tu propuesta para '+result.sourceLabel+' con un presupuesto de '+money(Math.round(Number(form.elements.budget.value)*100))+':':'No encontré productos disponibles que entren en esta selección. Prueba otra categoría o presupuesto.',data:result});
 }catch{add({role:'assistant',text:'No pude conectar con el asistente. Revisa tu conexión e inténtalo otra vez.'});input.value=message;}
 finally{busy=false;send.disabled=false;document.getElementById('chatReset').disabled=false;status.textContent='';input.focus();save();}
}
chatForm.addEventListener('submit',submit);
input.addEventListener('input',()=>{input.style.height='auto';input.style.height=Math.min(input.scrollHeight,120)+'px';});
input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();chatForm.requestSubmit();}});
document.querySelectorAll('.chat-suggestions button').forEach(b=>b.addEventListener('click',()=>{if(busy)return;input.value=b.textContent;chatForm.requestSubmit();}));
document.getElementById('chatReset').addEventListener('click',()=>{if(busy)return;history=[];context=[];messages.replaceChildren();save();updateView();input.focus();});
})();
