import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, existsSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, extname } from 'node:path';

const chrome = process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

test('Customer revision: routes, sender disclosure, tariff states and responsive filters', { skip: !existsSync(chrome), timeout: 180000 }, async t => {
    const temporary = mkdtempSync(join(tmpdir(), 'ea-ui-'));
    let browser, server, socket, phpServer;
    let customerType = 'business';
    let zoneFeedRequests = 0;
    let actionRequests = 0, failNextAction = false;
    try {
        execFileSync('php', ['artisan', 'test', '--compact', 'tests/Feature/WorkspacePresentationTest.php', 'tests/Feature/OperationalFlowTest.php'], { env: { ...process.env, EA_UI_FIXTURE_DIR: temporary }, stdio: 'pipe' });
        const manifest = JSON.parse(readFileSync('public/build/manifest.json'));
        const styles = ['resources/css/app.css', 'resources/css/experience.css'].map(key => `<link rel="stylesheet" href="/build/${manifest[key].file}">`).join('');
        const fixture = `<!doctype html><html lang="it"><head><meta name="viewport" content="width=device-width,initial-scale=1">${styles}</head><body><main style="padding:20px;max-width:800px;margin:auto">
          <form id="fixture"><label for="choice">Rider disponibile</label><select id="choice" name="rider" required><option value="">Seleziona…</option>${Array.from({ length: 15 }, (_, index) => `<option value="${index}" ${index === 3 ? 'disabled' : ''}>${index === 12 ? 'Destinatario Test Registro' : `Rider ${index}`}</option>`).join('')}</select>
          <label for="date">Data</label><input id="date" name="date" type="date" value="2026-10-12" min="2026-10-10" max="2026-11-20" required>
          <label for="time">Orario</label><input id="time" name="time" type="time" value="09:30" min="09:00" max="12:00" step="900" required>
          <label for="datetime">Previsione</label><input id="datetime" name="datetime" type="datetime-local" value="2026-10-12T10:30" min="2026-10-12T10:00" max="2026-10-15T12:00" required>
          <label for="month">Mese</label><input id="month" type="month" value="2026-10" min="2026-09" max="2027-02">
          <label for="business">Tipologia</label><input id="business" list="types" maxlength="25"><datalist id="types"><option value="Abbigliamento"><option value="Artigianato"></datalist>
          <button type="reset">Ripristina</button></form><dialog id="modal"><label for="nested">Stato</label><select id="nested"><option>In attesa di conferma amministratore</option><option>Consegnato</option></select><button id="close-modal" type="button">Chiudi</button></dialog>
          </main><script type="module">import {attachFormPopovers} from '/resources/js/form-popovers.js'; window.cleanup=attachFormPopovers(); window.ready=true;</script></body></html>`;
        const portal = resolve('../Ea-express');
        server = createServer((request, response) => {
            const url = new URL(request.url, 'http://localhost');
            if (/^\/orders\/[0-9a-f-]{36}$/.test(url.pathname) && request.method === 'POST') {
                actionRequests++;
                let body = '';
                request.on('data', chunk => body += chunk);
                request.on('end', () => {
                    response.setHeader('Content-Type', 'application/json');
                    if (failNextAction) { failNextAction = false; response.statusCode = 422; response.end(JSON.stringify({errors:{status:['Transizione non disponibile. I dati sono conservati.']}})); return; }
                    const status = body.match(/name="status"\r\n\r\n([^\r]+)/)?.[1];
                    const fixture = join(temporary, `ajax-${status}.json`);
                    if (!existsSync(fixture)) { response.statusCode = 422; response.end(JSON.stringify({message:'Transizione non disponibile'})); return; }
                    const result=JSON.parse(readFileSync(fixture));result.html=result.html.replace(/https?:\/\/localhost(?::\d+)?/g,'').replace(/https?:\/\/[^/"\s]+\/build\//g,'/build/');setTimeout(()=>response.end(JSON.stringify(result)),100);
                });
                return;
            }
            if (['/pending','/pickups','/messages','/pickups/new','/shipments','/rates','/statistics','/couriers','/corrieri'].includes(url.pathname)) { response.setHeader('Content-Type', 'text/html'); response.end(readFileSync(join(portal, 'dist/index.html'))); return; }
            if (url.pathname === '/api/v1/customer/sender-address') { response.setHeader('Content-Type','application/json');response.end(JSON.stringify({data:null}));return; }
            if (url.pathname.endsWith('/rates/quote')) {
              const city=url.searchParams.get('city'); const postal=url.searchParams.get('postal_code');
              response.setHeader('Content-Type','application/json');
              if (city==='Errore') {response.statusCode=503;response.end('{}');return;}
              const payload=city==='Assente'?{available:false,territory_valid:true,reason:'Nessuna corrispondenza compatibile nel listino.'}:{available:true,territory_valid:true,price_cents:postal==='81031'?600:850,postal_code:postal || '81100',area:postal==='81031'?'Caserta Nord':'Caserta',zone:'Centro',shipping_type:url.searchParams.get('shipping_type') || 'regional',delivery_time:'1–2 giorni lavorativi',reason:null};
              setTimeout(()=>response.end(JSON.stringify(payload)),postal==='81031'?100:400);return;
            }
            if (url.pathname === '/api/v1/customer/rates') {response.setHeader('Content-Type','application/json');response.end(JSON.stringify({rates:{data:[],total:0,current_page:1,last_page:1},areas:['Caserta']}));return;}
            if (url.pathname === '/api/v1/customer/orders') {response.setHeader('Content-Type','application/json');response.end(JSON.stringify({data:[],meta:{current_page:2,last_page:7,total:140}}));return;}
            if (url.pathname === '/api/v1/customer/statistics') {response.setHeader('Content-Type','application/json');response.end(JSON.stringify({total:0,delivered:0,in_progress:0,cancelled:0,changes:{total:null,delivered:null,in_progress:null,cancelled:null,gross_cents:null,delivered_spend_cents:null,net_cents:null},gross_cents:0,delivered_spend_cents:0,trend:[],months:[],chart_summaries:{orders:{total:0},revenue:{total:0},months:{total:0}},from:'2026-10-01',to:'2026-10-05',previous_from:'2026-09-26',previous_to:'2026-09-30',net_cents:0,regional_count:0,external_count:0,completion_percent:0}));return;}
            if (url.pathname === '/api/v1/customer/auth/me') { response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({user:{slug:'24369069-5868-49fd-b04c-3709fa3b5a81',name:'Azienda Test Distribuzione Metropolitana',email:'test@example.test',role:'customer',sender_type:customerType,business_type:'Logistica'}})); return; }
            if (url.pathname === '/api/v1/customer/pending') { response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({data:[],totals:{incoming:12550,outgoing:0},meta:{current_page:1,last_page:1,total:0}})); return; }
            if (/^\/orders\/[0-9a-f-]+\/location$/.test(url.pathname)) { response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({order_slug:url.pathname.split('/')[2],state:'ready',eligible:false,status_label:'In consegna',rider:{name:'Amministratore operativo EA Express'},location:null,pickup:{point:null},delivery:{point:null}})); return; }
            if (url.pathname === '/rider-operations/feed') { zoneFeedRequests++; response.setHeader('Content-Type','application/json'); response.end(readFileSync(join(temporary,'rider-feed.json'))); return; }
            if (url.pathname === '/fixture') { response.setHeader('Content-Type', 'text/html'); response.end(fixture); return; }
            const file = url.pathname.startsWith('/assets/') ? resolve(portal, 'dist', `.${url.pathname}`) : url.pathname.startsWith('/build/') ? resolve('public', `.${url.pathname}`) : ['/brand.svg', '/favicon.svg'].includes(url.pathname) ? resolve('public', `.${url.pathname}`) : url.pathname.startsWith('/resources/js/') ? resolve(`.${url.pathname}`) : join(temporary, url.pathname.slice(1));
            if (existsSync(file) && extname(file)) {
                let data = readFileSync(file);
                response.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.svg': 'image/svg+xml' })[extname(file)] || 'application/octet-stream');
                if (extname(file) === '.html') data = data.toString().replace(/https?:\/\/localhost(?::\d+)?/g, '').replace(/https?:\/\/[^/"\s]+\/build\//g, '/build/');
                response.end(data); return;
            }
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify(url.pathname.includes('configuration') ? { enabled: false } : { data: [], groups: [], unread: 0, available: false, now: '2026-10-02T09:00:00+02:00' }));
        });
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        let origin = `http://127.0.0.1:${server.address().port}`;
        browser = spawn(chrome, ['--headless=new', '--disable-background-timer-throttling', '--disable-renderer-backgrounding', '--disable-backgrounding-occluded-windows', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${join(temporary, 'chrome')}`, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
        const endpoint = await new Promise((resolve, reject) => {
            let output = '';
            const timer = setTimeout(() => reject(new Error(`Chrome did not start: ${output.slice(-1000)}`)), 15000);
            browser.once('exit', code => { clearTimeout(timer); reject(new Error(`Chrome exited (${code}): ${output.slice(-1000)}`)); });
            browser.stderr.on('data', chunk => { output += chunk; const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timer); resolve(match[1]); } });
        });
        const port = new URL(endpoint).port;
        const pages = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
        socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
        await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
        let sequence = 0;
        const pending = new Map();
        socket.addEventListener('message', ({ data }) => { const result = JSON.parse(data); if (pending.has(result.id)) { const { resolve, reject } = pending.get(result.id); pending.delete(result.id); if (result.error) reject(new Error(result.error.message)); else resolve(result.result); } });
        const send = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params })); });
        const evaluate = async expression => {
            const result = await send('Runtime.evaluate', { expression: `(() => { return eval(${JSON.stringify(expression)}); })()`, awaitPromise: true, returnByValue: true });
            if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
            return result.result.value;
        };
        const navigate = async path => {
            await send('Page.navigate', { url: `${origin}/${path}` });
            for (let index = 0; index < 100; index++) {
                if (await evaluate(`location.pathname === ${JSON.stringify('/'+path.split('?')[0])} && document.readyState === 'complete' && (location.pathname === '/fixture' ? window.ready : location.pathname === '/pending' ? document.querySelector('.ea-picker-trigger') : document.querySelector('.ea-picker-trigger') || !document.querySelector('select,input[type="date"],input[type="month"]'))`)) return;
                await new Promise(resolve => setTimeout(resolve, 30));
            }
            throw new Error(`Page did not initialize: ${path}`);
        };
        const key = async (key, code = key) => { await send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: ({ Enter: 13, Escape: 27, Tab: 9, ArrowDown: 40, ArrowUp: 38, ArrowLeft: 37, ArrowRight: 39 })[key] }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: ({ Enter: 13, Escape: 27, Tab: 9, ArrowDown: 40, ArrowUp: 38, ArrowLeft: 37, ArrowRight: 39 })[key] }); };
        const clickPicker = id => evaluate(`document.getElementById(${JSON.stringify(id)}).nextElementSibling.click()`);
        const pause=ms=>new Promise(r=>setTimeout(r,ms));
        const go=async path=>{await send('Page.navigate',{url:origin+'/'+path});await pause(700);};
        const width=async value=>{await send('Emulation.setDeviceMetricsOverride',{width:value,height:1000,deviceScaleFactor:1,mobile:false});await pause(250);};
        const set=async (name,value)=>evaluate(`(()=>{const e=document.querySelector('[name="'+${JSON.stringify(name)}+'"]');const setter=Object.getOwnPropertyDescriptor(e.tagName==='SELECT'?HTMLSelectElement.prototype:HTMLInputElement.prototype,'value').set;setter.call(e,${JSON.stringify(value)});e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));})()`);
        const screenshot=async name=>{const shot=await send('Page.captureScreenshot',{captureBeyondViewport:true});writeFileSync(join(temporary,'ea-after-'+name+'.png'),Buffer.from(shot.data,'base64'));};
        await t.test('all booking fields have proportional widths, consistent heights and preserve payloads',async()=>{
          for (const path of ['pickups/new','orders_create.html']) {
            for (const value of [1440,1280,1024,768,375]) {
              await width(value);await go(path);
              const metrics=await evaluate(`(()=>{const form=document.querySelector('.booking-form');const controls=[...form.querySelectorAll('input:not([type=hidden]):not([type=radio]):not([type=checkbox]),.ea-picker-trigger')].filter(x=>x.getClientRects().length);return {overflow:document.documentElement.scrollWidth>innerWidth,controls:controls.map(x=>({name:x.name||x.previousElementSibling?.name,width:x.offsetWidth,height:x.offsetHeight})),notes:form.querySelector('textarea').offsetHeight,address:form.querySelector('[name="pickup_address"]').offsetWidth,postal:form.querySelector('[name="pickup_postal_code"]').offsetWidth,civic:form.querySelector('[name="pickup_street_number"]').offsetWidth,name:form.querySelector('[name="store_name"]').offsetWidth}})()`);
              assert.equal(metrics.overflow,false,`${path} ${value}`);
              assert.ok(metrics.controls.length>=20,JSON.stringify(metrics));
              assert.ok(metrics.controls.every(x=>x.height>=34&&x.height<=40),JSON.stringify(metrics));
              assert.equal(new Set(metrics.controls.map(x=>x.height)).size,1,`${path} ${value}: mixed control heights`);
              assert.ok(metrics.address>metrics.postal&&metrics.address>metrics.civic,JSON.stringify(metrics));
              assert.ok(metrics.postal<=120&&metrics.civic<=100,JSON.stringify(metrics));
              assert.ok(metrics.name>0&&metrics.notes>=48,JSON.stringify(metrics));
              console.log('all fields',path,value,JSON.stringify(metrics));
              await screenshot('all-fields-'+path.replaceAll('/','-')+'-'+value);
            }
            await set('pickup_address','Via Roma');await set('pickup_street_number','12/B');await set('pickup_city','Napoli');await set('pickup_postal_code','80121');
            assert.deepEqual(await evaluate(`(()=>{const f=new FormData(document.querySelector('.booking-form'));return ['pickup_address','pickup_street_number','pickup_city','pickup_postal_code'].map(name=>f.get(name))})()`),['Via Roma','12/B','Napoli','80121']);
            await set('package_type','other');await pause(100);
            assert.equal(await evaluate(`Boolean(document.querySelector('[name="package_description"]').getClientRects().length)`),true);
            await set('package_description','Imballo refrigerato');
            assert.equal(await evaluate(`new FormData(document.querySelector('.booking-form')).get('package_description')`),'Imballo refrigerato');
            await set('package_type','standard');await pause(100);
            assert.equal(await evaluate(`new FormData(document.querySelector('.booking-form')).has('package_description')`),false);
          }
        });
        await t.test('portal routes preserve the profile sender type and readonly business identity',async()=>{
          await width(1440);
          for(const accountType of ['private','online_shop','business']) {
            customerType=accountType;await go('pickups/new');
            assert.equal(await evaluate(`document.querySelector('.sender-profile-type strong').textContent`),{private:'Privato',online_shop:'Shop online',business:'Attività commerciale'}[accountType]);
            assert.equal(await evaluate(`document.querySelectorAll('[name="sender_type"]').length`),0);
            assert.equal(await evaluate(`document.querySelectorAll('[name="business_type"]').length`),accountType==='business'?1:0);
          }
          await go('pickups/new');
          assert.equal(await evaluate(`document.querySelector('[name="business_type"]').readOnly`),true);
          assert.equal(await evaluate(`new FormData(document.querySelector('.order-form')).get('business_type')`),'Logistica');
          assert.equal(await evaluate(`new FormData(document.querySelector('.order-form')).has('sender_type')`),false);
          assert.equal(await evaluate(`document.querySelectorAll('.shipping-quote-summary').length`),1);
          assert.equal(await evaluate(`document.querySelector('.shipping-quote-summary').closest('section').querySelector('.step').textContent`),'05');
          for(const path of ['couriers','corrieri']){await go(path);assert.equal(await evaluate(`document.querySelector('#not-found-title').textContent`),'Error Not Found 404');assert.equal(await evaluate(`document.querySelectorAll('a[href="/couriers"]').length`),0);}
        });
        await t.test('both tariff cards update, invalidate stale prices, and render failures',async()=>{
          for(const path of ['pickups/new','orders_create.html']){
            await width(1440);await go(path);
            assert.equal(await evaluate(`document.querySelector('.shipping-quote-summary').dataset.state`),'empty');
            await set('delivery_city','Caserta');await set('delivery_postal_code','81100');
            assert.equal(await evaluate(`document.querySelector('.shipping-quote-summary').dataset.state`),'loading');
            await pause(900);assert.match(await evaluate(`document.querySelector('.shipping-quote-summary').textContent`),/8,50/);
            await set('delivery_postal_code','81031');assert.equal(await evaluate(`document.querySelector('.shipping-quote-summary').dataset.state`),'loading');
            assert.doesNotMatch(await evaluate(`document.querySelector('.shipping-quote-summary').textContent`),/8,50/);
            await set('delivery_city','Aversa');await pause(600);assert.match(await evaluate(`document.querySelector('.shipping-quote-summary').textContent`),/6,00.*Aversa.*Caserta Nord/s);
            await set('shipping_type','external');await pause(600);assert.match(await evaluate(`document.querySelector('.shipping-quote-summary').textContent`),/Fuori regione/);
            for(const value of [1440,1280,1024,768,375]){await width(value);assert.equal(await evaluate(`document.documentElement.scrollWidth>innerWidth`),false,`${path} ${value}`);await screenshot(path.replaceAll('/','-')+'-ready-'+value);}
            await set('delivery_city','Assente');await pause(800);assert.equal(await evaluate(`document.querySelector('.shipping-quote-summary').dataset.state`),'unavailable');
            for(const value of [1440,1280,1024,768,375]){await width(value);assert.equal(await evaluate(`document.documentElement.scrollWidth>innerWidth`),false);await screenshot(path.replaceAll('/','-')+'-unavailable-'+value);}
            await set('delivery_city','Errore');await pause(600);assert.equal(await evaluate(`document.querySelector('.shipping-quote-summary').dataset.state`),'error');
            for(const value of [1440,1280,1024,768,375]){await width(value);assert.equal(await evaluate(`document.documentElement.scrollWidth>innerWidth`),false);await screenshot(path.replaceAll('/','-')+'-error-'+value);}
            await set('delivery_city','Caserta');await set('delivery_postal_code','');await pause(1600);assert.equal(await evaluate(`document.querySelector('[name="delivery_postal_code"]').value`),'81100');
            await set('delivery_postal_code','81031');await pause(370);await set('delivery_city','Errore');await pause(800);assert.equal(await evaluate(`document.querySelector('.shipping-quote-summary').dataset.state`),'error');
            await set('delivery_city','');assert.equal(await evaluate(`document.querySelector('.shipping-quote-summary').dataset.state`),'empty');
          }
        });
        await t.test('filter order, dialogs, reset, query persistence and pagination at every viewport',async()=>{
          for(const path of ['shipments','pickups','messages','rates','pending','statistics']){
            for(const value of [1440,1280,1024,768,375]){
              await width(value);await go(path);
              assert.equal(await evaluate(`Boolean(document.querySelector('.portal-filter-toolbar'))`),true,path);
              assert.ok(await evaluate(`[...document.querySelectorAll('.portal-filter-toolbar input:not([type=hidden]),.portal-filter-toolbar > .portal-filter-primary .ea-picker-trigger,.portal-filter-actions button')].filter(node=>node.getClientRects().length).every(node=>node.getBoundingClientRect().height>=36)`),path+' usable filter controls');
              assert.doesNotMatch(await evaluate(`document.querySelector('#main').textContent`),/undefined|NaN/);
              assert.equal(await evaluate(`document.documentElement.scrollWidth>innerWidth`),false,`${path} ${value}`);
              assert.equal(await evaluate(`document.querySelector('.portal-filter-actions').lastElementChild.getAttribute('aria-label')`),'Ripristina filtri');
              if(value===375){await evaluate(`document.querySelector('.portal-filter-actions [aria-haspopup="dialog"]').click()`);assert.equal(await evaluate(`document.querySelector('dialog').open`),true);await screenshot(path+'-filters-'+value);await key('Escape');}
              await screenshot(path+'-'+value);
            }
          }
          await width(1440);await go('shipments?q=Napoli&status=received&page=2');
          await evaluate(`document.querySelector('[aria-label="Pagina successiva"]').click()`);await pause(100);
          assert.equal(await evaluate(`new URLSearchParams(location.search).get('page')`),'3');
          assert.equal(await evaluate(`new URLSearchParams(location.search).get('q')`),'Napoli');
          await evaluate(`document.querySelector('.portal-filter-toolbar').requestSubmit()`);await pause(100);
          assert.equal(await evaluate(`new URLSearchParams(location.search).has('page')`),false);
          await evaluate(`document.querySelector('[aria-label="Ripristina filtri"]').click()`);await pause(100);assert.equal(await evaluate(`location.search`),'');
          await set('q','Bozza da azzerare');await evaluate(`document.querySelector('[aria-label="Ripristina filtri"]').click()`);await pause(100);assert.equal(await evaluate(`document.querySelector('[name="q"]').value`),'');
          await width(375);await go('shipments');
          await evaluate(`document.querySelector('.portal-filter-actions [aria-haspopup="dialog"]').click()`);
          await evaluate(`document.querySelector('[name="status"]').nextElementSibling.click()`);
          await evaluate(`Array.from(document.querySelectorAll('[role="option"]')).find(x=>x.dataset.value==='received'||x.textContent.includes('Ricevut')).click()`);
          await evaluate(`document.querySelector('.portal-filter-toolbar dialog [type="submit"]').click()`);await pause(100);
          assert.equal(await evaluate(`new URLSearchParams(location.search).get('status')`),'received');
        });
        await t.test('booking profile identity and checkout render at five widths',async()=>{
          for(const path of ['pickups/new','checkout.html']){
            for(const value of [1440,1280,1024,768,375]){
              await width(value);await go(path);assert.equal(await evaluate(`document.documentElement.scrollWidth>innerWidth`),false,`${path} ${value}`);
              if(path==='pickups/new'){
                assert.equal(await evaluate(`document.querySelectorAll('.sender-profile-type').length`),1);
                assert.equal(await evaluate(`document.querySelectorAll('.booking-form [name="sender_type"]').length`),0);
              }
              await screenshot(path.replaceAll('/','-')+'-'+value);
            }
          }
        });
    } finally {
        socket?.close();
        if (browser && browser.exitCode === null) await new Promise(resolve => { browser.once('exit', resolve); browser.kill('SIGTERM'); });
        server?.closeAllConnections();
        if (server) await new Promise(resolve => server.close(resolve));
        rmSync(temporary, {recursive:true, force:true, maxRetries:5, retryDelay:100});
    }
});
