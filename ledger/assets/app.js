(function(){'use strict';
document.querySelectorAll('.per-form select[name="per"]').forEach(function(select){select.removeAttribute('onchange');select.addEventListener('change',function(){select.form.requestSubmit();});});
var overlay=document.querySelector('.loading-overlay'),timer=null,overlayTimer=null,pending=false,modal=document.getElementById('confirm-dialog'),confirmedForm=null;
function loading(){if(pending)return;pending=true;timer=setTimeout(function(){document.body.classList.add('busy');},180);overlayTimer=setTimeout(function(){if(overlay)overlay.setAttribute('aria-hidden','false');},2000);}
function clear(){pending=false;clearTimeout(timer);clearTimeout(overlayTimer);document.body.classList.remove('busy');if(overlay)overlay.setAttribute('aria-hidden','true');document.querySelectorAll('button[data-submitting]').forEach(function(b){b.disabled=false;b.removeAttribute('data-submitting');b.classList.remove('submitting');b.textContent=b.dataset.originalLabel||b.textContent;});}
function toast(message,kind){var stack=document.querySelector('.toast-stack');if(!stack)return;var t=document.createElement('div');t.className='toast '+(kind||'success');t.setAttribute('role','status');var span=document.createElement('span');span.textContent=message;var close=document.createElement('button');close.type='button';close.setAttribute('aria-label','Dismiss notification');close.textContent='\u00d7';close.onclick=function(){t.remove();};t.append(span,close);stack.append(t);setTimeout(function(){t.remove();},5500);}
function openConfirm(message,form){if(!modal||!modal.showModal)return false;confirmedForm=form;document.getElementById('confirm-message').textContent=message;modal.showModal();return true;}
if(modal){modal.querySelector('[data-cancel]').addEventListener('click',function(){modal.close();confirmedForm=null;});modal.querySelector('[data-accept]').addEventListener('click',function(){var f=confirmedForm;modal.close();confirmedForm=null;if(f){f.dataset.confirmed='1';if(f.requestSubmit)f.requestSubmit();else f.submit();}});modal.addEventListener('click',function(e){if(e.target===modal){modal.close();confirmedForm=null;}});}
var flash=document.querySelector('.flash');if(flash){toast(flash.textContent.trim(),/incorrect|invalid|error|ghalat|nahi|match/i.test(flash.textContent)?'error':'success');flash.remove();}
document.addEventListener('click',function(e){
 var a=e.target.closest('a[href]');if(!a||e.defaultPrevented||a.hasAttribute('download')||a.hasAttribute('data-download')||a.target||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||a.origin!==location.origin||(a.hash&&a.pathname===location.pathname&&a.search===location.search))return;loading();
});
document.addEventListener('submit',function(e){
 if(e.defaultPrevented)return;var f=e.target;if(!f.checkValidity())return;
 var submitter=e.submitter||f.querySelector('button[type="submit"],button:not([type])');var warning=submitter&&submitter.dataset.confirm;
 if(warning&&f.dataset.confirmed!=='1'){if(openConfirm(warning,f)){e.preventDefault();return;}if(!window.confirm(warning)){e.preventDefault();return;}}
 if(f.dataset.submitting==='1'){e.preventDefault();return;}f.dataset.submitting='1';
 if(submitter){submitter.dataset.originalLabel=submitter.textContent;submitter.dataset.submitting='1';submitter.classList.add('submitting');submitter.textContent='Processing…';submitter.disabled=true;}
 loading();
});
window.addEventListener('pageshow',function(){clear();document.querySelectorAll('form[data-submitting]').forEach(function(f){delete f.dataset.submitting;delete f.dataset.confirmed;});});
window.addEventListener('pagehide',function(){clearTimeout(timer);clear();});
if(document.readyState==='complete')clear();else window.addEventListener('load',clear);
})();
document.querySelectorAll('[data-print]').forEach(function(button){button.addEventListener('click',function(){window.print();});});
// Smooth anchor scrolling for header actions (drawer behaviour lives in validate.js).

// Clear (×) button for every search field, including the topbar search.
(function(){
 var icon='<svg class="ui-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
 function build(input){
  if(input.dataset.clearReady==='1'||input.type==='hidden')return;
  input.dataset.clearReady='1';
  var wrap=input.closest('.search-wrap');
  if(!wrap){
   wrap=document.createElement('span');wrap.className='search-wrap';
   input.parentNode.insertBefore(wrap,input);wrap.appendChild(input);
  }
  wrap.classList.add('search-wrap');
  if(!wrap.querySelector('.sw-ico')){
   var ico=document.createElement('span');ico.className='sw-ico';ico.innerHTML='<svg class="ui-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';
   wrap.insertBefore(ico,input);
  }
  var btn=document.createElement('button');btn.type='button';btn.className='search-clear';btn.setAttribute('aria-label','Clear search');btn.innerHTML=icon;
  btn.addEventListener('click',function(){input.value='';wrap.classList.remove('has-value');var host=input.closest('.top-search');if(host)host.classList.remove('has-value');input.dispatchEvent(new Event('input',{bubbles:true}));input.focus();if(input.form&&input.form.requestSubmit&&input.dataset.clearSubmit==='1')input.form.requestSubmit();});
  wrap.appendChild(btn);
  function sync(){
   var on=input.value!=='';
   wrap.classList.toggle('has-value',on);
   var host=input.closest('.top-search');if(host)host.classList.toggle('has-value',on);
  }
  input.addEventListener('input',sync);input.addEventListener('change',sync);sync();
 }
 document.querySelectorAll('input[type="search"],input.search,input[name="s"]').forEach(build);
})();

