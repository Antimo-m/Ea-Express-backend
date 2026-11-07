import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve, extname } from 'node:path';
import { tmpdir } from 'node:os';

const chrome=process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
test('Dashboard e ordini: card responsive, rischio reale e storico giornaliero con contesto preservato', {skip:!existsSync(chrome),timeout:180000}, async t=>{
 const temporary=mkdtempSync(join(tmpdir(),'ea-order-workspace-'));
 let browser,socket;
 const requests=[];
 try {execFileSync('php',['artisan','test','--compact','tests/Feature/OrderWorkspaceRevisionTest.php'],{env:{...process.env,EA_UI_FIXTURE_DIR:temporary},stdio:'pipe'});}
 catch(error){throw new Error(error.stdout?.toString() || error.message,{cause:error});}
 const markup=file=>readFileSync(join(temporary,file),'utf8').replace(/https?:\/\/localhost(?::\d+)?/g,'').replace(/https?:\/\/[^/"\s]+\/build\//g,'/build/');
 const ids=JSON.parse(readFileSync(join(temporary,'workspace-history-ids.json')));
 const routes={'/dashboard':'workspace-dashboard.html','/orders/incoming':'workspace-incoming.html','/orders/in-progress':'workspace-progress.html'};
 const server=createServer((request,response)=>{
  const url=new URL(request.url,'http://localhost');
  if(url.pathname==='/orders/history'){
   requests.push({query:Object.fromEntries(url.searchParams),fragment:request.headers['x-order-history']});
   const previous=url.searchParams.get('day')==='2026-11-05',page2=url.searchParams.get('day_page')==='2',filtered=url.searchParams.has('q'),fragment=request.headers['x-order-history']==='day';
   const file=filtered?(previous?'workspace-history-filtered-previous.html':'workspace-history-filtered.html'):previous?(fragment?'workspace-history-previous-fragment.html':'workspace-history-previous.html'):page2?(fragment?'workspace-history-page2-fragment.html':'workspace-history-page2.html'):'workspace-history.html';
   response.setHeader('Content-Type','text/html');response.end(markup(file));return;
  }
  if(routes[url.pathname]){response.setHeader('Content-Type','text/html');response.end(markup(routes[url.pathname]));return;}
  if(/^\/(orders|messages)\/[0-9a-f-]{36}$/.test(url.pathname)){response.setHeader('Content-Type','text/html');response.end('<!doctype html><html><body><h1>Dettaglio della richiesta selezionata</h1></body></html>');return;}
  if(url.pathname.startsWith('/resources/js/')){
   const moduleFile=resolve('.'+url.pathname+(extname(url.pathname)?'':'.js'));
   if(moduleFile.startsWith(resolve('resources/js')+'/')&&existsSync(moduleFile)){
    response.setHeader('Content-Type','text/javascript');response.end(readFileSync(moduleFile));return;
   }
  }
  const file=resolve('public','.'+url.pathname);
  if(file.startsWith(resolve('public')+'/')&&existsSync(file)&&extname(file)){
   response.setHeader('Content-Type',({'.js':'text/javascript','.css':'text/css','.woff2':'font/woff2','.woff':'font/woff','.svg':'image/svg+xml'})[extname(file)]||'application/octet-stream');response.end(readFileSync(file));return;
  }
  response.setHeader('Content-Type','application/json');response.end(JSON.stringify({enabled:false,data:[],groups:[],unread:0}));
 });
 try{
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const origin='http://127.0.0.1:'+server.address().port;
  browser=spawn(chrome,['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0','--user-data-dir='+join(temporary,'chrome'),'about:blank'],{stdio:['ignore','ignore','pipe']});
  const endpoint=await new Promise((resolve,reject)=>{let output='';const timer=setTimeout(()=>reject(new Error(output)),15000);browser.stderr.on('data',chunk=>{output+=chunk;const match=output.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(match){clearTimeout(timer);resolve(match[1]);}});browser.once('exit',code=>{clearTimeout(timer);reject(new Error('Chrome '+code));});});
  const pages=await(await fetch('http://127.0.0.1:'+new URL(endpoint).port+'/json/list')).json();
  socket=new WebSocket(pages.find(page=>page.type==='page').webSocketDebuggerUrl);
  await new Promise(resolve=>socket.addEventListener('open',resolve,{once:true}));
  let sequence=0;const pending=new Map();
  socket.addEventListener('message',({data})=>{const result=JSON.parse(data),handler=pending.get(result.id);if(handler){pending.delete(result.id);result.error?handler.reject(new Error(result.error.message)):handler.resolve(result.result);}});
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence,timer=setTimeout(()=>reject(new Error('Chrome timeout '+method)),15000);pending.set(id,{resolve:value=>{clearTimeout(timer);resolve(value);},reject:error=>{clearTimeout(timer);reject(error);}});socket.send(JSON.stringify({id,method,params}));});
  const evaluate=async source=>{const result=await send('Runtime.evaluate',{expression:'(()=>{'+source+'})()',returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw new Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;};
  const waitFor=async expression=>{for(let i=0;i<150;i++){if(await evaluate('return Boolean('+expression+');'))return;await new Promise(resolve=>setTimeout(resolve,30));}throw new Error('Not ready: '+expression+' '+await evaluate("return document.body.innerText.slice(-500);"));};
  const navigate=async path=>{await evaluate("window.__previous=true;");await send('Page.navigate',{url:origin+path});await waitFor('!window.__previous && document.readyState==="complete" && document.querySelector("h1")');await evaluate("return document.fonts.ready.then(()=>true);");};
  const viewport=width=>send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
  const check=async label=>{const dimensions=await evaluate("return {width:innerWidth,scroll:document.documentElement.scrollWidth,overflows:[...document.querySelectorAll(\"main *\")].filter(node=>{const r=node.getBoundingClientRect();return r.width&&r.right>innerWidth+1&&getComputedStyle(node).position!==\"fixed\";}).slice(0,5).map(node=>node.className)};");assert.ok(dimensions.scroll<=dimensions.width+1,label+' '+JSON.stringify(dimensions));};
  const clickSummary=async date=>{await evaluate('document.querySelector(\'[data-order-history-day="'+date+'"] > summary\').click();');await waitFor('document.querySelector(\'[data-order-history-day="'+date+'"][open] [data-history-loaded="true"]\') && !document.querySelector(\'[data-order-history-day="'+date+'"] [aria-busy="true"]\')');};
  for(const width of[320,375,390,430,768,1024,1440])await t.test(width+'px: dashboard e tre liste senza overflow',async()=>{
   await viewport(width);
   for(const path of['/dashboard','/orders/incoming','/orders/in-progress','/orders/history']){await navigate(path);await check(path+' '+width);}
   await navigate('/orders/in-progress');
   await evaluate("document.querySelector(\"[data-order-preview-toggle]\").click();");
   assert.ok(await evaluate("return document.querySelector(\".operational-preview\").getClientRects().length>0;"));
   assert.equal(await evaluate("return document.querySelectorAll(\".operational-preview a[href*=\\\"/messages/\\\"]\").length;"),0);
   await check('dettaglio espanso '+width);
   if(process.env.EA_ORDER_SCREENSHOTS){for(const[path,name]of[['/dashboard','dashboard'],['/orders/in-progress','progress'],['/orders/history','history']]){await navigate(path);writeFileSync(join(process.env.EA_ORDER_SCREENSHOTS,name+'-'+width+'.png'),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));}}
  });
  await t.test('anteprime native con hover e focus; Enter apre il dettaglio UUID corretto',async()=>{
   await viewport(390);await navigate('/dashboard');
   const link=await evaluate("const node=document.querySelector(\".dashboard-order-preview\");node.scrollIntoView({block:\"center\",behavior:\"instant\"});node.focus();const r=node.getBoundingClientRect();return {path:new URL(node.href).pathname,x:r.x+r.width/2,y:r.y+r.height/2,outline:getComputedStyle(node).outlineWidth};");
   assert.match(link.path,/^\/orders\/[0-9a-f-]{36}$/);assert.equal(link.outline,'3px');
   await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:link.x,y:link.y});
   assert.ok(await evaluate("return document.querySelector(\".dashboard-order-preview\").matches(\":hover\");"));
   await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
   await waitFor('location.pathname==='+JSON.stringify(link.path));
   await navigate('/orders/in-progress');
   const target=await evaluate("const row=document.querySelector(\"[data-order-link]\");row.focus();return new URL(row.dataset.orderLink,location.href).pathname;");
   await send('Input.dispatchKeyEvent',{type:'keyDown',key:' ',code:'Space',windowsVirtualKeyCode:32});
   await waitFor('location.pathname==='+JSON.stringify(target));
  });
  await t.test('priorità e puntini riflettono i dati; il messaggio rimane una singola azione separata',async()=>{
   await navigate('/orders/in-progress');
   const actual=await evaluate("return [...document.querySelectorAll(\"[data-operational-order]\")].map(node=>({urgent:node.classList.contains(\"operational-priority-urgent\"),border:getComputedStyle(node).borderLeftColor,risk:node.querySelector(\".operational-identity .recipient-dot\").getAttribute(\"aria-label\"),messages:node.querySelectorAll(\"a[href*=\\\"/messages/\\\"]\").length}));");
   assert.equal(actual.length,2);assert.notEqual(actual[0].border,actual[1].border);assert.ok(actual.every(item=>item.messages===1));
   assert.match(actual.find(item=>item.urgent).risk,/non affidabile/);assert.match(actual.find(item=>!item.urgent).risk,/nessun precedente/);
   await evaluate("document.querySelector(\".operational-actions a[href*=\\\"/messages/\\\"]\").click();");
   await waitFor('location.pathname.startsWith("/messages/")');
  });
  await t.test('40 ordini: apertura diretta ieri, cache A→B→A, pagina2 e reload preservano il contesto',async()=>{
   await viewport(375);await navigate('/orders/history');
   assert.equal(await evaluate("return document.querySelectorAll(\".history-order\").length;"),20);
   assert.equal(await evaluate("return document.querySelectorAll(\".archive-day\").length;"),3);
   assert.match(await evaluate("return document.querySelector(\".archive-day-heading\").textContent;"),/40 ordini/);
   await evaluate("window.__historyMarker=\"same-document\";");
   await clickSummary('2026-11-05');
   assert.equal(await evaluate("return document.querySelectorAll(\"[open] .history-order\").length;"),12);
   assert.equal(await evaluate("return document.querySelector(\"input[name=day]\").value;"),'2026-11-05');
   const afterLoad=requests.length;
   await clickSummary('2026-11-06');await clickSummary('2026-11-05');
   assert.equal(requests.length,afterLoad);
   assert.equal(await evaluate("return new URLSearchParams(location.search).get(\"day\");"),'2026-11-05');
   await clickSummary('2026-11-06');
   await evaluate("document.querySelector(\"[open] .workspace-pagination a[rel=next]\").click();");
   await waitFor('new URLSearchParams(location.search).get("day_page")==="2" && !document.querySelector("[aria-busy=true]")');
   const pageIds=await evaluate("return [...document.querySelectorAll(\"[open] .history-order\")].map(node=>new URL(node.dataset.orderLink,location.href).pathname.split(\"/\").at(-1));");
   assert.deepEqual(pageIds,ids.second);assert.equal(pageIds.filter(id=>ids.first.includes(id)).length,0);
   await clickSummary('2026-11-05');await clickSummary('2026-11-06');
   assert.equal(await evaluate("return new URLSearchParams(location.search).get(\"day_page\");"),'2');
   assert.equal(await evaluate("return window.__historyMarker;"),'same-document');
   await evaluate("document.querySelector('[data-order-history-day=\"2026-11-06\"] > summary').click();");
   await clickSummary('2026-11-06');
   assert.equal(await evaluate("return new URLSearchParams(location.search).get(\"day_page\");"),'2');
   await send('Page.reload');await waitFor('document.readyState==="complete" && document.querySelector("[open] .history-order")');
   assert.match(await evaluate("return document.querySelector(\"[open] .archive-page-caption\").textContent;"),/Pagina 2/);
   assert.equal(await evaluate("return document.querySelector(\"[open]\").dataset.orderHistoryDay;"),'2026-11-06');
  });
  await t.test('ricerca e stato restano nei link delle giornate e nelle richieste mirate',async()=>{
   await navigate('/orders/history?q=Archivio&status=delivered');await clickSummary('2026-11-05');
   const params=await evaluate("return Object.fromEntries(new URLSearchParams(location.search));");
   assert.equal(params.q,'Archivio');assert.equal(params.status,'delivered');assert.equal(params.day,'2026-11-05');
   assert.equal(requests.at(-1).fragment,'day');assert.equal(requests.at(-1).query.q,'Archivio');
  });
  await t.test('le giornate chiuse restano stabili durante gli eventi di polling',async()=>{
   await navigate('/orders/history');
   await evaluate('document.querySelector(".archive-day[open] > summary").click();');
   const before=requests.length;
   await evaluate('window.dispatchEvent(new Event("ea:workspace-polled"));window.dispatchEvent(new CustomEvent("ea:workspace-updated",{detail:{kind:"order"}}));');
   await new Promise(resolve=>setTimeout(resolve,250));
   assert.equal(requests.length,before);
   assert.equal(await evaluate('return document.querySelectorAll(".archive-day[open]").length;'),0);
  });
  await t.test('le conferme AJAX e i toast durano cinque secondi; il recupero resta disponibile',async()=>{
   await navigate('/orders/incoming');
   await evaluate('return import("/resources/js/workspace-updates.js").then(module=>{window.__feedback=module.workspaceFeedback;module.workspaceFeedback("Operazione completata.");return true;});');
   assert.match(await evaluate('return document.querySelector("[data-workspace-feedback]").textContent;'),/Operazione completata/);
   await new Promise(resolve=>setTimeout(resolve,5200));
   assert.equal(await evaluate('return document.querySelector("[data-workspace-feedback]");'),null);
   await evaluate('window.__feedback("Aggiornamento da riprovare.",{error:true,retry:async()=>{window.__retried=true;}});const flash=document.createElement("div");flash.dataset.toast="";flash.textContent="Conferma temporanea";document.querySelector("main").append(flash);');
   await new Promise(resolve=>setTimeout(resolve,5200));
   assert.equal(await evaluate('return document.querySelector("[data-toast]");'),null);
   assert.ok(await evaluate('return Boolean(document.querySelector("[data-workspace-feedback] button"));'));
   await evaluate('document.querySelector("[data-workspace-feedback] button").click();');
   await waitFor('window.__retried && !document.querySelector("[data-workspace-feedback]")');
   await evaluate('window.__feedback("Conferma chiudibile.");document.querySelector("[data-workspace-feedback] .btn-close").click();');
   assert.equal(await evaluate('return document.querySelector("[data-workspace-feedback]");'),null);
  });
 }finally{socket?.close();if(browser&&browser.exitCode===null)await new Promise(resolve=>{browser.once('exit',resolve);browser.kill('SIGTERM');});server.closeAllConnections();await new Promise(resolve=>server.close(resolve));rmSync(temporary,{recursive:true,force:true,maxRetries:5,retryDelay:100});}
});
