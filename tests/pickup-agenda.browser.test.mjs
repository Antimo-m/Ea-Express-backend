import test from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {spawn,execFileSync} from 'node:child_process';
import {existsSync,mkdtempSync,readFileSync,rmSync,writeFileSync} from 'node:fs';
import {join,resolve,extname} from 'node:path';
import {tmpdir} from 'node:os';

const chrome=process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
test('Agenda: account SQL separati dagli ordini, apertura limitata e aggiornamenti coerenti', {skip:!existsSync(chrome),timeout:180000},async t=>{
 const temporary=mkdtempSync(join(tmpdir(),'ea-pickup-agenda-'));
 try{execFileSync('php',['artisan','test','--compact','tests/Feature/PickupCalendarTest.php'],{env:{...process.env,EA_UI_FIXTURE_DIR:temporary},stdio:'pipe'});}catch(error){throw new Error(error.stdout?.toString()||error.message,{cause:error});}
 const manifest=JSON.parse(readFileSync(join(temporary,'pickup-agenda-manifest.json')));
 const markup=file=>readFileSync(join(temporary,file),'utf8').replace(/https?:\/\/localhost(?::\d+)?/g,'').replace(/https?:\/\/[^/"\s]+\/build\//g,'/build/');
 const calls=[];let mode='normal',failure=0,browser,socket;
 const keyOf=url=>{const copy=new URL(url);if(copy.pathname==='/pickups/day'){if(!copy.searchParams.has('page'))copy.searchParams.set('page','1');if(!copy.searchParams.has('per_page'))copy.searchParams.set('per_page','10');}else if(copy.pathname.includes('/accounts/')){if(!copy.searchParams.has('page'))copy.searchParams.set('page','1');if(!copy.searchParams.has('per_page'))copy.searchParams.set('per_page','20');}copy.searchParams.sort();return copy.pathname+'?'+copy.searchParams;};
 const server=createServer((request,response)=>{
  const url=new URL(request.url,'http://localhost');
  const key=keyOf(url),date=url.searchParams.get('date');
  if(url.pathname.startsWith('/pickups')){
   calls.push({path:url.pathname,query:Object.fromEntries(url.searchParams),summary:request.headers['x-pickup-agenda']==='accounts'});
   if(url.pathname.includes('/accounts/')&&(failure || (mode==='removed'&&url.pathname.includes(manifest.after_removal.missing_slug)))){response.statusCode=failure||404;failure=0;response.end('Dati non disponibili');return;}
   let file;
   if(url.pathname==='/pickups')file='pickup-calendar-'+(url.searchParams.get('month')||'2026-10')+'.html';
   else if(url.pathname==='/pickups/day'){
    const summary=request.headers['x-pickup-agenda']==='accounts';
    if(mode==='removed'&&date===manifest.date)file=summary?manifest.after_removal.accounts_file:manifest.after_removal.full_file;
    else if(mode==='changed'&&date===manifest.single.date&&summary)file=manifest.single_after_change.accounts_file;
    else if(date==='2026-10-18')file='pickup-day-empty.html';
    else if(summary)file=manifest.responses[key];
    else file=date===manifest.single.date?'pickup-single.html':'pickup-day-page-'+(url.searchParams.get('page')||'1')+'.html';
   }else if(mode==='changed'&&date===manifest.single.date&&url.searchParams.get('page')==='1')file=manifest.single_after_change.orders_file;
   else file=manifest.responses[key];
   if(file&&existsSync(join(temporary,file))){response.setHeader('Content-Type','text/html');response.end(markup(file));return;}
   response.statusCode=404;response.end('Fixture non trovata: '+key);return;
  }
  if(/^\/orders\/[0-9a-f-]{36}$/.test(url.pathname)){response.setHeader('Content-Type','text/html');response.end('<!doctype html><html><body><h1>Dettaglio ordine selezionato</h1></body></html>');return;}
  const file=resolve('public','.'+url.pathname);
  if(file.startsWith(resolve('public')+'/')&&existsSync(file)&&extname(file)){response.setHeader('Content-Type',({'.js':'text/javascript','.css':'text/css','.woff2':'font/woff2','.woff':'font/woff','.svg':'image/svg+xml'})[extname(file)]||'application/octet-stream');response.end(readFileSync(file));return;}
  response.setHeader('Content-Type','application/json');response.end(JSON.stringify({enabled:false,data:[],groups:[],unread:0}));
 });
 try{
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));const origin='http://127.0.0.1:'+server.address().port;
  browser=spawn(chrome,['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0','--user-data-dir='+join(temporary,'chrome'),'about:blank'],{stdio:['ignore','ignore','pipe']});
  const endpoint=await new Promise((resolve,reject)=>{let output='';const timer=setTimeout(()=>reject(new Error(output)),15000);browser.stderr.on('data',chunk=>{output+=chunk;const match=output.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(match){clearTimeout(timer);resolve(match[1]);}});browser.once('exit',code=>{clearTimeout(timer);reject(new Error('Chrome '+code));});});
  const pages=await(await fetch('http://127.0.0.1:'+new URL(endpoint).port+'/json/list')).json();socket=new WebSocket(pages.find(page=>page.type==='page').webSocketDebuggerUrl);await new Promise(resolve=>socket.addEventListener('open',resolve,{once:true}));
  let sequence=0;const pending=new Map();
  socket.addEventListener('message',({data})=>{const result=JSON.parse(data),handler=pending.get(result.id);if(handler){pending.delete(result.id);result.error?handler.reject(new Error(result.error.message)):handler.resolve(result.result);}});
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>reject(new Error('Chrome timeout '+method)),15000);pending.set(id,{resolve:value=>{clearTimeout(timer);resolve(value);},reject:error=>{clearTimeout(timer);reject(error);}});socket.send(JSON.stringify({id,method,params}));});
  const evaluate=async code=>{const result=await send('Runtime.evaluate',{expression:'(()=>{'+code+'})()',returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
  const waitFor=async code=>{for(let i=0;i<150;i++){if(await evaluate('return Boolean('+code+');'))return;await new Promise(resolve=>setTimeout(resolve,30));}throw new Error('Not ready: '+code+' '+await evaluate('return document.body.innerText.slice(-600);'));};
  const navigate=async path=>{await evaluate('window.__previous=true;');await send('Page.navigate',{url:origin+path});await waitFor('!window.__previous && document.readyState==="complete" && document.querySelector("h1")');await evaluate('return document.fonts.ready.then(()=>true);');};
  const viewport=width=>send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
  const check=async label=>{const result=await evaluate('return {width:innerWidth,scroll:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll("main *")].filter(node=>{const r=node.getBoundingClientRect();return r.width&&r.right>innerWidth+1&&getComputedStyle(node).position!=="fixed";}).slice(0,5).map(node=>node.className)};');assert.ok(result.scroll<=result.width+1,label+' '+JSON.stringify(result));};
  const ordersCalls=()=>calls.filter(call=>call.path.includes('/accounts/'));
  const openFirst=async()=>{await evaluate('document.querySelector("[data-pickup-account] > summary").click();');await waitFor('document.querySelector("[data-pickup-account][open] [data-pickup-orders-page]") && !document.querySelector("[data-pickup-orders][aria-busy=true]")');};
  const currentIds=()=>evaluate("return [...document.querySelectorAll(\"[data-pickup-account][open] input[name='ids[]']\")].map(input=>input.value);");
  const dayUrl='/pickups/day?date='+manifest.date+'&page=1&per_page=10';
  const singleUrl='/pickups/day?date='+manifest.single.date+'&page=1&per_page=10';
  for(const width of[320,375,390,430,768,1024,1440])await t.test(width+'px: calendario, 10 account chiusi e ordini senza overflow',async()=>{
   await viewport(width);await navigate('/pickups?month=2026-10');await check('calendario '+width);
   const before=ordersCalls().length;await navigate(dayUrl);
   assert.equal(await evaluate('return document.querySelectorAll("[data-pickup-account]").length;'),10);
   assert.equal(await evaluate('return document.querySelectorAll("[data-pickup-account][open],.pickup-order-card").length;'),0);
   assert.equal(ordersCalls().length,before);await check('account '+width);
   await openFirst();assert.equal(ordersCalls().length,before+1);assert.equal((await currentIds()).length,7);await check('ordini '+width);
   assert.ok(await evaluate('return [...document.querySelectorAll(".pickup-account-heading")].every(node=>node.getBoundingClientRect().height>=44);'));
   if(process.env.EA_PICKUP_SCREENSHOTS)writeFileSync(join(process.env.EA_PICKUP_SCREENSHOTS,'agenda-open-'+width+'.png'),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
  });
  await t.test('47 ritiri: pagine 20/20/7, stampa limitata e cache senza richieste duplicate',async()=>{
   await navigate(singleUrl);const before=ordersCalls().length;await openFirst();assert.equal(ordersCalls().length,before+1);
   const first=await currentIds();assert.equal(first.length,20);assert.ok(first.every(id=>/^[0-9a-f-]{36}$/.test(id)));
   await evaluate('document.querySelector(".pickup-orders-pagination a[rel=next]").click();');await waitFor("document.querySelector(\"[data-pickup-orders-page='2']\")");
   const second=await currentIds();assert.equal(second.length,20);assert.equal(second.filter(id=>first.includes(id)).length,0);
   const print=await evaluate('const form=document.querySelector(".pickup-print-form");return {path:new URL(form.action).pathname,ids:new FormData(form).getAll("ids[]")};');assert.equal(print.path,'/labels');assert.deepEqual(print.ids,second);
   await evaluate('document.querySelector("[data-select-labels]").click();');assert.equal(await evaluate('return new FormData(document.querySelector(".pickup-print-form")).getAll("ids[]").length;'),0);
   await evaluate('document.querySelector("[data-select-labels]").click();');
   await evaluate('document.querySelector(".pickup-orders-pagination a[rel=next]").click();');await waitFor("document.querySelector(\"[data-pickup-orders-page='3']\")");const third=await currentIds();assert.equal(third.length,7);assert.equal(new Set([...first,...second,...third]).size,47);
   const requested=ordersCalls().length;await evaluate('document.querySelector(".pickup-orders-pagination a[rel=prev]").click();');await waitFor("document.querySelector(\"[data-pickup-orders-page='2']\")");assert.equal(ordersCalls().length,requested);
   await evaluate('document.querySelector("[data-pickup-account] > summary").click();');await openFirst();assert.equal(ordersCalls().length,requested);assert.deepEqual(await currentIds(),second);
   const link=await evaluate('return new URL(document.querySelector(".pickup-order-card footer a").href).pathname;');assert.match(link,/^\/orders\/[0-9a-f-]{36}$/);
  });
  await t.test('paginazione di 10 account e calendario senza ricaricare il documento',async()=>{
   await navigate('/pickups?month=2026-10');await evaluate('window.__agendaMarker="same-page";');
   await evaluate('document.querySelector(\'[data-pickup-date="'+manifest.date+'"]\').click();');await waitFor('document.querySelectorAll("[data-pickup-account]").length===10');
   await openFirst();const before=ordersCalls().length;
   await evaluate('document.querySelector(".pickup-accounts-pagination a[rel=next]").click();');await waitFor("document.querySelector(\"[data-pickup-page='2']\")");assert.equal(ordersCalls().length,before);assert.equal(await evaluate('return document.querySelectorAll("[data-pickup-account]").length;'),10);
   await evaluate('document.querySelector(".pickup-accounts-pagination a[rel=prev]").click();');await waitFor("document.querySelector(\"[data-pickup-page='1'] [data-pickup-account][open] [data-pickup-orders-page]\")");assert.equal(ordersCalls().length,before);
   await evaluate('document.querySelector("[data-pickup-agenda] .page-back a").click();');await waitFor('document.querySelector("[data-pickup-calendar]")');
   await evaluate('document.querySelector(\'[data-pickup-date="'+manifest.single.date+'"]\').click();');await waitFor('document.querySelector("[data-pickup-day-total]")?.textContent==="47"');assert.equal(await evaluate('return document.querySelectorAll("[data-pickup-account][open]").length;'),0);
   assert.equal(await evaluate('return window.__agendaMarker;'),'same-page');
  });
  await t.test('errore recuperabile conserva il gruppo; Enter apre il riepilogo',async()=>{
   await navigate(singleUrl);failure=503;
   await evaluate('document.querySelector("[data-pickup-account] > summary").focus();');assert.equal(await evaluate('return document.activeElement.tagName;'),'SUMMARY');await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',text:'\r',unmodifiedText:'\r',windowsVirtualKeyCode:13});await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});await waitFor('document.querySelector("[data-pickup-error]")');
   assert.equal(await evaluate('return document.querySelector("[data-pickup-account]").open;'),true);
   await evaluate('document.querySelector("[data-pickup-error] button").click();');await waitFor('document.querySelector("[data-pickup-orders-page]")');assert.equal((await currentIds()).length,20);
  });
  await t.test('mutazione reale invalida cache, aggiorna i conteggi a 46 e conserva espansione selezione e scroll',async()=>{
   failure=0;await navigate(singleUrl);await openFirst();const previous=await currentIds();
   await evaluate("const boxes=document.querySelectorAll(\"input[name='ids[]']\");boxes[1].checked=false;boxes[1].dispatchEvent(new Event(\"change\",{bubbles:true}));window.scrollTo({top:850,behavior:\"instant\"});");
   const scroll=await evaluate('return scrollY;'),before=ordersCalls().length;mode='changed';
   await evaluate('window.dispatchEvent(new CustomEvent("ea:workspace-updated",{detail:{kind:"order"}}));');
   await waitFor("document.querySelector(\"[data-pickup-day-total]\")?.textContent===\"46\" && document.querySelector(\"[data-pickup-orders-total='46']\")");
   assert.ok(ordersCalls().length>before);assert.equal(await evaluate('return document.querySelector("[data-pickup-account]").open;'),true);
   assert.ok(Math.abs((await evaluate('return scrollY;'))-scroll)<3);
   const current=await currentIds();assert.equal(current.length,20);assert.ok(!current.includes(manifest.single_after_change.removed_order_slug));
   if(current.includes(previous[1]))assert.equal(await evaluate('return document.querySelector(\'input[value="'+previous[1]+'"]\').checked;'),false);
   mode='normal';
  });
  await t.test('gli aggiornamenti conservano il focus del giorno e del riepilogo senza aprire gruppi chiusi',async()=>{
   await navigate('/pickups?month=2026-10');
   await evaluate(`document.querySelector('[data-pickup-date="${manifest.date}"]').focus();window.__oldCalendar=document.querySelector('[data-pickup-calendar]');window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}));`);
   await waitFor('window.__oldCalendar!==document.querySelector("[data-pickup-calendar]")');
   assert.equal(await evaluate('return document.activeElement.dataset.pickupDate;'),manifest.date);
   await navigate(dayUrl);const before=ordersCalls().length;
   const slug=await evaluate('const summary=document.querySelector("[data-pickup-account] > summary");summary.focus();window.__oldAccounts=document.querySelector("[data-pickup-accounts]");return summary.parentElement.dataset.pickupAccount;');
   await evaluate('window.dispatchEvent(new CustomEvent("ea:workspace-updated",{detail:{kind:"order"}}));');
   await waitFor('window.__oldAccounts!==document.querySelector("[data-pickup-accounts]")');
   assert.equal(await evaluate('return document.activeElement.closest("[data-pickup-account]")?.dataset.pickupAccount;'),slug);
   assert.equal(ordersCalls().length,before);
  });
  await t.test('account non più visibile con risposta 404 scompare e i riepiloghi si riallineano; nessuna richiesta ai polling generici',async()=>{
   await navigate(dayUrl);const before=calls.length;await evaluate('window.dispatchEvent(new Event("ea:workspace-polled"));');await new Promise(resolve=>setTimeout(resolve,250));assert.equal(calls.length,before);
   mode='removed';await evaluate('document.querySelector("[data-pickup-account] > summary").click();');await waitFor('document.querySelector("[data-pickup-day-total]")?.textContent==="40"');
   assert.equal(await evaluate('return !!document.querySelector(\'[data-pickup-account="'+manifest.after_removal.missing_slug+'"]\');'),false);assert.equal(await evaluate('return document.querySelectorAll("[data-pickup-account][open]").length;'),0);mode='normal';
  });
 }finally{socket?.close();if(browser&&browser.exitCode===null)await new Promise(resolve=>{browser.once('exit',resolve);browser.kill('SIGTERM');});server.closeAllConnections();await new Promise(resolve=>server.close(resolve));rmSync(temporary,{recursive:true,force:true,maxRetries:5,retryDelay:100});}
});