// Premium select: the native control stays in the form, a styled popup replaces the OS list.
(function(){
 var caret='<svg class="ui-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
 var open=null;
 function close(){if(!open)return;var panel=open.wrap.querySelector('.dd-panel');if(panel&&panel.hidePopover&&panel.matches(':popover-open'))panel.hidePopover();open.wrap.classList.remove('open');open.btn.setAttribute('aria-expanded','false');open=null;}
 document.addEventListener('click',function(e){if(open&&!open.wrap.contains(e.target))close();});
 document.addEventListener('keydown',function(e){if(e.key==='Escape'&&open){e.preventDefault();e.stopPropagation();close();}});document.addEventListener('close',close,true);window.addEventListener('resize',close);document.addEventListener('scroll',function(e){if(open&&!open.wrap.contains(e.target))close();},true);
 function enhance(sel){
  if(sel.dataset.ddReady==='1')return;sel.dataset.ddReady='1';
  var wrap=document.createElement('div');wrap.className='dd';
  sel.parentNode.insertBefore(wrap,sel);wrap.appendChild(sel);sel.classList.add('dd-native');sel.setAttribute('tabindex','-1');
  var btn=document.createElement('button');btn.type='button';btn.className='dd-btn';btn.setAttribute('aria-haspopup','listbox');btn.setAttribute('aria-expanded','false');
  btn.innerHTML='<span class="dd-text"></span><span class="dd-caret">'+caret+'</span>';
  var panel=document.createElement('div');panel.className='dd-panel';panel.setAttribute('role','listbox');if(panel.showPopover)panel.setAttribute('popover','manual');
  wrap.append(btn,panel);
  var label=sel.closest('label');if(label&&!btn.getAttribute('aria-label'))btn.setAttribute('aria-label',label.textContent.trim().split('\n')[0]);
  function text(){var o=sel.selectedOptions[0];return o?o.textContent.trim():''}
  function paint(){
   if(sel.disabled&&open&&open.wrap===wrap)close();
   panel.replaceChildren();
   Array.prototype.forEach.call(sel.options,function(o){
    var b=document.createElement('button');b.type='button';b.className='dd-opt';b.setAttribute('role','option');b.dataset.value=o.value;
    b.setAttribute('aria-selected',o.selected?'true':'false');b.textContent=o.textContent.trim();b.disabled=o.disabled||sel.disabled;
    if(o.dataset.due)b.title='Remaining: PKR '+o.dataset.due;
    b.addEventListener('click',function(){if(sel.value!==o.value){sel.value=o.value;sel.dispatchEvent(new Event('change',{bubbles:true}));}paint();close();btn.focus();});
    panel.appendChild(b);
   });
   btn.querySelector('.dd-text').textContent=text()||'Select…';
   btn.disabled=sel.disabled;btn.classList.toggle('is-disabled',sel.disabled);
  }
  function place(){var r=btn.getBoundingClientRect(),height=Math.min(288,Math.max(100,Math.max(innerHeight-r.bottom,r.top)-16)),up=r.bottom+height>innerHeight&&r.top>innerHeight-r.bottom;panel.classList.toggle('dd-up',up);if(panel.showPopover){panel.style.position='fixed';panel.style.inset='auto';panel.style.width=Math.min(Math.max(r.width,180),innerWidth-20)+'px';panel.style.maxHeight=height+'px';panel.style.left=Math.max(10,Math.min(r.left,innerWidth-parseFloat(panel.style.width)-10))+'px';panel.style.top=up?'auto':(r.bottom+6)+'px';panel.style.bottom=up?(innerHeight-r.top+6)+'px':'auto';}}
  btn.addEventListener('click',function(){
   if(sel.disabled)return;
   if(open&&open.wrap===wrap){close();return;}
   close();open={wrap:wrap,btn:btn};wrap.classList.add('open');btn.setAttribute('aria-expanded','true');place();if(panel.showPopover)panel.showPopover();
   var s=panel.querySelector('.dd-opt[aria-selected="true"]')||panel.querySelector('.dd-opt');if(s)s.focus();
  });
  btn.addEventListener('keydown',function(e){
   if(e.key!=='ArrowDown'&&e.key!=='ArrowUp')return;e.preventDefault();
   var opts=Array.from(panel.querySelectorAll('.dd-opt'));if(!opts.length)return;
   var cur=opts.indexOf(document.activeElement),next=e.key==='ArrowDown'?(cur+1)%opts.length:(cur-1+opts.length)%opts.length;
   if(open&&open.wrap===wrap)opts[next].focus();
  });
  panel.addEventListener('keydown',function(e){
   var opts=Array.from(panel.querySelectorAll('.dd-opt')),i=opts.indexOf(document.activeElement);
   if(e.key==='ArrowDown'){e.preventDefault();opts[(i+1)%opts.length].focus();}
   else if(e.key==='ArrowUp'){e.preventDefault();opts[(i-1+opts.length)%opts.length].focus();}
   else if(e.key==='Home'){e.preventDefault();opts[0].focus();}
   else if(e.key==='End'){e.preventDefault();opts[opts.length-1].focus();}
  });
  sel.addEventListener('change',paint);
  if(window.MutationObserver)new MutationObserver(paint).observe(sel,{childList:true,subtree:true,attributes:true});
  paint();
  if(window.__ddPaint)window.__ddPaint.push(paint);
 }
 window.__ddPaint=[];
 document.querySelectorAll('form select:not(.dd-native),.filter select,.support-compose select').forEach(enhance);
})();

// Premium date picker panel for every date field.
(function(){
 var cal='<svg class="ui-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M8 3v4M16 3v4M3 10h18"/></svg>';
 var DOW=['Su','Mo','Tu','We','Th','Fr','Sa'],MON=['January','February','March','April','May','June','July','August','September','October','November','December'];
 var open=null;
 function pad(n){return (n<10?'0':'')+n}
 function iso(y,m,d){return y+'-'+pad(m)+'-'+pad(d)}
 function parse(v){var m=/^(\d{4})-(\d{2})-(\d{2})$/.exec(v||'');return m?new Date(+m[1],+m[2]-1,+m[3]):null}
 function close(){if(!open)return;var panel=open.wrap.querySelector('.dp-panel');if(panel&&panel.hidePopover&&panel.matches(':popover-open'))panel.hidePopover();open.wrap.classList.remove('open');open=null;}
 document.addEventListener('click',function(e){if(open&&!open.wrap.contains(e.target))close();});
 document.addEventListener('keydown',function(e){if(e.key==='Escape'&&open){e.preventDefault();e.stopPropagation();close();}});document.addEventListener('close',close,true);window.addEventListener('resize',close);document.addEventListener('scroll',function(e){if(open&&!open.wrap.contains(e.target))close();},true);
 document.querySelectorAll('input[type="date"]').forEach(function(input){
  if(input.dataset.dpReady==='1')return;input.dataset.dpReady='1';
  var wrap=document.createElement('div');wrap.className='dp';
  input.parentNode.insertBefore(wrap,input);wrap.appendChild(input);
  var ico=document.createElement('span');ico.className='dp-ico';ico.innerHTML=cal;
  var panel=document.createElement('div');panel.className='dp-panel';if(panel.showPopover)panel.setAttribute('popover','manual');
  wrap.append(ico,panel);
  var view=parse(input.value)||new Date();view.setDate(1);
  function paint(){
   var today=new Date(),sel=parse(input.value);
   var y=view.getFullYear(),m=view.getMonth();
   var first=new Date(y,m,1).getDay(),days=new Date(y,m+1,0).getDate(),prevDays=new Date(y,m,0).getDate();
   var h='<div class="dp-head"><span class="dp-title">'+MON[m]+' '+y+'</span><span class="dp-nav"><button type="button" data-nav="-1" aria-label="Previous month">&#8249;</button><button type="button" data-nav="1" aria-label="Next month">&#8250;</button></span></div><div class="dp-grid">';
   DOW.forEach(function(d){h+='<span class="dp-dow">'+d+'</span>'});
   for(var i=first-1;i>=0;i--)h+='<button type="button" class="dp-day mute" tabindex="-1">'+(prevDays-i)+'</button>';
   for(var d=1;d<=days;d++){
    var cls='dp-day';
    if(sel&&sel.getFullYear()===y&&sel.getMonth()===m&&sel.getDate()===d)cls+=' sel';
    else if(today.getFullYear()===y&&today.getMonth()===m&&today.getDate()===d)cls+=' today';
    h+='<button type="button" class="'+cls+'" data-day="'+d+'" aria-label="'+iso(y,m+1,d)+'">'+d+'</button>';
   }
   var tail=(7-(first+days)%7)%7;
   for(var t=1;t<=tail;t++)h+='<button type="button" class="dp-day mute" tabindex="-1">'+t+'</button>';
   h+='</div><div class="dp-foot"><button type="button" data-today class="primary">Today</button><button type="button" data-clear>Clear</button></div>';
   panel.innerHTML=h;
  }
  function commit(v){input.value=v;input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));close();}
  function show(){close();open={wrap:wrap};wrap.classList.add('open');var v=parse(input.value);if(v){view=v;view.setDate(1);}paint();if(panel.showPopover){var r=input.getBoundingClientRect(),width=Math.min(288,innerWidth-20),up=r.bottom+350>innerHeight&&r.top>innerHeight-r.bottom;panel.style.position='fixed';panel.style.inset='auto';panel.style.width=width+'px';panel.style.left=Math.max(10,Math.min(r.left,innerWidth-width-10))+'px';panel.style.top=up?'auto':(r.bottom+6)+'px';panel.style.bottom=up?(innerHeight-r.top+6)+'px':'auto';panel.style.maxHeight=(Math.max(160,up?r.top-16:innerHeight-r.bottom-16))+'px';panel.style.overflowY='auto';panel.showPopover();}}
  // Month navigation must never close the panel: it only repaints the grid.
  function stepMonth(delta){
   view=new Date(view.getFullYear(),view.getMonth()+delta,1);
   wrap.classList.add('open');open={wrap:wrap};
   paint();
  }
  // The custom panel is the only picker: block the browser's own calendar from ever opening.
  function blockNative(e){
   if(e)e.preventDefault();
   show();
   input.focus({preventScroll:true});
  }
  input.addEventListener('keydown',function(e){if(e.key===' '||e.key==='Enter'){e.preventDefault();blockNative();}});
  input.addEventListener('mousedown',function(e){e.preventDefault();blockNative();});
  input.addEventListener('click',function(e){e.preventDefault();if(!wrap.classList.contains('open'))show();});
  input.addEventListener('focus',function(){if(!wrap.classList.contains('open'))show();});
  panel.addEventListener('click',function(e){
   var nav=e.target.closest('[data-nav]');
   if(nav){e.preventDefault();e.stopPropagation();stepMonth(Number(nav.dataset.nav));return;}
   var day=e.target.closest('[data-day]');
   if(day){commit(iso(view.getFullYear(),view.getMonth()+1,Number(day.dataset.day)));return;}
   if(e.target.closest('[data-today]')){var t=new Date();view=new Date(t.getFullYear(),t.getMonth(),1);commit(iso(t.getFullYear(),t.getMonth()+1,t.getDate()));return;}
   if(e.target.closest('[data-clear]')){commit('');}
  });
  // Keep the panel open when the pointer goes down on its own controls.
  panel.addEventListener('mousedown',function(e){e.stopPropagation();});
  panel.addEventListener('keydown',function(e){
   if(e.key!=='ArrowLeft'&&e.key!=='ArrowRight'&&e.key!=='ArrowUp'&&e.key!=='ArrowDown')return;
   e.preventDefault();var sel=parse(input.value)||new Date();
   var step=e.key==='ArrowLeft'?-1:e.key==='ArrowRight'?1:e.key==='ArrowUp'?-7:7;
   sel.setDate(sel.getDate()+step);view=new Date(sel.getFullYear(),sel.getMonth(),1);commit(iso(sel.getFullYear(),sel.getMonth()+1,sel.getDate()));show();
  });
  paint();
 });
})();

// Debit/Credit category options and reference matching for payments.
(function(){
 const form=document.querySelector('[data-ledger-entry]');if(!form)return;
 const category=form.querySelector('#entry-category'),linked=form.querySelector('#linked-entry'),refField=form.querySelector('#reference-field'),amount=form.querySelector('#entry-amount'),hint=form.querySelector('#linked-due-note'),currency=form.querySelector('[name="currency"]');
 const wordHint=document.createElement('small');wordHint.className='field-hint';wordHint.id='amount-in-words';wordHint.setAttribute('aria-live','polite');amount.insertAdjacentElement('afterend',wordHint);
 const ones=['Zero','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'],tens=['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
 function underThousand(n){const out=[];if(n>=100){out.push(ones[Math.floor(n/100)]+' Hundred');n%=100;}if(n>=20){out.push(tens[Math.floor(n/10)]);n%=10;}if(n)out.push(ones[n]);return out.join(' ');}
 function amountWords(){const value=Number(amount.value);if(!Number.isFinite(value)||value<=0){wordHint.textContent='Amount in words will appear here';return;}let n=Math.floor(value),parts=[];for(const [size,label] of [[10000000,'Crore'],[100000,'Lakh'],[1000,'Thousand']]){if(n>=size){const group=Math.floor(n/size);parts.push(underThousand(group)+' '+label);n%=size;}}if(n)parts.push(underThousand(n));const unit=currency.value==='PKR'?'rupees':currency.value;const paisa=currency.value==='PKR'?Math.round((value-Math.floor(value))*100):0;wordHint.textContent=parts.join(' ')+(paisa?' and '+underThousand(paisa)+' paisa':'')+' '+unit+' only';}
 amount.addEventListener('input',amountWords);currency.addEventListener('change',amountWords);amountWords();
 const cats=Object.fromEntries(Object.entries(JSON.parse(form.dataset.entryCategories)).map(([side,options])=>[side,Object.entries(options)]));
 function side(){return form.querySelector('input[name="type"]:checked')?.value==='cr'?'cr':'dr';}
 function payment(){return ['payment_paid','cash_paid','bank_paid','payment_received','cash_received','bank_received','ticket_refund','travel_refund'].includes(category.value);}
 function refund(){return ['ticket_refund','travel_refund'].includes(category.value);}
 function due(){const opt=linked.selectedOptions[0];if(opt&&opt.dataset.due){amount.max=opt.dataset.due;hint.textContent='Remaining on selected reference: PKR '+Number(opt.dataset.due).toLocaleString('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2});}else{amount.removeAttribute('max');hint.textContent='General payment: leave reference empty if payment is not against a specific ticket.';}}
 const search=form.querySelector('#reference-search');let referenceRequest=null,searchTimer=null;
 async function loadReferences(){
  if(!payment())return;if(referenceRequest)referenceRequest.abort();referenceRequest=new AbortController();const controller=referenceRequest;
  linked.disabled=true;linked.value='';amount.removeAttribute('max');hint.textContent='Loading references…';form.dataset.referencesLoading='1';
  try{const url=new URL(location.href);url.search='';url.searchParams.set('p','references');url.searchParams.set('id',form.elements.account_id.value);url.searchParams.set('side',side());url.searchParams.set('s',search.value.trim());
   const response=await fetch(url,{signal:controller.signal,headers:{Accept:'application/json'}});if(!response.ok)throw Error('Unable to load references');const data=await response.json();
   if(controller!==referenceRequest||!payment())return;linked.replaceChildren(new Option(refund()?'General refund (no reference)':'General payment (no reference)',''));
   data.items.forEach(item=>{const option=new Option(item.label,String(item.id));option.dataset.due=item.due;linked.add(option);});
   hint.textContent=data.more?'Showing 50 references. Search PNR or entry number for older items.':(data.items.length?(refund()?'Choose the original sale to link this refund.':'Choose a reference, or leave as general payment.'):(refund()?'No refundable balance matches.':'No unpaid references match. You can record a general payment.'));
  }catch(error){if(error.name!=='AbortError'){linked.replaceChildren(new Option(refund()?'General refund (no reference)':'General payment (no reference)',''));hint.textContent='References could not load. Change category to retry, or leave this transaction unlinked.';}}
  finally{if(controller===referenceRequest){linked.disabled=!payment();delete form.dataset.referencesLoading;}}
 }
 function syncLink(){const show=payment();refField.hidden=!show;linked.disabled=!show;linked.replaceChildren(new Option(refund()?'General refund (no reference)':'General payment (no reference)',''));refField.querySelector('.reference-title').textContent=refund()?'Refund Against':'Payment Against';amount.removeAttribute('max');if(!show){if(referenceRequest)referenceRequest.abort();return;}search.value='';loadReferences();}
 search.addEventListener('input',function(){clearTimeout(searchTimer);searchTimer=setTimeout(loadReferences,250);});
 search.addEventListener('keydown',function(event){if(event.key==='Enter'){event.preventDefault();clearTimeout(searchTimer);loadReferences();}});
 function syncSide(){const prev=category.value;category.replaceChildren(...cats[side()].map(([value,text])=>new Option(text,value)));if(cats[side()].some(x=>x[0]===prev))category.value=prev;syncLink();}
 form.querySelectorAll('input[name="type"]').forEach(r=>r.addEventListener('change',syncSide));category.addEventListener('change',syncLink);linked.addEventListener('change',due);syncSide();
})();

// Password fields: show/hide (eye) toggle plus live strength and match feedback.
(function(){
 var eye='<svg class="ui-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3.2"/></svg>';
 var eyeOff='<svg class="ui-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.1A9.6 9.6 0 0 1 12 6c6 0 9.5 6 9.5 6a17 17 0 0 1-3.3 4"/><path d="M6.3 7.9A16.6 16.6 0 0 0 2.5 12S6 18 12 18a9.4 9.4 0 0 0 3.5-.7"/><path d="M9.9 10.2a3 3 0 0 0 4.1 4.2"/></svg>';
 function rules(){return [function(v){return v.length>=12},function(v){return /[A-Z]/.test(v)},function(v){return /[a-z]/.test(v)},function(v){return /[0-9]/.test(v)}];}
 function meter(v){
  if(!v)return {score:0,label:'',cls:''};
  var passed=rules().filter(function(f){return f(v)}).length;
  if(passed<=1)return {score:1,label:'Weak — add uppercase letters, numbers and more length',cls:'weak'};
  if(passed===2)return {score:2,label:'Fair — add a number or an uppercase letter',cls:'fair'};
  if(passed===3)return {score:3,label:'Good — 12+ characters with mixed case and numbers',cls:'good'};
  return {score:4,label:'Strong password',cls:'strong'};
 }
 document.querySelectorAll('input[type="password"]').forEach(function(input){
  if(input.dataset.eyeReady==='1')return;input.dataset.eyeReady='1';
  var wrap=document.createElement('span');wrap.className='pw-wrap';
  input.parentNode.insertBefore(wrap,input);wrap.appendChild(input);
  var btn=document.createElement('button');btn.type='button';btn.className='pw-toggle';btn.setAttribute('aria-label','Show password');btn.setAttribute('aria-pressed','false');btn.innerHTML=eye;
  btn.addEventListener('click',function(){
   var show=input.type==='password';
   input.type=show?'text':'password';
   btn.innerHTML=show?eyeOff:eye;
   btn.setAttribute('aria-label',show?'Hide password':'Show password');
   btn.setAttribute('aria-pressed',show?'true':'false');
   btn.classList.toggle('on',show);
   input.focus({preventScroll:true});
  });
  wrap.appendChild(btn);
  if(input.name==='new_password'){
   var bar=document.createElement('span');bar.className='pw-meter';
   bar.innerHTML='<i></i><i></i><i></i><i></i>';
   var hint=document.createElement('small');hint.className='pw-hint';
   hint.textContent='Use 12+ characters with uppercase, lowercase and a number.';
   wrap.insertAdjacentElement('afterend',hint);
   wrap.insertAdjacentElement('afterend',bar);
   input.addEventListener('input',function(){
    var m=meter(input.value);
    bar.className='pw-meter '+(m.cls||'');
    bar.dataset.score=String(m.score);
    hint.textContent=m.label||'Use 12+ characters with uppercase, lowercase and a number.';
    hint.className='pw-hint '+(m.cls||'');
   });
  }
 });
 document.querySelectorAll('input[name="confirm_password"]').forEach(function(confirm){
  var form=confirm.form;if(!form)return;
  var newField=form.querySelector('input[name="new_password"]');if(!newField)return;
  var note=document.createElement('small');note.className='pw-hint';note.setAttribute('role','status');
  (confirm.closest('.pw-wrap')||confirm).insertAdjacentElement('afterend',note);
  function check(){
   if(!confirm.value){note.textContent='';note.className='pw-hint';confirm.setCustomValidity('');return;}
   var same=newField.value===confirm.value;
   note.textContent=same?'Passwords match.':'Passwords do not match.';
   note.className='pw-hint '+(same?'strong':'weak');
   confirm.setCustomValidity(same?'':'Passwords do not match.');
  }
  confirm.addEventListener('input',check);newField.addEventListener('input',check);
 });
})();

// On phones the monthly chart scrolls sideways; start it at the current month
// so the user sees real activity instead of an empty January.
(function(){
 document.querySelectorAll('.chart-box').forEach(function(box){
  var svg=box.querySelector('.barchart');if(!svg)return;
  function centreCurrent(){
   if(box.scrollWidth<=box.clientWidth+2)return;
   var groups=svg.querySelectorAll('.bar-group');
   var target=groups[new Date().getMonth()];
   if(!target)return;
   var r=target.getBoundingClientRect(),b=box.getBoundingClientRect();
   box.scrollLeft+=Math.round(r.left-b.left-(box.clientWidth-r.width)/2);
  }
  centreCurrent();
  window.addEventListener('resize',centreCurrent);
 });
})();

// Owner password reset: collect the new password, then submit the row's form.
(function(){
 document.querySelectorAll('[data-reset-open]').forEach(function(btn){
  btn.addEventListener('click',function(){
   var form=btn.closest('form');if(!form)return;
   var pw=window.prompt('Enter a new password for this agency owner (12–72 characters).\nIt must include uppercase, lowercase and a number.');
   if(pw===null)return;
   pw=pw.trim();
   if(pw.length<12||pw.length>72){window.alert('Password must be 12–72 characters.');return;}
   if(!/[A-Z]/.test(pw)||!/[a-z]/.test(pw)||!/[0-9]/.test(pw)){window.alert('Password must include uppercase, lowercase and a number.');return;}
   form.querySelector('input[name="password"]').value=pw;
   if(form.requestSubmit)form.requestSubmit();else form.submit();
  });
 });
})();

// Every filter form gets a Clear control so a filtered view resets in one click.
(function(){
 var reset='<svg class="ui-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
 document.querySelectorAll('form.filter').forEach(function(form){
  if(form.dataset.clearReady==='1')return;form.dataset.clearReady='1';
  var fields=Array.from(form.querySelectorAll('input:not([type=hidden]),select'));
  var btn=document.createElement('button');btn.type='button';btn.className='mini filter-clear';btn.innerHTML=reset+' Clear';
  btn.addEventListener('click',function(){
   fields.forEach(function(f){
    if(f.tagName==='SELECT'){f.selectedIndex=0;}else{f.value='';}
    f.dispatchEvent(new Event('change',{bubbles:true}));
   });
   var url=new URL(location.href);
   Array.from(url.searchParams.keys()).forEach(function(k){if(k!=='p'&&k!=='id'&&k!=='per')url.searchParams.delete(k);});
   location.href=url.toString();
  });
  form.appendChild(btn);
  if(fields.some(function(f){return f.value!==''&&f.value!==f.defaultValue;}))btn.classList.add('active');
 });
})();


// Server-rendered drawers share one controller; no client-side layout replacement.
(function(){
 const dialog=document.querySelector('.form-drawer');if(!dialog)return;
 const form=dialog.querySelector('[data-ledger-entry]');
 function syncEntry(){if(!form)return;const credit=form.querySelector('[name="type"]:checked').value==='cr';dialog.dataset.side=credit?'cr':'dr';dialog.querySelector('.drawer-bar h2').textContent=credit?'New credit entry':'New debit entry';form.querySelector('button.btn').textContent=credit?'Save Credit Entry':'Save Debit Entry';}
 document.querySelectorAll('[data-open-drawer]').forEach(button=>button.addEventListener('click',function(){
  if(form){const radio=form.querySelector('[name="type"][value="'+button.dataset.openDrawer+'"]');if(!radio.checked){radio.checked=true;radio.dispatchEvent(new Event('change',{bubbles:true}));}}
  dialog.showModal();const field=form?form.querySelector('#entry-amount'):dialog.querySelector('input:not([type="hidden"])');if(field)field.focus();
 }));
 if(form){form.querySelectorAll('[name="type"]').forEach(radio=>radio.addEventListener('change',syncEntry));syncEntry();}
 dialog.querySelector('[data-close-entry],[data-close-drawer]').addEventListener('click',()=>dialog.close());
 dialog.addEventListener('click',function(event){if(event.target!==dialog)return;const rect=dialog.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)dialog.close();});
})();

// Foreign entries use a manually confirmed PKR-per-unit rate; never guess one.
(function(){
 const form=document.querySelector('[data-ledger-entry]');if(!form)return;
 const currency=form.querySelector('[name="currency"]'),rate=form.querySelector('[name="fx_rate"]'),label=form.querySelector('[data-currency-label]'),hint=form.querySelector('#fx-rate-hint');
 if(!currency||!rate)return;
 let previous=currency.value;function syncCurrency(){const code=currency.value;label.textContent=code;if(code==='PKR'){rate.value='1.000000';rate.readOnly=true;hint.textContent='PKR is fixed at 1.';}else{if(code!==previous)rate.value='';rate.readOnly=false;hint.textContent='Enter the agreed PKR value for one '+code+'. The app does not fetch live rates.';}previous=code;}
 currency.addEventListener('change',syncCurrency);syncCurrency();
})();
// Direct support is available before and after sign-in; no ticket or message is sent automatically.
(()=>{const dialog=document.getElementById('support-dialog');if(!dialog)return;
let opener;document.querySelectorAll('[data-support-open]').forEach(link=>link.addEventListener('click',event=>{if(typeof dialog.showModal!=='function')return;event.preventDefault();opener=link;if(!dialog.open)dialog.showModal();}));
dialog.querySelector('[data-support-close]').addEventListener('click',()=>dialog.close());
dialog.addEventListener('click',event=>{if(event.target===dialog){const r=dialog.getBoundingClientRect();if(event.clientX<r.left||event.clientX>r.right||event.clientY<r.top||event.clientY>r.bottom)dialog.close();}});
dialog.addEventListener('close',()=>{if(opener)opener.focus();});
const topic=dialog.querySelector('#support-topic'),details=dialog.querySelector('#support-details');
const update=()=>{const message='Assalamualaikum Abubakar, I need help with MIZORA Travel Ledger.\nTopic: '+topic.value+(details.value.trim()?'\nDetails: '+details.value.trim():'');const whatsapp=dialog.querySelector('[data-support-whatsapp]');const whatsappUrl=new URL(whatsapp.href);whatsappUrl.searchParams.set('text',message);whatsapp.href=whatsappUrl.toString();dialog.querySelector('[data-support-email]').href='mailto:abusidique555@gmail.com?subject='+encodeURIComponent('MIZORA Support: '+topic.value)+'&body='+encodeURIComponent(message);};
topic.addEventListener('change',update);details.addEventListener('input',update);update();})();
// Private support chat: cursor paging, bounded DOM, incremental polling and explicit human takeover.
(()=>{
 const dialog=document.getElementById('chat-dialog');if(!dialog)return;
 const $=selector=>dialog.querySelector(selector),master=dialog.dataset.master==='1',token=dialog.dataset.token;
 const form=$('[data-chat-form]'),input=form.querySelector('textarea'),send=form.querySelector('button'),messages=$('[data-chat-messages]'),history=$('.chat-history'),status=$('[data-chat-status]'),older=$('[data-chat-older]'),latest=$('[data-chat-latest]'),title=$('[data-chat-title]');
 const maxRows=300,drafts=new Map(),nodes=new Map();
 let agency=0,rows=[],threads=[],opener,sending=false,loading=false,queued=false,timer,searchTimer,generation=0,failures=0,hasOlder=null,historyMode=false,readThrough=0;
 let availability={mode:'available',available:false},threadPages=[null],threadPage=0,nextThreadCursor=null,search='',threadSignature='';
 const requestKey=()=>crypto.randomUUID?crypto.randomUUID():Date.now().toString(36)+'-'+Math.random().toString(36).slice(2)+'-chat';
 async function request(url,options={}){
  const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),12000);
  try{
   const response=await fetch(url,{...options,signal:controller.signal,headers:{Accept:'application/json',...(options.headers||{})}});
   if(response.redirected||response.status===419||response.status===401)throw Error('Your session expired. Sign in again to continue.');
   if(!response.headers.get('content-type')?.includes('application/json'))throw Error('Unable to load chat. Please refresh or sign in again.');
   const data=await response.json();if(!response.ok)throw Error(data.error||'Unable to complete this request. Please try again.');return data;
  }catch(error){if(error.name==='AbortError')throw Error('The connection timed out. Your unsent message is still here; please retry.');if(error instanceof TypeError)throw Error('Connection lost. Your unsent message is still here. Reconnect and retry.');throw error;}
  finally{clearTimeout(timeout);}
 }
 function badge(count){document.querySelectorAll('[data-chat-badge]').forEach(el=>{el.hidden=!count;el.textContent=count>99?'99+':String(count);});}
 function presence(data){
  availability=data;const label=$('[data-chat-presence]'),button=$('[data-chat-availability]');
  label.textContent=master?(data.mode==='away'?'Away - automated support is helping agents':'Available - you reply personally'):(data.handling?'Your conversation is with the admin':data.available?'Admin available - personal replies':'Admin away - automated support can help');
  label.classList.toggle('is-available',Boolean(data.available||data.handling));
  if(button){button.textContent=data.mode==='available'?'Available':'Away';button.setAttribute('aria-pressed',String(data.mode==='available'));button.classList.toggle('is-away',data.mode==='away');}
 }
 function resetWindow(){rows=[];hasOlder=null;historyMode=false;readThrough=0;older.hidden=true;latest.hidden=true;}
 function selectThread(thread){
  if(sending||agency===Number(thread.id))return;
  const old=drafts.get(agency)||{};drafts.set(agency,{...old,text:input.value});agency=Number(thread.id);generation++;resetWindow();input.value=drafts.get(agency)?.text||'';
  title.textContent=thread.name+(Number(thread.waiting)?' - Needs admin':'')+(Number(thread.active)?'':' - Inactive');send.disabled=false;status.textContent='';renderThreads();renderMessages();refresh();
 }
 function renderThreads(){
  if(!master)return;const signature=JSON.stringify([agency,sending,threadPage,nextThreadCursor,threads]);if(signature===threadSignature)return;threadSignature=signature;
  const container=$('[data-chat-threads]'),fragment=document.createDocumentFragment();
  if(!threads.length){const p=document.createElement('p');p.className='chat-empty';p.textContent='No agencies found.';fragment.append(p);}
  for(const thread of threads){
   const button=document.createElement('button');button.type='button';button.className='chat-thread'+(Number(thread.id)===agency?' selected':'');button.disabled=sending;button.setAttribute('aria-pressed',String(Number(thread.id)===agency));
   const name=document.createElement('strong');name.textContent=thread.name;const preview=document.createElement('small');preview.textContent=(Number(thread.waiting)?'Needs admin - ':'')+(thread.preview||'Start a conversation');button.append(name,preview);
   if(Number(thread.unread)){const unread=document.createElement('span');unread.className='chat-thread-count';unread.textContent=Number(thread.unread)>99?'99+':thread.unread;button.append(unread);}
   button.addEventListener('click',()=>selectThread(thread));fragment.append(button);
  }
  container.replaceChildren(fragment);$('[data-chat-thread-prev]').disabled=threadPage===0;$('[data-chat-thread-next]').disabled=!nextThreadCursor;
 }
 function createBubble(row){
  const mine=Boolean(Number(row.from_master))===master&&!Number(row.is_auto),bubble=document.createElement('article');bubble.className='chat-bubble'+(mine?' mine':'')+(Number(row.is_auto)?' automated':'');
  const author=document.createElement('strong');author.textContent=Number(row.is_auto)?'Automated support':Number(row.from_master)?'Master Admin':(row.display_name||row.username);
  const body=document.createElement('p');body.textContent=row.body;const date=document.createElement('time');date.dateTime=row.created_at.replace(' ','T')+'Z';const parsed=new Date(date.dateTime);date.textContent=Number.isNaN(parsed.valueOf())?row.created_at:parsed.toLocaleString([],{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});bubble.append(author,body,date);return bubble;
 }
 function renderMessages(prepend=false,forceBottom=false){
  const oldHeight=history.scrollHeight,oldTop=history.scrollTop,nearBottom=oldHeight-oldTop-history.clientHeight<90,ids=new Set(rows.map(row=>row.id));
  for(const [id,node] of nodes)if(!ids.has(id)){node.remove();nodes.delete(id);}
  messages.querySelector('.chat-empty')?.remove();
  if(!rows.length){const empty=document.createElement('p');empty.className='chat-empty';empty.textContent=master&&!agency?'Choose an agency from your inbox.':'How can we help? Describe your issue here. Automated support can guide you while Abubakar Sidique is away.';messages.append(empty);return;}
  let changed=false;for(let index=0;index<rows.length;index++){const row=rows[index];let node=nodes.get(row.id);if(!node){node=createBubble(row);nodes.set(row.id,node);changed=true;}if(messages.children[index]!==node)messages.insertBefore(node,messages.children[index]||null);}
  if(prepend)history.scrollTop=oldTop+history.scrollHeight-oldHeight;
  else if(forceBottom||nearBottom)history.scrollTop=history.scrollHeight;
  else if(changed)latest.hidden=false;
 }
 function schedule(immediate=false){clearTimeout(timer);if(document.hidden)return;timer=setTimeout(()=>refresh(),immediate?0:Math.min(60000,(dialog.open?4000:30000)*Math.pow(2,Math.min(failures,4))));}
 async function refresh(before=0){
  if(loading){if(!before)queued=true;return;}if(document.hidden)return;
  loading=true;older.disabled=true;const version=generation,current=agency,currentSearch=search,currentPage=threadPage,wasOpen=dialog.open;
  const params=new URLSearchParams({p:'chat'});if(master)params.set('agency',String(current));if(!wasOpen)params.set('badge','1');
  if(before)params.set('before',String(before));else if(historyMode)params.set('messages','0');else if(rows.length)params.set('after',String(rows.at(-1).id));
  if(master){params.set('search',currentSearch);const cursor=threadPages[currentPage];if(cursor){params.set('thread_last',String(cursor.last));params.set('thread_id',String(cursor.id));}if(before)params.set('threads','0');}
  let force=false;
  try{
   const data=await request('?'+params);failures=0;badge(Number(data.unread));if(version!==generation)return;presence(data.availability);
   if(!master&&data.agency)agency=Number(data.agency);
   if(master&&wasOpen&&dialog.open&&!before&&currentSearch===search&&currentPage===threadPage){threads=data.threads;nextThreadCursor=data.thread_more?data.thread_cursor:null;renderThreads();}
   if(wasOpen&&dialog.open){
    if(!historyMode||before){
     const incoming=data.messages||[],initial=rows.length===0;
     if(before){historyMode=true;rows=[...incoming,...rows].slice(0,maxRows);hasOlder=data.has_older;latest.hidden=false;}
     else if(data.has_more){/* The gap is large (e.g. after being away); one fetch of the latest window beats paging through thousands of rows. */rows=incoming;hasOlder=true;force=true;}
     else {rows=[...rows,...incoming];if(data.has_older!==null)hasOlder=data.has_older;if(rows.length>maxRows){rows=rows.slice(-maxRows);hasOlder=true;}}
     older.hidden=!hasOlder;renderMessages(Boolean(before),initial||force);status.textContent='';
    }
    const through=rows.at(-1)?.id||0;
    if(through>readThrough&&(!master||current)&&!document.hidden){
     await request('?p=chat-read',{method:'POST',body:new URLSearchParams({t:token,agency:String(agency),through:String(through)})});
     if(version===generation){readThrough=through;queued=true;}
    }
   }
  }catch(error){failures++;if(dialog.open&&version===generation)status.textContent=error.message;}
  finally{loading=false;older.disabled=false;const again=queued;queued=false;schedule(again);}
 }
 document.querySelectorAll('[data-chat-open]').forEach(button=>button.addEventListener('click',()=>{opener=button;if(!dialog.open)dialog.showModal();document.body.classList.remove('nav-open');refresh();if(!master)input.focus();}));
 $('[data-chat-close]').addEventListener('click',()=>dialog.close());dialog.addEventListener('close',()=>{generation++;opener?.focus();schedule();});
 dialog.addEventListener('click',event=>{if(event.target===dialog){const rect=dialog.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)dialog.close();}});
 if(master){
  $('[data-chat-search]').addEventListener('input',event=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{search=event.target.value.trim();threadPages=[null];threadPage=0;nextThreadCursor=null;generation++;refresh();},250);});
  $('[data-chat-thread-next]').addEventListener('click',()=>{if(!nextThreadCursor)return;threadPages[++threadPage]=nextThreadCursor;generation++;refresh();});
  $('[data-chat-thread-prev]').addEventListener('click',()=>{if(!threadPage)return;threadPage--;generation++;refresh();});
  $('[data-chat-availability]').addEventListener('click',async event=>{const button=event.currentTarget;button.disabled=true;try{const data=await request('?p=chat',{method:'POST',body:new URLSearchParams({t:token,action:'availability',mode:availability.mode==='available'?'away':'available'})});presence(data.availability);status.textContent='';refresh();}catch(error){status.textContent=error.message;}finally{button.disabled=false;}});
 }
 older.addEventListener('click',()=>{if(rows.length&&!loading)refresh(rows[0].id);});
 latest.addEventListener('click',()=>{if(!historyMode){history.scrollTop=history.scrollHeight;latest.hidden=true;return;}generation++;resetWindow();renderMessages();refresh();});
 form.addEventListener('submit',async event=>{
  event.preventDefault();const body=input.value.trim();if(!body||sending||(master&&!agency))return;
  const version=generation,current=agency;let draft=drafts.get(current);if(!draft||draft.text!==body||!draft.key){draft={text:body,key:requestKey()};drafts.set(current,draft);}
  sending=true;input.disabled=true;send.disabled=true;send.textContent='Sending...';renderThreads();status.textContent='';
  try{
   await request('?p=chat'+(master?'&agency='+current:''),{method:'POST',body:new URLSearchParams({t:token,message:body,key:draft.key})});drafts.delete(current);
   if(version===generation){input.value='';if(historyMode){generation++;resetWindow();renderMessages();}refresh();}
  }catch(error){status.textContent=error.message;}
  finally{sending=false;input.disabled=false;send.disabled=master&&!agency;send.textContent='Send';renderThreads();if(dialog.open)input.focus();}
 });
 input.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){event.preventDefault();form.requestSubmit();}});
 document.addEventListener('visibilitychange',()=>{if(document.hidden)clearTimeout(timer);else refresh();});window.addEventListener('online',()=>refresh());
 renderMessages();refresh();
})();
