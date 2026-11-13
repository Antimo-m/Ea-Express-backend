import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { createHash } from 'node:crypto';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, existsSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, extname } from 'node:path';

const chrome = process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

test('EA pickers and operational pages: keyboard, validation, long content, contrast and responsive', { skip: !existsSync(chrome), timeout: 900000 }, async t => {
    const temporary = mkdtempSync(join(tmpdir(), 'ea-ui-'));
    const scenarioPattern = process.env.EA_UI_SCENARIOS ? new RegExp(process.env.EA_UI_SCENARIOS, 'i') : null;
    const scenario = (name, callback) => t.test(name, { skip: Boolean(scenarioPattern && !scenarioPattern.test(name)) }, callback);
    let browser, server, socket, phpServer;
    let zoneFeedRequests = 0, operationFeedFixture = 'rider-feed.json', operationDetailFixture;
    let financeWrites = 0, financeRejectNext = false;
    let financePageFailNext = false;
    let batchMode = false, batchStep = 0, batchWrites = 0, failBatchRefresh = false, settingsSaved = false, settingsWrites = 0;
    let chatData, chatWrites = 0, chatRejectNext = false;
    const chatReceipts = [];
    let actionRequests = 0, failNextAction = false, delayOrderRefresh = false;
    try {
        try {
            execFileSync('php', ['artisan', 'test', '--compact', 'tests/Feature/WorkspacePresentationTest.php', 'tests/Feature/OperationalFlowTest.php', 'tests/Feature/OperationalSurfaceTest.php', 'tests/Feature/OperationalDayTest.php', 'tests/Feature/OperationalRevisionTest.php', 'tests/Feature/GpsRemovalAndZoneOrdersTest.php', 'tests/Feature/PickupCalendarTest.php', 'tests/Feature/BalanceExperienceTest.php', 'tests/Feature/RoundedWorkspaceTest.php', 'tests/Feature/WorkspaceUiTest.php', 'tests/Feature/NotificationPresentationTest.php', 'tests/Feature/CustomerDirectoryTest.php', 'tests/Feature/RecipientDetailTest.php', 'tests/Feature/WorkspaceMutationTest.php', 'tests/Feature/ReceiptReconciliationTest.php', 'tests/Feature/FinancialStatementTest.php', 'tests/Feature/LaunchReadinessTest.php'], { env: { ...process.env, XDEBUG_MODE: 'off', EA_UI_FIXTURE_DIR: temporary }, stdio: 'pipe' });
        } catch (error) {
            throw new Error(`Browser fixture generation failed:\n${error.stdout?.toString() || error.message}`, { cause: error });
        }
        chatData = JSON.parse(readFileSync(join(temporary, 'rounded-chat.json')));
        const batch = JSON.parse(readFileSync(join(temporary, 'batch.json')));
        const markup = file => readFileSync(join(temporary, file)).toString().replace(/https?:\/\/localhost(?::\d+)?/g, '').replace(/https?:\/\/[^/"\s]+\/build\//g, '/build/');
        const notificationHistory = JSON.parse(readFileSync(join(temporary, 'notifications-history.json')));
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
            if (batchMode && request.method === 'POST' && /^\/balance\/[0-9a-f-]{36}\/payment$/.test(url.pathname)) {
                let body = ''; request.on('data', chunk => body += chunk); request.on('end', () => {
                    response.setHeader('Content-Type', 'application/json');
                    const expected = batch.steps[batchStep];
                    if (url.pathname !== expected.payment_path || !body.includes(`name="version"\r\n\r\n${expected.order_version}`)) { response.statusCode = 409; response.end(JSON.stringify({message:'Stato cambiato.'})); return; }
                    batchStep++; batchWrites++;
                    setTimeout(() => response.end(JSON.stringify({message:'Incasso registrato.',redirect:'/balance'})), 40);
                }); return;
            }
            if (batchMode && request.method === 'GET' && url.pathname === '/balance') {
                if (failBatchRefresh) { failBatchRefresh=false; response.statusCode=503; response.end('Aggiornamento temporaneamente non disponibile.'); return; }
                const page = url.searchParams.get('orders_page') === '2' ? '2' : '1';
                response.setHeader('Content-Type','text/html'); response.end(markup(`batch-${batchStep}-page${page}.html`)); return;
            }
            if (url.pathname === '/settings') {
                if (request.method === 'POST') { settingsSaved=true;settingsWrites++;request.resume(); }
                response.setHeader('Content-Type','text/html');response.end(markup(settingsSaved?'settings-after.html':'settings-before.html'));return;
            }
            if (notificationHistory[url.pathname]) {
                response.setHeader('Content-Type', 'application/json');
                response.end(JSON.stringify(notificationHistory[url.pathname])); return;
            }
            if (request.method === 'GET' && url.pathname === '/customers') {
                const page = ['1','2','3'].includes(url.searchParams.get('page')) ? url.searchParams.get('page') : '1';
                response.setHeader('Content-Type','text/html');
                response.end(readFileSync(join(temporary,`customers-page-${page}.html`)).toString().replace(/https?:\/\/localhost(?::\d+)?/g,'').replace(/https?:\/\/[^/"\s]+\/build\//g,'/build/')); return;
            }
            if (request.method === 'POST' && (['/expenses','/movements'].includes(url.pathname) || /^\/balance\/[0-9a-f-]{36}\/payment$/.test(url.pathname))) {
                financeWrites++;
                request.resume();
                request.on('end',()=>{ response.setHeader('Content-Type','application/json');
                    if(financeRejectNext){financeRejectNext=false;response.statusCode=422;response.end(JSON.stringify({errors:{amount:['Importo non valido: verifica il valore inserito.']}}));return;}
                    setTimeout(()=>{response.statusCode=201;response.end(JSON.stringify({message:'Operazione registrata.',redirect:'/balance'}));},100);
                });return;
            }
            if (/^\/messages\/[0-9a-f-]{36}(\/read)?$/.test(url.pathname)) {
                if (request.method === 'POST' || request.method === 'PATCH') {
                    let body='';request.on('data',chunk=>body+=chunk);request.on('end',()=>{
                        const data=JSON.parse(body);response.setHeader('Content-Type','application/json');
                        if(request.method==='PATCH') {chatReceipts.push(data);response.end(JSON.stringify({message:'Stato aggiornato.'}));return;}
                        chatWrites++;
                        if(chatRejectNext){chatRejectNext=false;response.statusCode=422;response.end(JSON.stringify({message:'Messaggio non valido.',errors:{body:['Verifica il testo.']}}));return;}
                        chatData.data.unshift({slug:crypto.randomUUID(),body:data.body,sender:'team',created_at:'2026-10-22T10:30:00Z',delivered_at:null,read_at:null});
                        setTimeout(()=>{response.statusCode=201;response.end(JSON.stringify({message:'Messaggio inviato.'}));},100);
                    });return;
                }
                response.setHeader('Content-Type',request.headers.accept?.includes('application/json')?'application/json':'text/html');
                response.end(request.headers.accept?.includes('application/json')?JSON.stringify(chatData):readFileSync(join(temporary,'rounded-thread.html')).toString().replace(/https?:\/\/localhost(?::\d+)?/g,''));return;
            }
            if (url.pathname === '/balance') {
                if (url.searchParams.get('q') === 'selezionata') {
                    if (financePageFailNext) { financePageFailNext = false; response.statusCode = 503; response.end('Temporaneamente non disponibile'); return; }
                    response.setHeader('Content-Type', 'text/html');
                    response.end(markup(url.searchParams.get('movements_page') === '2' ? 'finance-manual-categories.html' : 'finance-manual-first.html')); return;
                }
                const view=url.searchParams.get('ledger') || 'payments';
                response.setHeader('Content-Type','text/html');response.end(readFileSync(join(temporary,`finance-${view}.html`)).toString().replace(/https?:\/\/localhost(?::\d+)?/g,''));return;
            }
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
            if (url.pathname.startsWith('/pickups/accounts/')) {
                const manifest=JSON.parse(readFileSync(join(temporary,'pickup-agenda-manifest.json')));
                url.searchParams.set('page',url.searchParams.get('page') || '1');url.searchParams.set('per_page',url.searchParams.get('per_page') || '20');url.searchParams.sort();
                const file=manifest.responses[url.pathname+'?'+url.searchParams.toString()];
                if(!file){response.statusCode=404;response.end('Account non disponibile');return;}
                response.setHeader('Content-Type','text/html');response.end(markup(file));return;
            }
            if (url.pathname === '/pickups' || url.pathname === '/pickups/day') {
                const name = url.pathname === '/pickups' ? `pickup-calendar-${url.searchParams.get('month') || '2026-10'}.html` : url.searchParams.get('date') === '2026-10-17' ? `pickup-day-page-${url.searchParams.get('page') || '1'}.html` : 'pickup-day-empty.html';
                response.setHeader('Content-Type', 'text/html'); response.end(readFileSync(join(temporary,name)).toString().replace(/https?:\/\/localhost(?::\d+)?/g,'')); return;
            }
            if (['/pending', '/dashboard'].includes(url.pathname)) { response.setHeader('Content-Type', 'text/html'); response.end(readFileSync(join(portal, 'dist/index.html'))); return; }
            if (url.pathname === '/api/v1/customer/auth/me') { response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({user:{slug:'f4c0dc18-3893-4a91-b468-e88c408aa2e6',name:'Azienda Test Distribuzione Metropolitana',email:'test@example.test',role:'customer',sender_type:'business'}})); return; }
            if (url.pathname === '/api/v1/customer/dashboard') {
                const order = {slug:'24369069-5868-49fd-b04c-3709fa3b5a81',reference:'EA-EXPRESS-TEST',sender_type:'business',store_name:'Azienda Test Distribuzione Metropolitana',recipient_name:'Destinatario Test Nominativo Esteso',pickup_city:'Napoli',delivery_city:'Caserta',pickup_address:'Via Test Dimostrativa del Punto di Ritiro 145',delivery_address:'Via Test Partenza 10',pickup_date:'2026-10-17',pickup_from:'09:00',pickup_to:'12:00',status:'out_for_delivery',status_label:'In consegna',rider:{name:'Rider Test Calendario'}};
                response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({metrics:{active:1,pickups:1,delivered:0,attention:0,unread:1},live_order:order,recent:[order],next_pickups:[order]})); return;
            }
            if (url.pathname === '/api/v1/customer/pending') { response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({data:[],totals:{incoming:12550,outgoing:0},meta:{current_page:1,last_page:1,total:0}})); return; }
            if (url.pathname === '/rider-operations/zone-orders') { response.setHeader('Content-Type','text/html'); response.end(readFileSync(join(temporary, 'zone-orders.html')).toString().replace(/https?:\/\/localhost(?::\d+)?/g, '')); return; }
            if (url.pathname === '/rider-operations/detail') { response.setHeader('Content-Type','text/html'); response.end(readFileSync(join(temporary, operationDetailFixture || (url.searchParams.get('rider') && url.searchParams.get('date')==='2026-10-07' ? 'rider-mini-orders.html' : url.searchParams.get('page')==='2' ? 'zone-detail-page-2.html' : url.searchParams.get('date')==='2026-10-07' ? 'zone-detail-50.html' : 'rider-orders.html')))); return; }
            if (url.pathname === '/rider-operations/feed') { zoneFeedRequests++; response.setHeader('Content-Type','application/json'); response.end(readFileSync(join(temporary,operationFeedFixture))); return; }
            if (url.pathname === '/fixture') { response.setHeader('Content-Type', 'text/html'); response.end(fixture); return; }
            const file = url.pathname.startsWith('/assets/') ? resolve(portal, 'dist', `.${url.pathname}`) : url.pathname.startsWith('/build/') ? resolve('public', `.${url.pathname}`) : ['/brand.svg', '/favicon.svg'].includes(url.pathname) ? resolve('public', `.${url.pathname}`) : url.pathname.startsWith('/resources/js/') ? resolve(`.${url.pathname}`) : join(temporary, url.pathname.slice(1));
            if (existsSync(file) && extname(file)) {
                let data = readFileSync(file);
                response.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.svg': 'image/svg+xml' })[extname(file)] || 'application/octet-stream');
                if (extname(file) === '.html') data = data.toString().replace(/https?:\/\/localhost(?::\d+)?/g, '').replace(/https?:\/\/[^/"\s]+\/build\//g, '/build/');
                if (delayOrderRefresh && url.pathname === '/ajax-order.html') setTimeout(() => response.end(data), 100);
                else response.end(data); return;
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
        const send = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; const timeout = setTimeout(() => { pending.delete(id); reject(new Error('Chrome timeout: '+method+' '+JSON.stringify(params).slice(0,250))); }, 15000); pending.set(id, { resolve: result => { clearTimeout(timeout); resolve(result); }, reject: error => { clearTimeout(timeout); reject(error); } }); socket.send(JSON.stringify({ id, method, params })); });
        const evaluate = async expression => {
            const result = await send('Runtime.evaluate', { expression: `(() => { return eval(${JSON.stringify(expression)}); })()`, awaitPromise: true, returnByValue: true });
            if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
            return result.result.value;
        };
        const navigate = async path => {
            await evaluate('window.__eaPreviousDocument = true');
            await send('Page.navigate', { url: `${origin}/${path}` });
            for (let index = 0; index < 100; index++) {
                if (await evaluate(`!window.__eaPreviousDocument && location.pathname === ${JSON.stringify('/'+path.split('?')[0])} && document.readyState === 'complete' && (location.pathname === '/fixture' ? window.ready : location.pathname === '/pending' ? document.querySelector('.ea-picker-trigger') : document.querySelector('.ea-picker-trigger') || !document.querySelector('select,input[type="date"],input[type="month"]'))`)) return;
                await new Promise(resolve => setTimeout(resolve, 30));
            }
            throw new Error(`Page did not initialize: ${path}`);
        };
        const key = async (key, code = key) => { await send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, ...(key === 'Enter' ? {text:'\r'} : {}), windowsVirtualKeyCode: ({ Enter: 13, Escape: 27, Tab: 9, ArrowDown: 40, ArrowUp: 38, ArrowLeft: 37, ArrowRight: 39 })[key] }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: ({ Enter: 13, Escape: 27, Tab: 9, ArrowDown: 40, ArrowUp: 38, ArrowLeft: 37, ArrowRight: 39 })[key] }); };
        const clickPicker = id => evaluate(`document.getElementById(${JSON.stringify(id)}).nextElementSibling.click()`);
        await navigate('fixture');
        await scenario('native controls are hidden and keyboard searchable select sends original payload', async () => {
            assert.equal(await evaluate(`getComputedStyle(document.getElementById('choice')).display`), 'none');
            await clickPicker('choice');
            await evaluate(`const search=document.querySelector('.ea-picker-popup input'); search.value='Destinatario Test'; search.dispatchEvent(new Event('input'));`);
            await key('ArrowDown'); await key('Enter');
            assert.equal(await evaluate(`document.getElementById('choice').value`), '12');
            assert.equal(await evaluate(`new FormData(document.getElementById('fixture')).get('rider')`), '12');
            assert.match(await evaluate(`document.activeElement.getAttribute('aria-label')`), /Destinatario Test/);
            await clickPicker('choice'); await key('Escape');
            assert.equal(await evaluate(`document.querySelector('.ea-picker-popup')`), null);
        });
        await scenario('calendar bounds, month navigation and seven-day keyboard movement', async () => {
            await clickPicker('date');
            assert.equal(await evaluate(`document.querySelector('[data-calendar-day="2026-10-09"]').disabled`), true);
            await evaluate(`document.querySelector('[data-calendar-day="2026-10-12"]').focus()`); await key('ArrowDown'); await key('Enter');
            assert.equal(await evaluate(`document.getElementById('date').value`), '2026-10-19');
            await clickPicker('date');
            await evaluate(`document.querySelector('[aria-label="Mese successivo"]').click()`);
            assert.match(await evaluate(`document.activeElement.getAttribute('aria-label')`), /Mese successivo/);
            await evaluate(`document.querySelector('[data-calendar-day="2026-11-20"]').click()`);
            assert.equal(await evaluate(`document.getElementById('date').value`), '2026-11-20');
        });
        await scenario('time constraints reject out of range and off-step values', async () => {
            await clickPicker('time');
            const setClock = (hours, minutes) => evaluate(`const inputs=document.querySelectorAll('.time-popover-fields input'); inputs[0].value=${JSON.stringify(hours)}; inputs[1].value=${JSON.stringify(minutes)}; inputs[1].dispatchEvent(new Event('input'));`);
            await setClock('13', '0'); assert.equal(await evaluate(`document.querySelector('.picker-content > button').disabled`), true);
            await setClock('10', '7'); assert.equal(await evaluate(`document.querySelector('.picker-content > button').disabled`), true);
            await setClock('10', '45'); await evaluate(`document.querySelector('.picker-content > button').click()`);
            assert.equal(await evaluate(`document.getElementById('time').value`), '10:45');
        });
        await scenario('datetime validates date plus time and month picker has range bounds', async () => {
            await clickPicker('datetime');
            await evaluate(`const inputs=document.querySelectorAll('.time-popover-fields input'); inputs[0].value='9'; inputs[1].value='30'; inputs[0].dispatchEvent(new Event('input'));`);
            assert.equal(await evaluate(`document.querySelector('.picker-content > button').disabled`), true);
            await evaluate(`const input=document.querySelector('.time-popover-fields input'); input.value='11'; input.dispatchEvent(new Event('input'));document.querySelector('.picker-content > button').click();`);
            assert.equal(await evaluate(`document.getElementById('datetime').value`), '2026-10-12T11:30');
            await clickPicker('month'); assert.equal(await evaluate(`document.querySelector('.month-grid button').disabled`), true);
            await evaluate(`document.querySelectorAll('.month-grid button')[10].click()`);
            assert.equal(await evaluate(`document.getElementById('month').value`), '2026-11');
        });
        await scenario('free text suggestions, dynamic controls, disabled and reset remain synchronized', async () => {
            await clickPicker('business'); await evaluate(`const input=document.querySelector('.picker-content input'); input.value='Artigianato'; input.dispatchEvent(new Event('input'));`); await key('Enter');
            assert.equal(await evaluate(`document.getElementById('business').value`), 'Artigianato');
            await evaluate(`document.getElementById('fixture').insertAdjacentHTML('beforeend','<select id="dynamic"><option>Nuovo filtro</option></select>')`);
            assert.equal(await evaluate(`Boolean(document.getElementById('dynamic').nextElementSibling?.matches('.ea-picker-trigger'))`), true);
            await evaluate(`document.getElementById('dynamic').disabled=true`);
            assert.equal(await evaluate(`document.getElementById('dynamic').nextElementSibling.disabled`), true);
            await evaluate(`document.getElementById('fixture').reset()`);
            assert.match(await evaluate(`document.getElementById('date').nextElementSibling.textContent`), /12\/10\/2026/);
        });
        await scenario('open dropdown follows live options, preserves search and focus, and closes when loading', async () => {
            await clickPicker('choice');
            await evaluate(`const search=document.querySelector('.picker-content input');search.value='Destinatario Test';search.dispatchEvent(new Event('input'));document.getElementById('choice').options[13].label='Destinatario Test Registro — turno pomeriggio'`);
            assert.match(await evaluate(`document.querySelector('[role="option"]').textContent`), /turno pomeriggio/);
            assert.equal(await evaluate(`document.querySelector('.picker-content input').value`), 'Destinatario Test');
            await evaluate(`document.querySelector('[role="option"]').focus();document.getElementById('choice').options[13].disabled=true`);
            assert.equal(await evaluate(`document.querySelector('[role="option"]').disabled`), true);
            assert.equal(await evaluate(`document.activeElement===document.querySelector('.picker-content input')`), true);
            await evaluate(`document.getElementById('choice').setAttribute('aria-busy','true')`);
            assert.equal(await evaluate(`document.querySelector('.ea-picker-popup')===null`), true);
            assert.equal(await evaluate(`document.getElementById('choice').nextElementSibling.disabled`), true);
            await evaluate(`document.getElementById('choice').removeAttribute('aria-busy');document.getElementById('dynamic').disabled=false`);
            await clickPicker('dynamic');
            await evaluate(`document.getElementById('dynamic').insertAdjacentHTML('beforeend',Array.from({length:8},(_,i)=>'<option value="new-'+i+'">Nuovo Rider '+i+'</option>').join(''))`);
            assert.equal(await evaluate(`Boolean(document.querySelector('.picker-content input'))`), true);
            assert.equal(await evaluate(`document.querySelectorAll('[role="option"]').length`), 9);
            assert.equal(await evaluate(`document.querySelector('.ea-picker-popup').contains(document.activeElement)`), true);
            await evaluate(`document.getElementById('dynamic').replaceChildren()`);
            assert.match(await evaluate(`document.querySelector('.picker-options [role="status"]').textContent`), /Nessun risultato/);
            await key('Escape');
            await evaluate(`document.getElementById('business').readOnly=true`);
            assert.equal(await evaluate(`document.getElementById('business').nextElementSibling.disabled`), true);
            await evaluate(`document.getElementById('business').readOnly=false`);
        });
        await scenario('popup enters top layer inside modal and Escape preserves parent', async () => {
            await evaluate(`document.getElementById('modal').showModal()`); await clickPicker('nested');
            assert.equal(await evaluate(`document.querySelector('.ea-picker-popup').matches(':popover-open')`), true);
            assert.equal(await evaluate(`document.querySelector('.ea-picker-popup').closest('dialog').id`), 'modal');
            await key('Escape'); assert.equal(await evaluate(`document.getElementById('modal').open`), true);
            await evaluate(`document.getElementById('modal').close()`);
        });
        await scenario('mobile sheet fits 375px and traps focus', async () => {
            await send('Emulation.setDeviceMetricsOverride', { width: 375, height: 812, deviceScaleFactor: 1, mobile: true });
            await clickPicker('date');
            const rect = await evaluate(`(()=>{const r=document.querySelector('.ea-picker-popup').getBoundingClientRect();return {left:r.left,right:r.right,bottom:r.bottom,width:innerWidth,height:innerHeight}})()`);
            assert.ok(rect.left >= 0 && rect.right <= rect.width && rect.bottom <= rect.height, JSON.stringify(rect));
            await evaluate(`document.querySelector('.ea-picker-popup button:last-child').focus()`); await key('Tab');
            assert.equal(await evaluate(`document.querySelector('.ea-picker-popup').contains(document.activeElement)`), true);
            await key('Escape');
        });
        await scenario('React controlled selects preserve onChange, URL filters and labels after rerender', async () => {
            await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
            await navigate('pending');
            await evaluate(`document.querySelector('select').nextElementSibling.click()`);
            await evaluate(`Array.from(document.querySelectorAll('[role="option"]')).find(node=>node.value==='history').click();document.querySelector('.portal-filter-toolbar').requestSubmit()`);
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('view')`), 'history');
            assert.equal(await evaluate(`document.querySelector('select').value`), 'history');
            assert.match(await evaluate(`document.querySelector('select').nextElementSibling.textContent`), /Archivio/);
            await evaluate(`document.querySelectorAll('select')[1].nextElementSibling.click()`);
            await key('ArrowDown');
            await key('Enter');
            await evaluate(`new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))`);
            await evaluate(`document.querySelector('.portal-filter-toolbar').requestSubmit()`);
            assert.equal(await evaluate(`document.querySelectorAll('select')[1].value`), 'incoming');
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('direction')`), 'incoming');
        });
        await scenario('reports use compact orange controls, rate editor groups history, and login supports password visibility', async () => {
            await navigate('reports.html');
            assert.ok(await evaluate(`document.querySelector('[name="month"]').nextElementSibling.getBoundingClientRect().width < 240`));
            assert.equal(await evaluate(`getComputedStyle(document.querySelector('.filter-actions .icon-button')).backgroundColor`), 'rgb(230, 162, 61)');
            await navigate('rates.html');
            await evaluate(`document.querySelector('[data-rate-edit]').click()`);
            assert.equal(await evaluate(`document.getElementById('rate-editor').open`), true);
            assert.equal(await evaluate(`document.querySelector('[data-rate-save]').dataset.tooltip`), 'Salva tariffa');
            assert.equal(await evaluate(`document.querySelector('[data-rate-form] [name="max_weight_kg"]')`), null);
            assert.equal(await evaluate(`document.querySelector('[data-rate-form] [name="max_dimension_cm"]')`), null);
            assert.equal(await evaluate(`document.querySelector('[data-editor-history]').parentElement.classList.contains('rate-timing-actions')`), true);
            assert.equal(await evaluate(`document.querySelector('#rate-editor .modal-actions [data-dialog-close]').textContent.trim()`), '');
            assert.equal(await evaluate(`document.querySelector('#rate-editor .modal-actions [data-dialog-close]').getAttribute('aria-label')`), 'Torna indietro');
            assert.ok(await evaluate(`document.querySelector('#rate-editor .modal-actions [data-dialog-close]').getBoundingClientRect().height >= 24 && document.querySelector('#rate-editor .modal-actions [data-dialog-close]').getBoundingClientRect().height <= 44`));
            await send('Emulation.setDeviceMetricsOverride',{width:375,height:1000,deviceScaleFactor:1,mobile:true});
            assert.ok(await evaluate(`document.querySelector('#rate-editor .modal-actions [data-dialog-close]').getBoundingClientRect().width >= 24 && document.querySelector('#rate-editor .modal-actions [data-dialog-close]').getBoundingClientRect().width <= 44`));
            await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
            assert.ok(await evaluate(`document.querySelector('.sidebar-create').getBoundingClientRect().height >= 44`));
            await navigate('login.html');
            await evaluate(`document.querySelector('[data-password-toggle]').click()`);
            assert.equal(await evaluate(`document.querySelector('[name="password"]').type`), 'text');
            assert.equal(await evaluate(`document.querySelector('[data-password-toggle]').getAttribute('aria-pressed')`), 'true');
            await evaluate(`document.querySelector('[data-password-toggle]').click()`);
            assert.equal(await evaluate(`document.querySelector('[name="password"]').type`), 'password');
            await evaluate(`document.querySelector('[data-auth-form]').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))`);
            assert.equal(await evaluate(`document.querySelector('[data-auth-form]').getAttribute('aria-busy')`), 'true');
            assert.equal(await evaluate(`document.querySelector('[data-auth-form] button[type="submit"]').disabled`), true);
        });
        await scenario('login is centered and operational zones and dates work on desktop tablet and mobile', async () => {
            for (const width of [375,768,1024,1280,1440]) {
                await send('Emulation.setDeviceMetricsOverride', {width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('login.html');
                assert.ok(await evaluate(`(()=>{const form=document.querySelector('[data-auth-form]').getBoundingClientRect(),button=document.querySelector('.auth-submit').getBoundingClientRect();return Math.abs((button.left+button.width/2)-(form.left+form.width/2))<2})()`), `login centered at ${width}`);
                await navigate('orders_in-progress.html');
                assert.ok(await evaluate(`Array.from(document.querySelectorAll('.filter-field-small input')).every(input=>input.getBoundingClientRect().height<=36)`));
                await navigate('rider-operations.html');
                assert.ok(await evaluate(`document.querySelectorAll('.explorer-item').length>=9`));
                assert.equal(await evaluate(`document.querySelector('[name="from"]').nextElementSibling.tagName`), 'BUTTON');
                assert.equal(await evaluate(`document.querySelector('[name="to"]').nextElementSibling.tagName`), 'BUTTON');
                assert.equal(await evaluate(`document.querySelector('[data-operational-map]')`), null);
                await evaluate(`document.querySelector('[data-operations-view="rider"]').click()`);
                assert.equal(await evaluate(`document.querySelector('[data-operations-view="rider"]').getAttribute('aria-pressed')`),'true');
                await evaluate(`document.querySelector('[data-operations-view="zone"]').click()`);
                if(width===1440) {
                    const originalFeed=readFileSync(join(temporary,'rider-feed.json'));
                    const changed=JSON.parse(originalFeed); changed.zones[0].name='Zona aggiornata realtime';
                    writeFileSync(join(temporary,'rider-feed.json'),JSON.stringify(changed));
                    const requestsBefore=zoneFeedRequests;
                    await evaluate(`if(document.querySelector('[data-operations-refresh]')) document.querySelector('[data-operations-refresh]').click(); else window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                    for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-operations-list]').textContent.includes('Zona aggiornata realtime')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                    assert.ok(zoneFeedRequests>requestsBefore);
                    assert.ok(await evaluate(`document.querySelector('[data-operations-list]').textContent.includes('Zona aggiornata realtime')`));
                    writeFileSync(join(temporary,'rider-feed.json'),originalFeed);
                }
                await navigate('rider-history.html');
                assert.equal(await evaluate(`document.querySelector('[data-operational-map]')`),null);
                await navigate('rider-detail.html');
                assert.equal(await evaluate(`document.querySelector('.rider-order-events')`),null);
                assert.equal(await evaluate(`document.querySelectorAll('[data-rider-delivery]').length`),1);
                assert.ok(await evaluate(`document.querySelector('.rider-day-accounting').textContent.includes('Contabilità del periodo')`));
            }
        });
        await scenario('operational periods preserve Rider perspective, selection and navigation context without reloading', async () => {
            operationFeedFixture='revision-feed.json'; operationDetailFixture='rider-orders.html';
            const waitFor = async expression => {
                for(let index=0;index<100;index++) {
                    if(await evaluate(expression)) return;
                    await new Promise(resolve=>setTimeout(resolve,20));
                }
                assert.fail(`Operational state did not settle: ${expression}`);
            };
            const rangeFeed = JSON.parse(readFileSync(join(temporary,'revision-feed.json')));
            writeFileSync(join(temporary,'operations-range.json'),JSON.stringify({...rangeFeed,date:'2026-09-29',to:'2026-10-03'}));
            writeFileSync(join(temporary,'operations-single-day.json'),JSON.stringify({...rangeFeed,date:'2026-09-29',to:'2026-09-29'}));
            try {
                for(const width of [375,1440]) {
                    operationFeedFixture='revision-feed.json';
                    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                    await navigate('revision-zone.html');
                    await evaluate(`window.__operationsPage='preserved';document.querySelector('[data-operations-view="rider"]').click()`);
                    for(const mode of ['week','month','today']) {
                        const before=zoneFeedRequests;
                        await evaluate(`document.querySelector('.period-options a[href*="period=${mode}"]').click()`);
                        await waitFor(`new URL(location.href).searchParams.get('period')==='${mode}' && document.querySelector('[data-zone-updated]').textContent`);
                        for(let index=0;index<100 && zoneFeedRequests<=before;index++)await new Promise(resolve=>setTimeout(resolve,20));
                        assert.ok(zoneFeedRequests>before,`period ${mode} fetch @${width}`);
                        assert.equal(await evaluate(`document.querySelector('[data-operations-view="rider"]').getAttribute('aria-pressed')`),'true');
                        assert.equal(await evaluate(`window.__operationsPage`),'preserved');
                    }
                    const context = await evaluate(`(()=>{const seed=JSON.parse(document.querySelector('[data-zone-initial]').textContent),search=document.querySelector('[data-operations-search]'),sort=document.querySelector('[data-operations-sort]');search.value=seed.riders.find(rider=>rider.slug).name;search.dispatchEvent(new Event('input',{bubbles:true}));sort.value='delivered';sort.dispatchEvent(new Event('change',{bubbles:true}));return {q:search.value,selected:new URL(location.href).searchParams.get('selected')};})()`);
                    operationFeedFixture='operations-range.json';
                    await evaluate(`document.querySelector('[data-custom-period]').click();const from=document.querySelector('[name="from"]'),to=document.querySelector('[name="to"]');from.value='2026-09-29';from.dispatchEvent(new Event('change',{bubbles:true}));to.value='2026-10-03';to.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('[data-operations-period]').requestSubmit()`);
                    await waitFor(`document.querySelector('.filter-period-caption').textContent==='2026-09-29 – 2026-10-03'`);
                    const interval = await evaluate(`(()=>{const url=new URL(location.href),feed=new URL(document.querySelector('[data-rider-operations]').dataset.feed);return {from:url.searchParams.get('from'),to:url.searchParams.get('to'),feedFrom:feed.searchParams.get('from'),feedTo:feed.searchParams.get('to'),q:url.searchParams.get('q'),selected:url.searchParams.get('selected'),sort:url.searchParams.get('sort'),view:url.searchParams.get('view')};})()`);
                    assert.deepEqual(interval,{from:'2026-09-29',to:'2026-10-03',feedFrom:'2026-09-29',feedTo:'2026-10-03',q:context.q,selected:context.selected,sort:'delivered',view:'rider'});
                    assert.equal(await evaluate(`window.__operationsPage`),'preserved');
                    assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`range overflow @${width}`);
                    if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`operations-range-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
                    await waitFor(`Boolean(document.querySelector('[data-explorer-orders] a[href*="return_to="]'))`);
                    assert.ok(await evaluate(`document.querySelector('[data-explorer-orders] a[href*="return_to="]').href.includes('from%3D2026-09-29') && document.querySelector('[data-explorer-orders] a[href*="return_to="]').href.includes('to%3D2026-10-03')`));
                    const customUrl = await evaluate(`location.href`);
                    const beforeInvalid=zoneFeedRequests;
                    await evaluate(`const from=document.querySelector('[name="from"]');from.value='2026-10-04';from.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('[data-operations-period]').requestSubmit()`);
                    assert.equal(await evaluate(`document.querySelector('[name="to"]').validity.rangeUnderflow`),true);
                    await new Promise(resolve=>setTimeout(resolve,80));
                    assert.equal(zoneFeedRequests,beforeInvalid,'inverted range is rejected before loading');
                    operationFeedFixture='operations-single-day.json';
                    await evaluate(`const from=document.querySelector('[name="from"]'),to=document.querySelector('[name="to"]');from.value='2026-09-29';from.dispatchEvent(new Event('change',{bubbles:true}));to.value='2026-09-29';to.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('[data-operations-period]').requestSubmit()`);
                    await waitFor(`document.querySelector('.filter-period-caption').textContent==='2026-09-29 – 2026-09-29'`);
                    assert.equal(await evaluate(`new URL(location.href).searchParams.get('from')===new URL(location.href).searchParams.get('to')`),true);
                    operationFeedFixture='revision-feed.json';
                    await evaluate(`document.querySelector('.filter-reset').click()`);
                    await waitFor(`!new URL(location.href).searchParams.has('from') && document.querySelector('[data-custom-dates]').hidden`);
                    assert.equal(await evaluate(`new URL(location.href).searchParams.get('q')`),context.q);
                    assert.equal(await evaluate(`new URL(location.href).searchParams.get('sort')`),'delivered');
                    operationFeedFixture='operations-range.json';
                    await evaluate(`history.pushState(null,'',${JSON.stringify(customUrl)});window.dispatchEvent(new PopStateEvent('popstate'))`);
                    await waitFor(`document.querySelector('.filter-period-caption').textContent==='2026-09-29 – 2026-10-03'`);
                    assert.equal(await evaluate(`window.__operationsPage`),'preserved');
                    operationFeedFixture='revision-feed.json';
                    await evaluate(`history.back()`);
                    await waitFor(`!new URL(location.href).searchParams.has('from') && document.querySelector('[data-custom-dates]').hidden`);
                    assert.equal(await evaluate(`window.__operationsPage`),'preserved');
                    assert.equal(await evaluate(`new URL(location.href).searchParams.get('selected')`),context.selected);
                    await evaluate(`if(document.querySelector('[data-operations-refresh]')) document.querySelector('[data-operations-refresh]').click(); else window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                    await waitFor(`document.querySelector('[data-operations-view="rider"]').getAttribute('aria-pressed')==='true'`);
                }
            } finally { operationFeedFixture='rider-feed.json';operationDetailFixture=undefined; }
        });
        await scenario('GPS is absent from main pages and feedback keeps its colors', async () => {
            for (const page of ['dashboard.html','orders_incoming.html','orders_in-progress.html','orders_history.html','orders_1.html','rider-operations.html']) {
                await navigate(page);
                assert.equal(await evaluate(`document.querySelector('[data-rider-gps], [data-tracking-panel], [data-operational-map]')`), null);
                assert.equal(await evaluate(`document.body.textContent.includes('GPS')`), false);
            }
            await navigate('orders_1.html');
            await evaluate(`for(const kind of ['success','danger','warning']){const box=document.createElement('div');box.className='alert alert-'+kind;box.dataset.fixtureFeedback=kind;box.textContent='Esito operazione';document.querySelector('main').prepend(box)}`);
            for (const theme of ['light', 'dark']) {
                await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);
                const signals = await evaluate(`Array.from(document.querySelectorAll('[data-fixture-feedback]')).map(node=>({color:getComputedStyle(node).color,border:getComputedStyle(node).borderLeftWidth}))`);
                assert.equal(new Set(signals.map(signal=>signal.color)).size, 3);
                assert.ok(signals.every(signal=>parseFloat(signal.border)>=3));
            }
        });
        await scenario('zone summary navigates to compact cards and Rider displays cards immediately at all widths', async () => {
            const previousFeed = operationFeedFixture; operationFeedFixture = 'zones-50.json';
            try {
            for (const width of [1440,1280,768,375]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('zones-50.html?date=2026-10-07&view=zone&selected=Zona+00&sort=delivered');
                assert.equal(await evaluate(`document.querySelector('[data-explorer-orders]')`), null);
                assert.ok(await evaluate(`document.querySelector('[data-zone-orders]').textContent.includes('Vai agli ordini')`));
                assert.equal(await evaluate(`new URL(document.querySelector('[data-zone-orders]').href).searchParams.get('date')`),'2026-10-07');
                assert.equal(await evaluate(`new URL(document.querySelector('[data-zone-orders]').href).searchParams.get('zone')`),'Zona 00');
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`zone-summary-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
                await evaluate(`document.querySelector('[data-zone-orders]').click()`);
                for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('.operation-mini-card')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                assert.equal(await evaluate(`location.pathname`),'/rider-operations/zone-orders');
                assert.equal(await evaluate(`document.querySelectorAll('.operation-mini-card').length`),20);
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth`));
                assert.equal(await evaluate(`document.querySelector('.operation-info').getAttribute('aria-label')`),'Dettaglio ordine');
                assert.ok(await evaluate(`const link=document.querySelector('.operation-info');link.getBoundingClientRect().width>=24 && link.getBoundingClientRect().height>=24`));
                assert.ok(await evaluate(`document.querySelector('.operation-info').pathname.match(new RegExp("^/orders/[0-9a-f-]{36}$"))`));
                assert.ok(await evaluate(`new URL(document.querySelector('.operation-info').href).searchParams.get('return_to').startsWith('/rider-operations/zone-orders')`));
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`zone-orders-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
                await navigate('zones-50.html?date=2026-10-07&view=rider');
                for(let i=0,stable=0;i<250;i++){const ready=await evaluate(`document.querySelectorAll('[data-explorer-orders] .operation-mini-card').length===20 && document.querySelector('[data-explorer-orders]').getAttribute('aria-busy')!=='true'`);stable=ready?stable+1:0;if(stable===3)break;await new Promise(resolve=>setTimeout(resolve,20));}
                assert.equal(await evaluate(`document.querySelectorAll('[data-explorer-orders] .operation-mini-card').length`),20);
                assert.equal(await evaluate(`document.querySelector('[data-zone-orders]')`),null);
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth`));
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`rider-mini-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
            }
            } finally { operationFeedFixture = previousFeed; }
        });
        await scenario('searchable selectors remain compact with 5 50 and 200 accounts', async () => {
            await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
            await navigate('fixture');
            for (const count of [5,50,200]) {
                await evaluate(`(()=>{const select=document.getElementById('choice');select.replaceChildren(new Option('Scegli account',''),...Array.from({length:${count}},(_,index)=>new Option('Account '+index,String(index))));select.dispatchEvent(new Event('change',{bubbles:true}));})()`);
                await evaluate(`document.getElementById('choice').nextElementSibling.click()`);
                assert.ok(await evaluate(`document.querySelector('.ea-picker-popup input')`));
                assert.ok(await evaluate(`document.querySelector('.picker-options').getBoundingClientRect().height<=340`));
                await evaluate(`const search=document.querySelector('.ea-picker-popup input');search.value='Account ${count-1}';search.dispatchEvent(new Event('input',{bubbles:true}))`);
                await key('ArrowDown'); await key('Enter');
                assert.equal(await evaluate(`document.getElementById('choice').value`),String(count-1));
            }
        });
        await scenario('Zone Explorer handles 5 15 30 50 zones and riders, keyboard and preserved realtime selection', async () => {
            const originalFeed=readFileSync(join(temporary,'rider-feed.json'));
            try {
                for (const count of [5,15,30,50]) {
                    const data=JSON.parse(readFileSync(join(temporary,`zones-${count}.json`)));
                    writeFileSync(join(temporary,'rider-feed.json'),JSON.stringify(data));
                    for (const width of [375,768,1024,1280,1440]) {
                        await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                        await navigate(`zones-${count}.html`);
                        assert.equal(await evaluate(`document.querySelectorAll('.explorer-item').length`),count);
                        assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth`));
                        assert.ok(await evaluate(`document.querySelector('.explorer-list').getBoundingClientRect().height<=575`));
                        await evaluate(`document.querySelector('.explorer-item').focus()`);await key('ArrowDown');await key('Enter');
                        assert.equal(await evaluate(`document.querySelector('.explorer-detail h2').textContent`),'Zona 01');
                        await evaluate(`if(document.querySelector('[data-operations-refresh]')) document.querySelector('[data-operations-refresh]').click(); else window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                        await new Promise(resolve=>setTimeout(resolve,150));
                        assert.equal(await evaluate(`document.querySelector('.explorer-detail h2').textContent`),'Zona 01');
                        await evaluate(`const search=document.querySelector('[data-operations-search]');search.value='Zona 00';search.dispatchEvent(new Event('input'))`);
                        assert.equal(await evaluate(`document.querySelectorAll('.explorer-item').length`),1);
                        assert.equal(await evaluate(`document.querySelector('.explorer-detail h2').textContent`),'Zona 00');
                        await evaluate(`document.querySelector('[data-operations-search]').value='';document.querySelector('[data-operations-view="rider"]').click()`);
                        assert.equal(await evaluate(`document.querySelectorAll('.explorer-item').length`),count);
                    }
                }
            } finally {writeFileSync(join(temporary,'rider-feed.json'),originalFeed);}
        });
        await scenario('explicit refresh updates daily delivery counts and cards without GPS or reloading', async () => {
            operationFeedFixture='late-before.json';operationDetailFixture='late-orders-before.html';
            try {
                for(const width of [1440,1280,1024,768,375]){
                    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                    operationFeedFixture='late-before.json';operationDetailFixture='late-orders-before.html';
                    await navigate('late-day.html?view=rider');
                    let dailySnapshot, settled=0;
                    for(let i=0;i<100;i++){
                        dailySnapshot=await evaluate(`({undelivered:document.querySelector('[data-kpi="pending"]').textContent,orders:document.querySelector('[data-explorer-orders]')?.textContent || '',busy:document.querySelector('[data-explorer-orders]')?.getAttribute('aria-busy')==='true'})`);
                        settled=dailySnapshot.undelivered==='4' && dailySnapshot.orders.includes('07/10/2026 23:59') && !dailySnapshot.busy?settled+1:0;
                        if(settled>=3)break;
                        await new Promise(resolve=>setTimeout(resolve,20));
                    }
                    assert.equal(dailySnapshot.undelivered,'4');
                    assert.ok(dailySnapshot.orders.includes('07/10/2026 23:59'));
                    assert.equal(dailySnapshot.busy,false);
                    await evaluate(`window.__dailyPage='same'`);
                    operationFeedFixture='late-after.json';operationDetailFixture='late-orders-after.html';
                    await evaluate(`if(document.querySelector('[data-operations-refresh]')) document.querySelector('[data-operations-refresh]').click(); else window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                    for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-kpi="delivered"]').textContent==='17'`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                    assert.equal(await evaluate(`document.querySelector('[data-kpi="pending"]').textContent`),'3');
                    assert.equal(await evaluate(`document.querySelector('[data-kpi="overdue"]')`),null);
                    assert.equal(await evaluate(`window.__dailyPage`),'same');
                    assert.equal(await evaluate(`document.querySelector('[data-operational-map]')`),null);
                    if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`late-day-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
                }
            } finally { operationFeedFixture='rider-feed.json';operationDetailFixture=undefined; }
        });
        await scenario('operational units expand with keyboard and keep state through explicit refresh', async () => {
            for (const width of [1440,1280,1024,768,375]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                for (const page of ['orders_incoming.html','orders_in-progress.html']) {
                    await navigate(page);
                    assert.ok(await evaluate(`document.querySelectorAll('[data-operational-order]').length > 0`));
                    assert.ok(await evaluate(`document.documentElement.scrollWidth <= innerWidth`));
                    await evaluate(`document.querySelector('[data-order-preview-toggle]').focus()`);
                    await key('Enter');
                    assert.equal(await evaluate(`document.querySelector('[data-order-preview-toggle]').getAttribute('aria-expanded')`),'true');
                    assert.equal(await evaluate(`document.querySelector('.operational-preview').hidden`),false);
                    assert.ok(await evaluate(`document.querySelector('.operational-preview .operational-preview-addresses').children.length===2 && document.querySelector('.operational-preview .operational-preview-addresses > div:last-child p').textContent.trim().length>0`));
                    assert.ok(await evaluate(`document.querySelector('.operational-preview a').pathname.match(new RegExp("^/orders/[0-9a-f-]{36}$"))`));
                    if (process.env.EA_UI_SCREENSHOTS) writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`preview-${page}-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
                    await key('Enter');
                    assert.equal(await evaluate(`document.querySelector('.operational-preview').hidden`),true);
                }
            }
            await navigate('orders_in-progress.html');
            const file = join(temporary,'orders_in-progress.html');
            const original = readFileSync(file,'utf8');
            try {
                await evaluate(`document.querySelector('[data-order-preview-toggle]').click()`);
                writeFileSync(file, original.replace(/data-order-state="[^"]+"/, 'data-order-state="picked_up"'));
                await evaluate(`window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'orders'}}))`);
                for(let attempt=0;attempt<100;attempt++){if(await evaluate(`Boolean(document.querySelector('[data-operational-order].is-updated'))`))break;await new Promise(r=>setTimeout(r,30));}
                assert.equal(await evaluate(`document.querySelector('[data-operational-order]').dataset.orderState`),'picked_up');
                assert.equal(await evaluate(`document.querySelector('.operational-preview').hidden`),false);
                assert.ok(await evaluate(`document.querySelector('[data-operational-order]').classList.contains('is-updated')`));
            } finally {writeFileSync(file,original);}
            for (const count of [0,1,5,20,100]) {
                await navigate(`incoming-${count}.html`);
                assert.equal(await evaluate(`document.querySelectorAll('[data-operational-order]').length`),Math.min(20,count));
                assert.ok(await evaluate(`document.documentElement.scrollWidth <= innerWidth`));
            }
            await navigate('customer-detail.html');
            assert.ok(await evaluate(`document.querySelector('.customer-identity') && document.querySelector('.customer-value-grid')`));
        });
        await scenario('reporting has four adjacent period presets and one custom date range', async () => {
            for (const page of ['customer-detail.html','balance.html']) {
                await navigate(page);
                assert.deepEqual(await evaluate(`Array.from(document.querySelectorAll('.period-options a')).map(node=>node.textContent.trim())`),['Oggi','Settimana','Mese','Anno']);
                assert.equal(await evaluate(`document.querySelector('select[name="year"],select[name="period"],input[name="month"]')`),null);
                assert.ok(await evaluate(`document.querySelector('.period-options a:last-child').href.includes('year=')`));
                assert.equal(await evaluate(`document.querySelector('[name="from"]').disabled`),false);
                assert.equal(await evaluate(`getComputedStyle(document.querySelector('.filter-bar')).padding`),'0px');
                assert.equal(await evaluate(`document.querySelector('[data-custom-dates]').hidden`),true);
                await evaluate(`document.querySelector('[data-custom-period]').click()`);
                assert.equal(await evaluate(`document.querySelector('[data-custom-dates]').hidden`),false);
            }
        });
        await scenario('person detail edits and removes individual incidents in accessible dialogs', async () => {
            await navigate('recipient-incidents_1.html');
            await evaluate(`document.querySelector('.recipient-detail-card .card-heading [data-dialog-open]').focus();document.querySelector('.recipient-detail-card .card-heading [data-dialog-open]').click()`);
            assert.equal(await evaluate(`document.querySelector('dialog[id^="person-correction-"]').open`), true);
            assert.equal(await evaluate(`document.activeElement.name`), 'recipient_name');
            await evaluate(`document.querySelector('dialog[open] [name="correction_reason"]').value='Verifica dati del destinatario';document.querySelector('dialog[open] [name="action"][value="dismiss"]').click()`);
            assert.equal(await evaluate(`document.getElementById('action-confirmation').open`), true);
            await key('Escape'); await key('Escape');
            assert.equal(await evaluate(`document.activeElement.getAttribute('aria-label')`), 'Modifica dati o rimuovi segnalazione');
        });
        await scenario('pickup action group preserves confirmation and cancellation without the removed edit dialog', async () => {
            const orderFile = readdirSync(temporary).filter(file=>/^orders_\d+\.html$/.test(file)).find(file=>readFileSync(join(temporary,file),'utf8').includes('aria-label="Conferma pacco ritirato"'));
            assert.ok(orderFile);
            await navigate(orderFile);
            assert.equal(await evaluate(`document.querySelector('.workflow-primary-actions [type="submit"]').form.querySelector('[name="status"]').value`), 'picked_up');
            assert.equal(await evaluate(`document.querySelector('[data-dialog-open^="transition-note-"]')`), null);
            await evaluate(`document.querySelector('.workflow-secondary-actions [aria-label="Annulla spedizione"]').click()`);
            assert.equal(await evaluate(`document.querySelector('dialog[id$="-cancelled"]').open`), true);
            assert.equal(await evaluate(`document.querySelector('dialog[id$="-cancelled"] [name="cancellation_reason"]').value`), 'other');
            await key('Escape');
        });
        await scenario('filters keep their intended hierarchy, identical heights and mobile apply/cancel behavior', async () => {
            const pages = ['orders_incoming.html','orders_in-progress.html','orders_history.html','balance.html','customers.html','customer-detail.html','reports.html','rates.html','pending.html','recipient-incidents.html','rider-operations.html'];
            for (const width of [1440,1280,1024,768,375]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                for (const page of pages) {
                    await navigate(page);
                    const geometry = await evaluate(`(()=>{const toolbar=document.querySelector('[data-filter-toolbar]');return {controls:[...toolbar.querySelectorAll('.ea-picker-trigger,.form-control:not(.ea-picker-source),.filter-actions .icon-button,.filter-more:not([hidden]),[data-custom-period]')].filter(node=>node.getClientRects().length).map(node=>({height:node.getBoundingClientRect().height,left:node.getBoundingClientRect().left,right:node.getBoundingClientRect().right,class:node.className,filterHeight:getComputedStyle(node).getPropertyValue('--filter-height')})),search:toolbar.querySelector('.filter-search')?.getBoundingClientRect().toJSON(),primary:toolbar.querySelector('.filter-primary').getBoundingClientRect().toJSON(),reset:toolbar.querySelector('.filter-reset').getBoundingClientRect().toJSON()}})()`);
                    assert.ok(geometry.controls.every(control=>control.height>=(width<768?44:38)&&Math.abs(control.height-geometry.controls[0].height)<1),`${page} at ${width}: ${JSON.stringify(geometry.controls)}`);
                    assert.ok(geometry.controls.every(control=>control.right<=width+1),`${page} controls extend at ${width}`);
                    if(geometry.search && width>=576) assert.ok(geometry.search.top<=geometry.primary.top,`${page}: search comes first`);
                    assert.equal(await evaluate(`document.querySelector('.filter-actions').lastElementChild.matches('.filter-reset')`),true);
                    if(width===375 && await evaluate(`!document.querySelector('[data-filter-more]').hidden`)) {
                        await evaluate(`document.querySelector('[data-filter-more]').click()`);
                        assert.equal(await evaluate(`document.querySelector('[data-filter-dialog]').open`),true);
                        assert.ok(await evaluate(`(()=>{const fields=document.querySelector('[data-filter-dialog-fields]');return [...fields.querySelectorAll(':scope > .filter-control,:scope > .filter-field')].every(node=>Math.abs(node.getBoundingClientRect().width-fields.getBoundingClientRect().width)<1)})()`));
                        const picker=await evaluate(`Boolean(document.querySelector('[data-filter-dialog-fields] .ea-picker-trigger'))`);
                        if(picker) {
                            await evaluate(`document.querySelector('[data-filter-dialog-fields] .ea-picker-trigger').click()`);
                            assert.ok(await evaluate(`document.querySelector('.ea-picker-popup')`));
                            await key('Escape');
                            assert.equal(await evaluate(`document.querySelector('[data-filter-dialog]').open`),true);
                        }
                        const oldValue=await evaluate(`document.querySelector('[data-filter-dialog-fields] select')?.value`);
                        const oldName=await evaluate(`document.querySelector('[data-filter-dialog-fields] select')?.name`);
                        if(oldValue!==undefined) {
                            await evaluate(`const select=document.querySelector('[data-filter-dialog-fields] select');select.selectedIndex=1;select.dispatchEvent(new Event('change',{bubbles:true}))`);
                            await key('Escape');
                            assert.equal(await evaluate(`document.querySelector('[data-filter-toolbar] [name="${oldName}"]')?.value`),oldValue);
                        } else await key('Escape');
                        assert.equal(await evaluate(`document.querySelector('[data-filter-dialog]').open`),false);
                        assert.equal(await evaluate(`document.activeElement.matches('[data-filter-more]')`),true);
                    }
                }
            }
            await navigate('orders_in-progress.html');
            await evaluate(`document.querySelector('[data-filter-more]').click();window.filterPayload=null;document.querySelector('[data-filter-toolbar]').addEventListener('submit',event=>{event.preventDefault();window.filterPayload=Object.fromEntries(new FormData(event.target))});const state=document.querySelector('[name="status"]');state.value='out_for_delivery';state.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('[data-filter-dialog] [type="submit"]').click()`);
            assert.equal(await evaluate(`window.filterPayload.status`),'out_for_delivery');
            assert.equal(await evaluate(`document.querySelector('[data-filter-dialog]').open`),false);
        });
        await scenario('active secondary filters show counts and chips remove only their own query and pagination', async () => {
            for(const width of [1440,375]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('filters-active.html?status=out_for_delivery&zone=Zona&shipping_type=regional&urgency=urgent&page=2');
                assert.equal(await evaluate(`document.querySelector('[data-filter-count]').textContent.trim()`), width===375?'· 4':'· 3');
                assert.equal(await evaluate(`document.querySelectorAll('.active-filter-chip').length`),4);
                const url=await evaluate(`document.querySelector('.active-filter-chip[aria-label^="Rimuovi filtro Zona"]').href`);
                const params=new URL(url).searchParams;
                assert.equal(params.get('zone'),null);assert.equal(params.get('page'),null);
                assert.equal(params.get('status'),'out_for_delivery');assert.equal(params.get('shipping_type'),'regional');
            }
            await navigate('customer-custom.html?period=custom&from=2026-01-01&to=2026-01-31&status=delivered&page=2');
            const rangeUrl=new URL(await evaluate(`document.querySelector('.active-filter-chip[aria-label^="Rimuovi filtro Periodo"]').href`));
            for(const name of ['period','from','to','page']) assert.equal(rangeUrl.searchParams.get(name),null);
            assert.equal(rangeUrl.searchParams.get('status'),'delivered');
            await navigate('customer-year.html?period=year&year=2025&status=delivered');
            assert.equal(await evaluate(`new FormData(document.querySelector('[data-filter-toolbar]')).get('year')`),'2025');
            assert.equal(await evaluate(`document.querySelector('.period-options .active').textContent.trim()`),'Anno');
            await evaluate(`document.querySelector('[data-custom-period]').click()`);
            assert.equal(await evaluate(`new FormData(document.querySelector('[data-filter-toolbar]')).get('period')`),'custom');
            assert.equal(await evaluate(`new FormData(document.querySelector('[data-filter-toolbar]')).get('year')`),null);
        });
        const contrast = () => evaluate(`(() => {
            const context = document.createElement('canvas').getContext('2d', {willReadFrequently:true});
            const color = value => { context.clearRect(0,0,1,1); context.fillStyle=value; context.fillRect(0,0,1,1); return Array.from(context.getImageData(0,0,1,1).data).map((n,i)=>i===3?n/255:n); };
            const mix = (front, back) => [0,1,2].map(i=>front[i]*front[3]+back[i]*(1-front[3]));
            const luminance = rgb => rgb.slice(0,3).map(n=>{n/=255;return n<=.04045?n/12.92:((n+.055)/1.055)**2.4}).reduce((sum,n,i)=>sum+n*[.2126,.7152,.0722][i],0);
            const result={checked:0,failed:[]};
            for(const node of document.querySelectorAll('body *')) {
                if(node.matches('.visually-hidden,.sr-only')||node.getBoundingClientRect().bottom<0||['SCRIPT','STYLE','I','SVG','PATH','OPTION'].includes(node.tagName)||!node.getClientRects().length||!Array.from(node.childNodes).some(n=>n.nodeType===3&&n.textContent.trim()))continue;
                const style=getComputedStyle(node); if(style.visibility==='hidden'||Number(style.opacity)<1)continue;
                let layers=[],gradient=false,ancestor=node;
                while(ancestor) {const css=getComputedStyle(ancestor);if(ancestor!==document.body&&css.backgroundImage!=='none')gradient=true;layers.push(color(css.backgroundColor));ancestor=ancestor.parentElement;}
                if(gradient)continue;
                let background=[8,14,25];for(const layer of layers.reverse())background=mix(layer,background);
                const foreground=mix(color(style.color),background),a=luminance(foreground),b=luminance(background),ratio=(Math.max(a,b)+.05)/(Math.min(a,b)+.05);
                const minimum=parseFloat(style.fontSize)>=24||(parseFloat(style.fontSize)>=18.66&&Number(style.fontWeight)>=700)?3:4.5;
                result.checked++;if(ratio<minimum)result.failed.push({text:node.textContent.trim().slice(0,65),class:node.className,ratio:Math.round(ratio*100)/100,color:style.color,background:style.backgroundColor});
            }
            return result;
        })()`);
        const assertButtonShapes = async context => {
            const invalid = await evaluate(`(() => {
                let icons='.icon-button:not(.action-with-text),.page-arrow,.password-toggle,.btn-close,.calendar-heading > button,.portal-mobile-nav .mobile-create';
                if(innerWidth<768)icons+=',.sound-toggle,.account-button';
                if(innerWidth<=991)icons+=',.topbar .account';
                const result=[];
                for(const node of document.querySelectorAll('button,.btn,.button,.icon-button,.page-arrow,.page-number,.back-link,.password-toggle,.portal-mobile-nav .mobile-create,.topbar .account,.period-options a,.workspace-tabs a,.section-jump a')) {
                    const rect=node.getBoundingClientRect(),style=getComputedStyle(node);
                    if(!rect.width||!rect.height||style.visibility==='hidden'||node.matches('.nav-backdrop'))continue;
                    const icon=node.matches(icons);
                    if(icon&&node.matches('.account-button,.account')&&getComputedStyle(node.querySelector('.avatar')).borderRadius!=='50%')result.push({label:'Account avatar must follow the circular button'});
                    if(icon ? Math.abs(rect.width-rect.height)>1 || style.borderRadius!=='50%' : !node.matches('.sound-toggle,.account-button') && style.borderRadius!=='9999px')result.push({label:node.getAttribute('aria-label')||node.textContent.trim().slice(0,40),class:node.className,width:rect.width,height:rect.height,radius:style.borderRadius});
                }
                return result;
            })()`);
            assert.deepEqual(invalid,[],context);
        };
        await scenario('archive and order detail preserve hierarchy, spacing, slug links and readable secondary information', async () => {
            const detail = readdirSync(temporary).filter(file=>/^orders_\d+\.html$/.test(file)).find(file=>readFileSync(join(temporary,file),'utf8').includes('id="next-action"'));
            assert.ok(detail);
            for (const width of [375,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('orders_history.html');
                assert.ok(await evaluate(`document.querySelector('.archive-day .history-order') !== null`));
                assert.ok(await evaluate(`document.documentElement.scrollWidth <= innerWidth + 1`));
                assert.ok(await evaluate(`[...document.querySelectorAll('.history-order [href*="/orders/"]')].every(link=>/^[0-9a-f-]{36}$/.test(link.pathname.split('/').at(-1)))`));
                if(process.env.EA_ORDER_SCREENSHOTS)writeFileSync(join(process.env.EA_ORDER_SCREENSHOTS,`archive-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
                await navigate(detail);
                const geometry = await evaluate(`(()=>{const workflow=document.querySelector('.order-workflow').getBoundingClientRect(),layout=document.querySelector('.order-detail-layout').getBoundingClientRect();const sections=[...document.querySelectorAll('.order-data-stack > section,.order-support > section,.order-support > div')].filter(node=>node.getClientRects().length).map(node=>({name:node.className,...node.getBoundingClientRect().toJSON()}));const overlaps=[];for(let i=0;i<sections.length;i++)for(let j=i+1;j<sections.length;j++){const a=sections[i],b=sections[j];if(a.left<b.right-1&&a.right>b.left+1&&a.top<b.bottom-1&&a.bottom>b.top+1)overlaps.push([a.name,b.name]);}return {workflow:workflow.bottom,layout:layout.top,overlaps,overflow:document.documentElement.scrollWidth>innerWidth+1};})()`);
                assert.ok(geometry.layout >= geometry.workflow + 12,JSON.stringify({width,geometry}));
                assert.deepEqual(geometry.overlaps,[],JSON.stringify({width,geometry})); assert.equal(geometry.overflow,false);
                assert.equal(await evaluate(`document.querySelector('#next-action a[href*="/messages/"]')`),null);
                assert.ok(await evaluate(`document.querySelector('.order-detail-heading a[href*="/messages/"]') !== null`));
                assert.equal(await evaluate(`document.querySelector('[data-dialog-open^="pickup-schedule-"]')`),null);
                await assertButtonShapes(`order detail ${width}`);
                if(process.env.EA_ORDER_SCREENSHOTS)writeFileSync(join(process.env.EA_ORDER_SCREENSHOTS,`order-detail-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
            }
        });
        await scenario('rounded directories select real Rider and recipient details with keyboard, search and responsive geometry', async () => {
            for(const width of [320,375,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                for(const page of ['rounded-riders','rounded-recipients']) {
                    await navigate(page+'.html');
                    assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`${page} overflow ${width}`);
                    assert.ok(await evaluate(`parseFloat(getComputedStyle(document.querySelector('.directory-detail')).borderRadius)>=24`));
                    assert.ok(await evaluate(`document.querySelector('.directory-list').scrollHeight>=document.querySelector('.directory-list').clientHeight`));
                    await evaluate(`document.querySelector('[data-directory-select]').focus()`);await key('ArrowDown');
                    const active=await evaluate(`document.querySelector('[data-directory-select][aria-current]').dataset.directorySelect`);
                    assert.match(active,/^[0-9a-f-]{36}$/);
                    assert.equal(await evaluate(`document.querySelector('[data-directory-panel]:not([hidden])').dataset.directoryPanel`),active);
                    await key('Enter');
                    assert.equal(await evaluate(`document.querySelectorAll('[data-directory-panel]:not([hidden])').length`),1);
                    await evaluate(`const input=document.querySelector('[data-directory-search]');input.value='Nessuna corrispondenza 928';input.dispatchEvent(new Event('input'))`);
                    assert.equal(await evaluate(`document.querySelector('[data-directory-empty]').hidden`),false);
                    await evaluate(`const input=document.querySelector('[data-directory-search]');input.value='';input.dispatchEvent(new Event('input'))`);
                    assert.equal(await evaluate(`document.querySelector('[data-directory-empty]').hidden`),true);
                    assert.equal(await evaluate(`document.querySelectorAll('[data-directory-panel]:not([hidden])').length`),1);
                    for(const theme of ['light','dark']) {
                        await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);await new Promise(resolve=>setTimeout(resolve,200));
                        assert.deepEqual((await contrast()).failed,[],`${page} contrast ${theme} ${width}`);
                    }
                    await evaluate(`document.documentElement.dataset.eaTheme='light';document.activeElement.blur();window.scrollTo({top:0,behavior:'instant'})`);await new Promise(resolve=>setTimeout(resolve,200));
                    if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`${page}-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
                }
            }
        });
        await scenario('inbox preserves drafts on realtime refresh, distinguishes unread threads and retries without duplicate messages', async () => {
            for(const width of [320,375,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('rounded-inbox.html');
                for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-live-chat] .message-bubble[data-message-id]')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`inbox overflow ${width}`);
                assert.equal(await evaluate(`document.querySelectorAll('[data-conversation-link][aria-current]').length`),1);
                assert.equal(await evaluate(`new URL(document.querySelector('[data-live-chat]').dataset.messagesUrl,location.origin).pathname`),'/messages/'+await evaluate(`document.querySelector('[data-live-chat]').dataset.liveChat`));
                await evaluate(`document.querySelector('[data-inbox-filter="unread"]').click()`);
                assert.ok(await evaluate(`[...document.querySelectorAll('[data-conversation-link]:not([hidden])')].every(link=>Number(link.dataset.inboxUnread)>0)`));
                await evaluate(`const input=document.querySelector('[data-inbox-search]');input.value='Officina';input.dispatchEvent(new Event('input'))`);
                assert.equal(await evaluate(`document.querySelectorAll('[data-conversation-link]:not([hidden])').length`),1);
                await evaluate(`document.querySelector('#body').value='Bozza da conservare';document.querySelector('#body').focus();window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'messages'}}))`);
                await new Promise(resolve=>setTimeout(resolve,100));
                assert.equal(await evaluate(`document.querySelector('#body').value`),'Bozza da conservare');
                for(const theme of ['light','dark']) {
                    await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);await new Promise(resolve=>setTimeout(resolve,200));
                    assert.deepEqual((await contrast()).failed,[],`inbox contrast ${theme} ${width}`);
                }
                await evaluate(`document.documentElement.dataset.eaTheme='light';document.querySelector('#body').blur();document.querySelector('[data-inbox-search]').value='';document.querySelector('[data-inbox-search]').dispatchEvent(new Event('input'));document.querySelector('[data-inbox-filter="all"]').click();window.scrollTo({top:0,behavior:'instant'})`);await new Promise(resolve=>setTimeout(resolve,200));
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`rounded-inbox-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
            }
            chatRejectNext=true;
            const before=chatWrites;
            await evaluate(`document.querySelector('#body').value='Conferma consegna ore 15';document.querySelector('.message-composer').requestSubmit()`);
            for(let i=0;i<100;i++){if(chatWrites===before+1&&await evaluate(`!document.querySelector('.message-composer button').disabled`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(await evaluate(`document.querySelector('#body').value`),'Conferma consegna ore 15');
            await evaluate(`const form=document.querySelector('.message-composer');form.requestSubmit();form.requestSubmit()`);
            for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('.conversation-list').textContent.includes('Conferma consegna ore 15')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(chatWrites,before+2);
            assert.equal(await evaluate(`document.querySelector('#body').value`),'');
            await evaluate(`document.querySelector('#body').value='Messaggio in partenza';document.querySelector('.message-composer').requestSubmit();document.querySelector('#body').value='Bozza successiva'`);
            for(let i=0;i<100;i++){if(await evaluate(`!document.querySelector('.message-composer button').disabled&&document.querySelector('.conversation-list').textContent.includes('Messaggio in partenza')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(chatWrites,before+3);
            assert.equal(await evaluate(`document.querySelector('#body').value`),'Bozza successiva');
            await evaluate(`document.querySelector('[data-chat-refresh]').click()`);
            for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-conversation-list]').getAttribute('aria-busy')!=='true'`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(await evaluate(`document.querySelector('#body').value`),'Bozza successiva');
            assert.ok(chatReceipts.some(receipt=>receipt.state==='delivered'&&receipt.ids.every(id=>/^[0-9a-f-]{36}$/.test(id))));
        });
        await scenario('customers pagination and recipient typography preserve compact navigation and contextual confirmations', async () => {
            const waitFor = async expression => {
                for(let attempt=0;attempt<100;attempt++) {
                    if(await evaluate(expression)) return;
                    await new Promise(resolve=>setTimeout(resolve,30));
                }
                assert.fail(`Page state did not settle: ${expression}`);
            };
            for(const width of [320,390,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('customers');
                assert.equal(await evaluate(`document.querySelectorAll('.customer-directory .customer-entry').length`),25);
                assert.ok(await evaluate(`document.querySelector('.workspace-pagination').textContent.includes('Pagina 1 di 3')`));
                assert.equal(await evaluate(`new URL(document.querySelector('.workspace-pagination [rel="next"]').href).searchParams.get('page')`),'2');
                await evaluate(`document.querySelector('.workspace-pagination [rel="next"]').click()`);
                await waitFor(`new URLSearchParams(location.search).get('page')==='2'&&document.querySelector('.workspace-pagination')?.textContent.includes('Pagina 2 di 3')`);
                assert.equal(await evaluate(`document.querySelectorAll('.customer-directory .customer-entry').length`),25);
                assert.equal(await evaluate(`document.querySelector('.customer-entry h2').textContent.trim()`),'Cliente 026');
                await evaluate(`document.querySelector('.workspace-pagination [rel="next"]').click()`);
                await waitFor(`new URLSearchParams(location.search).get('page')==='3'&&document.querySelector('.workspace-pagination')?.textContent.includes('Pagina 3 di 3')`);
                assert.equal(await evaluate(`document.querySelectorAll('.customer-directory .customer-entry').length`),1);
                assert.equal(await evaluate(`document.querySelector('.customer-entry h2').textContent.trim()`),'Cliente 051');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`customer pagination overflow @${width}`);

                await navigate('recipient-detail-typography.html');
                await evaluate(`document.fonts.ready.then(()=>true)`);
                for(const theme of ['light','dark']) {
                    await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);
                    await new Promise(resolve=>setTimeout(resolve,200));
                    const typography = await evaluate(`(()=>{
                        const families=new Set(),walker=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);let node;
                        while(node=walker.nextNode()) {const parent=node.parentElement;if(!node.textContent.trim()||!parent.getClientRects().length||parent.closest('script,style,.bi'))continue;families.add(getComputedStyle(parent).fontFamily.split(',')[0].replaceAll('"','').replaceAll("'",'').trim());}
                        const fields=[...document.querySelectorAll('.recipient-detail-page .operation-facts>div')].map(field=>{const label=field.querySelector('dt'),value=field.querySelector('dd'),labelStyle=getComputedStyle(label),valueStyle=getComputedStyle(value),bounds=value.getBoundingClientRect(),card=value.closest('.recipient-detail-card').getBoundingClientRect();return {label:labelStyle.fontFamily,value:valueStyle.fontFamily,labelSize:parseFloat(labelStyle.fontSize),valueSize:parseFloat(valueStyle.fontSize),labelWeight:Number(labelStyle.fontWeight),valueWeight:Number(valueStyle.fontWeight),contained:bounds.left>=card.left+8&&bounds.right<=card.right-8&&value.scrollWidth<=value.clientWidth+1};});
                        return {families:[...families],fields,overflow:document.documentElement.scrollWidth-innerWidth};
                    })()`);
                    assert.ok(typography.families.length<=2,`recipient text font limit ${theme} @${width}: ${JSON.stringify(typography.families)}`);
                    assert.ok(typography.fields.every(field=>field.label!==field.value&&field.valueWeight>field.labelWeight&&field.valueSize-field.labelSize<=4&&field.contained),`recipient field hierarchy ${theme} @${width}: ${JSON.stringify(typography.fields)}`);
                    assert.ok(typography.overflow<=1,`recipient long values overflow ${theme} @${width}`);
                    assert.deepEqual((await contrast()).failed,[],`recipient typography contrast ${theme} @${width}`);
                    if(process.env.EA_UI_SCREENSHOTS && [320,1440].includes(width))writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`recipient-typography-${theme}-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
                }
                await evaluate(`document.querySelector('[data-dialog-open^="person-correction-"]').click()`);
                await evaluate(`Promise.all(document.querySelector('dialog[id^="person-correction-"]').getAnimations().map(animation=>animation.finished))`);
                const actions = await evaluate(`(()=>{const dialog=document.querySelector('dialog[id^="person-correction-"]'),footer=dialog.querySelector('.modal-actions'),save=footer.querySelector('[value="correct"]'),remove=footer.querySelector('[value="dismiss"]'),probe=document.createElement('span');document.body.append(probe);probe.style.color='var(--ea-success)';const green=getComputedStyle(probe).color;probe.style.color='var(--ea-danger)';const red=getComputedStyle(probe).color;probe.remove();return {buttons:footer.querySelectorAll('button').length,back:footer.querySelectorAll('[data-dialog-close]').length,save:getComputedStyle(save).backgroundColor,remove:getComputedStyle(remove).backgroundColor,green,red,confirmation:remove.dataset.confirm};})()`);
                assert.equal(actions.buttons,2);assert.equal(actions.back,0);assert.equal(actions.save,actions.green);assert.equal(actions.remove,actions.red);assert.equal(actions.confirmation,'Rimuovi segnalazione');
                await evaluate(`window.__recipientSubmissions=0;const dialog=document.querySelector('dialog[id^="person-correction-"]'),form=dialog.querySelector('form');form.querySelector('[name="correction_reason"]').value='Verifica esplicita di rimozione';form.addEventListener('submit',event=>{event.preventDefault();event.stopImmediatePropagation();window.__recipientSubmissions++;});form.querySelector('[value="dismiss"]').click()`);
                await waitFor(`document.querySelector('#action-confirmation').open`);
                assert.equal(await evaluate(`window.__recipientSubmissions`),0);
                assert.ok(await evaluate(`!document.querySelector('#action-confirmation .modal-back').getClientRects().length`));
                assert.ok(await evaluate(`document.querySelector('#action-confirmation .modal-heading [data-dialog-close]').getClientRects().length>0`));
                await evaluate(`document.querySelector('#action-confirmation [data-confirm-action]').click()`);
                await waitFor(`window.__recipientSubmissions===1&&!document.querySelector('#action-confirmation').open`);
                await evaluate(`document.querySelector('dialog[id^="person-correction-"] .modal-heading [data-dialog-close]').click()`);
            }
        });
        await scenario('forty consecutive receipts update only their regions and preserve filters page scroll and duplicate protection', async () => {
            const metric = label => evaluate(`(()=>{const metric=${JSON.stringify(label)}==='Contanti'?document.querySelector('.liquidity-total > strong'):document.querySelector('.finance-economic .statement-lines > div:first-child dd');return Math.round(Number(metric.textContent.replace(/[^0-9,-]/g,'').replace(',','.'))*100);})()`);
            try {
            for (const width of [375,1440]) {
                batchMode=true;batchStep=0;batchWrites=0;
                await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
                await navigate(batch.initial_url.slice(1));
                await evaluate(`window.__batchDocument='unchanged';window.__batchFilter=document.querySelector('[data-filter-toolbar]');`);
                for (let step=0;step<40;step++) {
                    const expected=batch.steps[step];
                    const anchor = await evaluate(`(()=>{const rows=[...document.querySelectorAll('#delivery-details [data-receipt]')];rows[0]?.scrollIntoView({block:'start',behavior:'instant'});const row=rows[1];return row?{slug:row.dataset.receipt,top:row.getBoundingClientRect().top}:null;})()`);
                    await evaluate(`document.querySelector('[data-dialog-open="receipt-${expected.order_slug}"]').click()`);
                    await evaluate(`const form=document.getElementById('receipt-${expected.order_slug}').querySelector('form');form.requestSubmit();form.requestSubmit();`);
                    for(let attempt=0;attempt<200;attempt++) {
                        if(batchStep===step+1 && await evaluate(`!document.querySelector('#delivery-details [data-receipt="${expected.order_slug}"]')`)) break;
                        await new Promise(resolve=>setTimeout(resolve,20));
                    }
                    assert.equal(batchWrites,step+1,`one write per receipt ${step} @${width}`);
                    assert.equal(await evaluate(`window.__batchDocument`),'unchanged');
                    assert.ok(await evaluate(`window.__batchFilter===document.querySelector('[data-filter-toolbar]')`),'filter controls retained');
                    assert.equal(await metric('Totale lordo'),(step+1)*100);
                    assert.equal(await metric('Contanti'),3000+(step+1)*100);
                    assert.equal(await evaluate(`Number(document.querySelector('#delivery-details .count-badge').textContent)`),42-step);
                    assert.equal(await evaluate(`new URLSearchParams(location.search).get('orders_page')`),String(batch.steps[step+1].page));
                    assert.equal(await evaluate(`new URLSearchParams(location.search).get('q')`),'Batch test');
                    assert.equal(await evaluate(`new URLSearchParams(location.search).get('from')`),'2026-11-01');
                    assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
                    if(anchor && await evaluate(`!!document.querySelector('#delivery-details [data-receipt="${anchor.slug}"]')`)) {
                        const top=await evaluate(`document.querySelector('#delivery-details [data-receipt="${anchor.slug}"]').getBoundingClientRect().top`);
                        assert.ok(Math.abs(top-anchor.top)<3,`visible receipt anchor retained @${width} step${step}: ${anchor.top} -> ${top}`);
                    }
                }
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`batch-receipts-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
            }
            batchStep=0;batchWrites=0;
            await navigate(batch.initial_url.slice(1));
            const order=batch.steps[0];failBatchRefresh=true;
            await evaluate(`document.querySelector('[data-dialog-open="receipt-${order.order_slug}"]').click();document.getElementById('receipt-${order.order_slug}').querySelector('form').requestSubmit()`);
            for(let attempt=0;attempt<100;attempt++){if(await evaluate(`!!document.querySelector('[data-workspace-feedback] button')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(batchWrites,1);
            await evaluate(`document.querySelector('[data-workspace-feedback] button').click()`);
            for(let attempt=0;attempt<100;attempt++){if(await metric('Totale lordo')===100)break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(await metric('Totale lordo'),100);
            assert.equal(batchWrites,1,'refresh retry never repeats a receipt');
            batchMode=false;
            await navigate('settings');
            await evaluate(`window.__settingsDocument=true;const form=document.querySelector('form[action$="/settings"]');form.querySelector('input[name="notify_orders"][type="checkbox"]').checked=false;form.requestSubmit();form.requestSubmit()`);
            for(let attempt=0;attempt<100;attempt++){if(settingsSaved&&await evaluate(`document.querySelector('[data-workspace-feedback]')?.textContent.includes('Preferenze salvate')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(settingsWrites,1);
            assert.equal(await evaluate(`window.__settingsDocument`),true);
            assert.equal(await evaluate(`document.querySelector('input[name="notify_orders"][type="checkbox"]').checked`),false);
            } finally { batchMode=false; }
        });
        await scenario('staff modal spacing keeps reversal fields and every shared dialog comfortably inside its borders', async () => {
            const pages = ['finance-payments.html', 'finance-reversals-flow.html', 'finance-expenses.html', 'workspace-pending.html', 'workspace-rates.html', 'rounded-riders.html', 'rounded-recipients.html', 'rounded-thread.html', 'customer-detail.html', 'ajax-order.html'];
            const settleDialog = () => evaluate(`Promise.all(document.querySelector('dialog[open]').getAnimations().map(animation=>animation.finished))`);
            const inspectDialog = async context => {
                const layout = await evaluate(`(()=>{
                    const dialog=document.querySelector('dialog[open]'),rect=dialog.getBoundingClientRect(),style=getComputedStyle(dialog),heading=dialog.querySelector('.modal-heading');
                    const elements=[...dialog.querySelectorAll('.form-label,input:not([type="hidden"]),textarea,.ea-picker-trigger,p,.modal-actions>button,button[type="submit"]')].filter(node=>node.getClientRects().length&&!node.closest('.modal-heading'));
                    return {width:rect.width,left:rect.left,right:rect.right,viewport:innerWidth,overflow:dialog.scrollWidth-dialog.clientWidth,border:parseFloat(style.borderLeftWidth),headingBorder:parseFloat(getComputedStyle(heading).borderBottomWidth),topGap:elements.length?Math.min(...elements.map(node=>node.getBoundingClientRect().top))-heading.getBoundingClientRect().bottom:null,elements:elements.map(node=>{const bounds=node.getBoundingClientRect();return {tag:node.tagName,name:node.name||node.textContent.trim().slice(0,35),left:bounds.left-rect.left,right:rect.right-bounds.right};})};
                })()`);
                assert.ok(layout.left>=7 && layout.right<=layout.viewport-7 && layout.overflow<=1, `${context}: dialog bounds ${JSON.stringify(layout)}`);
                assert.ok(layout.border>=1 && layout.headingBorder>=1, `${context}: dialog and heading borders`);
                if(layout.topGap!==null) assert.ok(layout.topGap>=16,`${context}: content below heading ${layout.topGap}`);
                assert.ok(layout.elements.every(node=>node.left>=16 && node.right>=16), `${context}: content inset ${JSON.stringify(layout.elements)}`);
            };
            for (const width of [320,390,768,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
                await navigate('finance-payments.html');
                const reversal = await evaluate(`(()=>{document.querySelector('.row-actions-menu summary').click();const trigger=document.querySelector('[data-dialog-open^="receipt-state-"]');trigger.click();return trigger.dataset.dialogOpen;})()`);
                assert.equal(await evaluate(`document.getElementById(${JSON.stringify(reversal)}).open`),true);
                await settleDialog();
                const alignment = await evaluate(`(()=>{const dialog=document.querySelector('dialog[open]'),input=dialog.querySelector('[name="note"]').getBoundingClientRect(),label=dialog.querySelector('label').getBoundingClientRect();return {input:input.left,label:label.left,gap:input.top-label.bottom};})()`);
                assert.ok(Math.abs(alignment.input-alignment.label)<=2 && alignment.gap>=4, `reversal alignment @${width}: ${JSON.stringify(alignment)}`);
                for (const theme of ['light','dark']) {
                    await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);
                    await new Promise(resolve=>setTimeout(resolve,200));
                    await inspectDialog(`reversal ${theme} @${width}`);
                    assert.deepEqual((await contrast()).failed,[],`reversal modal contrast ${theme} @${width}`);
                    if(process.env.EA_UI_SCREENSHOTS && [320,1440].includes(width))writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`modal-reversal-${theme}-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
                }
                await key('Escape');
                for (const page of pages) {
                    await navigate(page);
                    const ids = await evaluate(`(()=>{const seen=new Set();return [...document.querySelectorAll('dialog.ea-modal')].filter(node=>{const kind=node.id.replace(/[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}/g,'slug');if(seen.has(kind))return false;seen.add(kind);return true;}).map(node=>node.id);})()`);
                    assert.ok(ids.length>0,`${page}: shared dialogs available`);
                    for (const id of ids) {
                        if (id === 'action-confirmation' && page === 'finance-expenses.html') {
                            await evaluate(`document.querySelector('form[data-confirm="Annulla spesa"] button[type="submit"]').click()`);
                        } else {
                            await evaluate(`document.getElementById(${JSON.stringify(id)}).showModal()`);
                        }
                        await settleDialog();
                        for (const theme of ['light','dark']) {
                            await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);
                            await inspectDialog(`${page} ${id} ${theme} @${width}`);
                            if(process.env.EA_UI_SCREENSHOTS && id==='action-confirmation' && page==='finance-expenses.html' && [320,1440].includes(width))writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`modal-confirmation-${theme}-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
                        }
                        if (id === 'balance-add') {
                            for (const step of ['expense','cash']) {
                                await evaluate(`document.querySelector('[data-finance-go="${step}"]').click()`);
                                await inspectDialog(`${page} operation ${step} @${width}`);
                                if(process.env.EA_UI_SCREENSHOTS && step==='expense' && page==='finance-payments.html' && [320,1440].includes(width))writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`modal-expense-dark-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
                                await evaluate(`document.querySelector('[data-finance-step="${step}"] [data-finance-go="choose"]').click()`);
                            }
                        }
                        await key('Escape');
                    }
                }
            }
            await send('Emulation.setDeviceMetricsOverride',{width:390,height:480,deviceScaleFactor:1,mobile:true});
            await navigate('workspace-pending.html');
            await evaluate(`document.getElementById('pending-create').showModal()`);
            await settleDialog();
            await inspectDialog('long pending form in short viewport');
            const scrolling = await evaluate(`(()=>{const dialog=document.querySelector('dialog[open]');dialog.scrollTop=dialog.scrollHeight;const rect=dialog.getBoundingClientRect(),button=dialog.querySelector('button[type="submit"]').getBoundingClientRect();return {scroll:dialog.scrollTop,top:rect.top,bottom:rect.bottom,height:innerHeight,buttonBottom:button.bottom};})()`);
            assert.ok(scrolling.scroll>0 && scrolling.top>=7 && scrolling.bottom<=scrolling.height-7 && scrolling.buttonBottom<=scrolling.bottom-12,`short viewport scrolling: ${JSON.stringify(scrolling)}`);
            await key('Escape');
        });
        await scenario('reversal accounting history distinguishes unpaid corrections refunds and compensating restores responsively', async () => {
            for (const width of [320,375,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('finance-reversals-flow.html');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`reversal page overflow ${width}`);
                assert.equal(await evaluate(`document.querySelectorAll('[data-reversal]').length`),2);
                assert.ok(await evaluate(`document.body.textContent.includes('Senza effetto sul netto')&&document.body.textContent.includes('Con rettifica contabile')&&!document.body.textContent.includes('Ripristinato il')`));
                assert.ok(await evaluate(`!!document.querySelector('[data-balance="final"]') && !!document.querySelector('[data-balance="pending"]')`));
                await assertButtonShapes(`reversal controls ${width}`);
                assert.equal(await evaluate(`document.querySelector('[data-dialog-open^="receipt-refund-"]')`),null,'the fixture records a separate completed refund');
                await evaluate(`const row=[...document.querySelectorAll('[data-reversal]')].find(node=>node.textContent.includes('Con rettifica contabile')&&node.querySelector('[data-dialog-open^="receipt-state-"]'));row.querySelector('.row-actions-menu summary').click();row.querySelector('[data-dialog-open^="receipt-state-"]').click()`);
                assert.ok(await evaluate(`document.querySelector('dialog[open]').textContent.includes('nuova registrazione del pagamento')`));
                assert.equal(await evaluate(`document.querySelector('dialog[open] [name="action"]').value`),'restore');
                assert.ok(await evaluate(`document.querySelector('dialog[open] [name="note"]').required`));
                await key('Escape');
                for(const theme of ['light','dark']) {
                    await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);await new Promise(resolve=>setTimeout(resolve,200));
                    assert.deepEqual((await contrast()).failed,[],`reversal contrast ${theme} ${width}`);
                }
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`reversals-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
                await navigate('finance-reversals-history.html');
                assert.ok(await evaluate(`document.querySelector('[data-money-register="history"]').textContent.includes('Ripristino contabile')`));
                assert.equal(await evaluate(`document.querySelectorAll('[data-reversal]').length`),0);
            }
        });
        await scenario('financial statement categories and verified reconciliation keep money and deliveries distinct on mobile and desktop', async () => {
            for(const width of [320,390,768,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                for(const page of ['finance-statement-customers.html','finance-manual-categories.html','finance-payment-categories.html','finance-reconciliation.html']) {
                    await navigate(page);
                    assert.deepEqual(await evaluate(`[...document.querySelectorAll('.finance-overview h3')].map(node=>node.textContent)`),['Saldo dei movimenti registrati','Saldo finale','Ancora da regolare']);
                    assert.ok(await evaluate(`document.querySelector('[data-balance="final"]') && document.querySelector('[data-balance="pending"]')?.textContent.includes('Saldo sospeso')`));
                    assert.ok(await evaluate(`!!document.querySelector('.finance-overview .count-pill')`));
                    if(page==='finance-statement-customers.html') {
                        assert.equal(await evaluate(`document.querySelector('.finance-overview .count-pill').textContent`),'2 consegne');
                        assert.ok(await evaluate(`document.querySelector('.liquidity-flows').textContent.includes('Da riconciliare')`));
                    }
                    if(page==='finance-manual-categories.html') {
                        const sections=await evaluate(`[...document.querySelectorAll('[data-money-register]')].map(node=>node.dataset.moneyRegister)`);
                        assert.deepEqual(sections,['cash']);
                        assert.equal(new Set(sections).size,sections.length);
                    }
                    if(page==='finance-payment-categories.html') {
                        assert.ok(await evaluate(`document.querySelectorAll('[data-money-register="payments"] .money-row').length===1 && !document.querySelector('[data-payment-category]')`));
                    }
                    if(page==='finance-reconciliation.html') {
                        await evaluate(`document.querySelector('[data-dialog-open^="receipt-reconcile-"]').click()`);
                        await evaluate(`Promise.all(document.querySelector('dialog[open]').getAnimations().map(animation=>animation.finished))`);
                        assert.equal(await evaluate(`document.querySelector('dialog[open] [name="resolution"]').value`),'refunded');
                        assert.ok(await evaluate(`document.querySelector('dialog[open] [name="confirmed"]').required`));
                        assert.equal(await evaluate(`document.querySelector('dialog[open] form').checkValidity()`),false);
                        await key('Escape');
                    }
                    for(const theme of ['light','dark']) {
                        await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);
                        assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`${page} ${theme} @${width}`);
                    }
                    if(process.env.EA_UI_SCREENSHOTS && [390,1440].includes(width))writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`${page}-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
                }
            }
        });
        await scenario('finance compact pagination keeps filters and browser history without reloading, handles errors and aligns category headings', async () => {
            const initial = JSON.parse(readFileSync(join(temporary, 'finance-pagination.json'))).initial;
            const waitFor = async expression => {
                for (let attempt = 0; attempt < 150; attempt++) {
                    if (await evaluate(expression)) return;
                    await new Promise(resolve => setTimeout(resolve, 20));
                }
                throw new Error('Finance pagination did not reach '+expression);
            };
            for (const width of [320,375,390,430,768,1440]) {
                await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 600 });
                await navigate(initial);
                const filters = await evaluate(`(()=>{const url=new URL(location.href);return ['ledger','period','q','customer_ids[0]'].map(name=>url.searchParams.get(name));})()`);
                assert.ok(await evaluate(`document.querySelector('.finance-ledger [aria-label="Pagina precedente"]').disabled`));
                assert.equal(await evaluate(`document.querySelector('.finance-ledger [aria-live]').textContent.trim()`), '1');
                assert.equal(await evaluate(`document.querySelector('.finance-ledger .pagination-pages')`), null);
                assert.ok(await evaluate(`!!document.querySelector('.finance-ledger h2')`));
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
                await evaluate(`window.__financePaginationDocument='preserved';document.querySelector('.finance-ledger [rel="next"]').focus()`);
                await key('Enter');
                await waitFor(`document.querySelector('.finance-ledger [data-pagination-page]').dataset.paginationPage==='2' && !document.querySelector('.finance-ledger[aria-busy]')`);
                assert.equal(await evaluate(`window.__financePaginationDocument`), 'preserved');
                assert.equal(await evaluate(`document.querySelector('.finance-ledger [aria-live]').textContent.trim()`), '2');
                assert.equal(await evaluate(`document.querySelectorAll('.finance-ledger .money-row').length`), 3);
                assert.ok(await evaluate(`document.querySelector('.finance-ledger [aria-label="Pagina successiva"]').disabled`));
                assert.deepEqual(await evaluate(`(()=>{const url=new URL(location.href);return ['ledger','period','q','customer_ids[0]'].map(name=>url.searchParams.get(name));})()`), filters);
                await evaluate(`history.back()`);
                await waitFor(`new URL(location.href).searchParams.get('movements_page')==='1' && document.querySelector('.finance-ledger [data-pagination-page]').dataset.paginationPage==='1' && !document.querySelector('.finance-ledger[aria-busy]')`);
                assert.equal(await evaluate(`window.__financePaginationDocument`), 'preserved');
                await evaluate(`history.forward()`);
                await waitFor(`new URL(location.href).searchParams.get('movements_page')==='2' && document.querySelector('.finance-ledger [data-pagination-page]').dataset.paginationPage==='2' && !document.querySelector('.finance-ledger[aria-busy]')`);
                assert.equal(await evaluate(`window.__financePaginationDocument`), 'preserved');
                await evaluate(`document.querySelector('.finance-ledger [rel="prev"]').click()`);
                await waitFor(`document.querySelector('.finance-ledger [data-pagination-page]').dataset.paginationPage==='1' && !document.querySelector('.finance-ledger[aria-busy]')`);
            }
            financePageFailNext = true;
            await evaluate(`document.querySelector('.finance-ledger [rel="next"]').click()`);
            await waitFor(`!!document.querySelector('[data-workspace-feedback].alert-warning') && !document.querySelector('.finance-ledger[aria-busy]')`);
            assert.equal(await evaluate(`document.querySelector('.finance-ledger [data-pagination-page]').dataset.paginationPage`), '1');
            assert.equal(await evaluate(`new URL(location.href).searchParams.get('movements_page')`), '1');
            await evaluate(`document.querySelector('[data-workspace-feedback] button').click()`);
            await waitFor(`document.querySelector('.finance-ledger [data-pagination-page]').dataset.paginationPage==='2' && !document.querySelector('.finance-ledger[aria-busy]')`);
            assert.equal(await evaluate(`window.__financePaginationDocument`), 'preserved');
        });
        await scenario('staff 404 centers on every requested width, counts down, cleans timers and redirects to its own home', async () => {
            for (const width of [320,375,390,430,768,1440]) {
                await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 600 });
                await navigate('not-found.html');
                assert.equal(await evaluate(`document.querySelector('h1').textContent`), 'Error Not Found 404');
                assert.equal(await evaluate(`document.querySelector('[data-not-found-countdown]').textContent`), '5');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
                assert.ok(await evaluate(`(()=>{const r=document.querySelector('[data-not-found]').getBoundingClientRect();return Math.abs((r.left+r.right)/2-innerWidth/2)<2&&Math.abs((r.top+r.bottom)/2-innerHeight/2)<2;})()`), `404 centered at ${width}`);
            }
            await new Promise(resolve => setTimeout(resolve, 1150));
            assert.equal(await evaluate(`document.querySelector('[data-not-found-countdown]').textContent`), '4');
            await evaluate(`window.dispatchEvent(new PageTransitionEvent('pagehide'))`);
            await new Promise(resolve => setTimeout(resolve, 1150));
            assert.equal(await evaluate(`document.querySelector('[data-not-found-countdown]').textContent`), '4');
            await evaluate(`window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}))`);
            assert.equal(await evaluate(`document.querySelector('[data-not-found-countdown]').textContent`), '5');
            for (let attempt = 0; attempt < 200; attempt++) {
                if (await evaluate(`location.pathname==='/dashboard' && document.readyState==='complete'`)) break;
                await new Promise(resolve => setTimeout(resolve, 30));
            }
            assert.equal(await evaluate(`location.pathname`), '/dashboard');
        });
        await scenario('balance operation chooser keeps one modal, preserves failed values and filters, prevents double submission and exposes contextual receipts', async () => {
            for(const width of [320,375,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('balance?ledger=payments&from=2026-10-01&to=2026-10-18');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`balance overflow ${width}`);
                assert.equal(await evaluate(`document.querySelectorAll('.finance-overview h3').length`),3);
                assert.ok(await evaluate(`getComputedStyle(document.body).fontFamily.includes('Source Sans 3')`));
                await assertButtonShapes(`balance controls ${width}`);
                await evaluate(`document.querySelector('.row-actions-menu summary').click()`);
                assert.ok(await evaluate(`(()=>{const box=document.querySelector('.row-actions-menu[open] .row-actions-popover').getBoundingClientRect();return box.left>=0&&box.right<=innerWidth})()`),`balance action menu ${width}`);
                await evaluate(`document.querySelector('.row-actions-menu summary').click()`);
                await evaluate(`document.querySelector('[data-dialog-open="balance-add"]').focus()`);await key('Enter');
                assert.equal(await evaluate(`document.querySelectorAll('dialog[open]').length`),1);
                assert.equal(await evaluate(`document.activeElement.dataset.financeGo`),'expense');
                await key('Enter');
                assert.equal(await evaluate(`document.querySelector('[data-finance-step="expense"]').hidden`),false);
                assert.ok(await evaluate(`document.activeElement.matches('[name="description"]')`));
                await assertButtonShapes(`balance modal ${width}`);
                assert.ok(await evaluate(`document.querySelector('#balance-add').getBoundingClientRect().width<=innerWidth`));
                await evaluate(`document.querySelector('[data-finance-step="expense"] [data-finance-go="choose"]').click()`);
                await evaluate(`document.querySelector('[data-finance-go="cash"]').click()`);
                assert.equal(await evaluate(`document.querySelectorAll('dialog[open]').length`),1);
                assert.equal(await evaluate(`document.querySelector('[data-finance-step="cash"]').hidden`),false);
                await key('Escape');
                assert.equal(await evaluate(`document.querySelector('#balance-add').open`),false);
                assert.equal(await evaluate(`document.activeElement.dataset.dialogOpen`),'balance-add');
                for(const theme of ['light','dark']) {
                    await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);await new Promise(resolve=>setTimeout(resolve,200));
                    assert.deepEqual((await contrast()).failed,[],`balance contrast ${theme} ${width}`);
                }
                await evaluate(`document.documentElement.dataset.eaTheme='light'`);await new Promise(resolve=>setTimeout(resolve,200));
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`balance-workspace-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
            }
            await evaluate(`document.querySelector('[data-dialog-open="balance-add"]').click();document.querySelector('[data-finance-go="expense"]').click();document.querySelector('#expense-create-description').value='Carburante turno serale';document.querySelector('#expense-create-amount').value='12,50'`);
            financeRejectNext=true;
            const before=financeWrites;
            await evaluate(`document.querySelector('[data-finance-step="expense"] form').requestSubmit()`);
            for(let attempt=0;attempt<100;attempt++){if(await evaluate(`!!document.querySelector('#balance-add .form-feedback[role="alert"]')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(financeWrites,before+1);
            assert.equal(await evaluate(`document.querySelector('#expense-create-amount').value`),'12,50');
            assert.equal(await evaluate(`document.querySelector('#expense-create-description').value`),'Carburante turno serale');
            assert.equal(await evaluate(`document.querySelector('#balance-add').open`),true);
            await evaluate(`document.querySelector('[data-finance-step="expense"] [data-finance-go="choose"]').click();document.querySelector('[data-finance-go="cash"]').click();document.querySelector('#cash-create-amount').value='20'`);
            await evaluate(`window.__financeDocument=true;const form=document.querySelector('[data-finance-step="cash"] form');window.__oldFinanceForm=form;form.requestSubmit();form.requestSubmit()`);
            for(let attempt=0;attempt<150;attempt++){if(await evaluate(`window.__oldFinanceForm&&!window.__oldFinanceForm.isConnected`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(financeWrites,before+2);
            assert.equal(await evaluate(`window.__financeDocument`),true);
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('from')`),'2026-10-01');
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('ledger')`),'payments');
            assert.ok(await evaluate(`document.body.textContent.includes('Operazione registrata.')`));
            await evaluate(`document.querySelector('[data-receipt] [data-dialog-open^="receipt-"]').click()`);
            assert.equal(await evaluate(`document.querySelectorAll('dialog[open]').length`),1);
            assert.match(await evaluate(`document.querySelector('dialog[open] form').getAttribute('action')`),/\/balance\/[0-9a-f-]{36}\/payment$/);
            await key('Escape');
            await evaluate(`document.querySelector('.row-actions-menu summary').click();document.querySelector('.row-actions-menu [data-dialog-open]').click()`);
            assert.equal(await evaluate(`document.querySelectorAll('dialog[open]').length`),1);
            assert.ok(await evaluate(`document.querySelector('dialog[open] [name="note"]').getClientRects().length>0`));
            await key('Escape');
            assert.ok(await evaluate(`document.activeElement.matches('.row-actions-menu summary')`));
            for(const view of ['expenses','cash','payments','reversed','pending']) {
                await navigate('balance?ledger='+view);
                assert.equal(await evaluate(`document.querySelector('.ledger-navigation [aria-current="page"]').href.includes('ledger=${view}')`),true);
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
            }
            assert.ok(await evaluate(`document.querySelector('#cash-details table caption').textContent.includes('Situazione sospesi alla data di riferimento')`));
        });
        await scenario('pickup calendar keeps counts and lazy account pages at all sizes without document reloads', async () => {
            for(const width of [320,375,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('pickups?month=2026-10');
                assert.equal(await evaluate(`document.querySelectorAll('.pickup-calendar-table th').length`),7);
                assert.equal(await evaluate(`document.querySelectorAll('[data-pickup-date]').length`),31);
                assert.equal(await evaluate(`document.querySelector('[data-pickup-date="2026-10-17"]').dataset.pickupCount`),'47');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`calendar overflow ${width}`);
                assert.ok(await evaluate(`Array.from(document.querySelectorAll('.pickup-calendar-table tr')).every(row=>row.children.length===7)`));
                await assertButtonShapes(`calendar buttons ${width}`);
                for(const theme of ['light','dark']) {
                    await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);
                    await new Promise(resolve=>setTimeout(resolve,150));
                    assert.deepEqual((await contrast()).failed,[],`calendar contrast ${theme} ${width}`);
                }
                await navigate('pickups/day?date=2026-10-17');
                assert.equal(await evaluate(`document.querySelectorAll('[data-pickup-account]').length`),10);
                assert.equal(await evaluate(`document.querySelectorAll('[data-pickup-account][open],.pickup-order-card').length`),0);
                assert.equal(await evaluate(`document.querySelector('[data-pickup-day-total]').textContent`),'47');
                await evaluate(`document.querySelector('[data-pickup-account] summary').click()`);
                for(let attempt=0;attempt<100;attempt++) {if(await evaluate(`document.querySelectorAll('.pickup-order-card').length===7`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                assert.equal(await evaluate(`document.querySelectorAll('.pickup-order-card').length`),7);
                assert.match(await evaluate(`document.querySelector('.pickup-order-card a[href*="/orders/"]').getAttribute('href')`),/\/orders\/[0-9a-f-]{36}$/);
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`daily report overflow ${width}`);
            }
            await evaluate(`window.__agendaDocument='retained'`);
            const follow=async selector=>{
                await evaluate(`window.__agendaRoot=document.querySelector('[data-pickup-agenda]');document.querySelector(${JSON.stringify(selector)}).focus()`);
                await key('Enter');
                for(let attempt=0;attempt<100;attempt++) {if(await evaluate(`!window.__agendaRoot.isConnected && !document.querySelector('[data-pickup-agenda][aria-busy="true"]')`))return;await new Promise(resolve=>setTimeout(resolve,30));}
                throw new Error('Agenda navigation failed: '+selector);
            };
            await follow('.pickup-accounts-pagination [aria-label="Pagina successiva"]');
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('page')`),'2');
            assert.equal(await evaluate(`document.querySelectorAll('[data-pickup-account]').length`),10);
            assert.equal(await evaluate(`document.querySelectorAll('.pickup-order-card').length`),0);
            await follow('.page-back a');
            await follow('[aria-label="Mese precedente"]');
            assert.match(await evaluate(`document.getElementById('calendar-month').textContent`),/Settembre 2026/);
            await follow('[aria-label="Mese successivo"]');await follow('[aria-label="Mese successivo"]');
            assert.match(await evaluate(`document.getElementById('calendar-month').textContent`),/Novembre 2026/);
            await follow('.pickup-calendar-navigation a.btn');
            await follow('[data-pickup-date="2026-10-18"]');
            assert.ok(await evaluate(`document.body.textContent.includes('Nessun ritiro da effettuare')`));
            assert.equal(await evaluate(`window.__agendaDocument`),'retained');
            await navigate('pickups?month=2026-10');
            await evaluate(`window.__originalCalendar=document.querySelector('[data-pickup-date="2026-10-17"]');window.__originalCalendar.focus();window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'orders'}}))`);
            for(let attempt=0;attempt<100;attempt++){if(await evaluate(`!window.__originalCalendar.isConnected`))break;await new Promise(resolve=>setTimeout(resolve,30));}
            assert.ok(await evaluate(`!window.__originalCalendar.isConnected`));
            assert.equal(await evaluate(`document.activeElement.dataset.pickupDate`),'2026-10-17');
        });
        await scenario('customer dashboard preserves booking and slug links, readable themes and accessible responsive navigation', async () => {
            for (const width of [320,360,375,390,430,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('dashboard');
                for(let attempt=0;attempt<100;attempt++){if(await evaluate(`!!document.querySelector('.order-row')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`portal-dashboard-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
                await assertButtonShapes(`portal buttons ${width}`);
                assert.equal(await evaluate(`document.querySelector('.brand-wordmark').getAttribute('aria-label')`),'EA-Express');
                assert.ok(await evaluate(`!!document.querySelector('.dashboard-actions [href="/pickups/new"]')`));
                assert.equal(await evaluate(`document.querySelector('.order-row').getAttribute('href')`),'/shipments/24369069-5868-49fd-b04c-3709fa3b5a81');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`), `portal overflow at ${width}`);
                assert.ok(await evaluate(`document.querySelector('.sidebar').getBoundingClientRect().width>0 || document.querySelector('.menu-toggle').getBoundingClientRect().width>0`));
                if(width<992){
                    await evaluate(`document.querySelector('.menu-toggle').click()`);
                    assert.equal(await evaluate(`document.querySelector('.sidebar').getAttribute('aria-modal')`),'true');
                    await key('Escape');
                    assert.ok(await evaluate(`document.activeElement.matches('.menu-toggle')`));
                }
                for(const theme of ['light','dark']) {
                    await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);
                    await new Promise(resolve=>setTimeout(resolve,200));
                    const sample=await contrast();
                    assert.deepEqual(sample.failed,[], `portal contrast ${theme} at ${width}`);
                }
                await evaluate(`document.documentElement.dataset.eaTheme='light'`);
                await new Promise(resolve=>setTimeout(resolve,200));
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`portal-dashboard-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
            }
        });
        await scenario('shared navigation and pending workspace preserve reachable creation, payment actions and bounded close tooltips', async () => {
            for (const width of [375,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
                await navigate('workspace-pending.html');
                await evaluate('document.fonts.ready');
                assert.ok(await evaluate(`document.querySelectorAll('.pending-account-panel').length === 2`));
                assert.ok(await evaluate(`![...document.querySelectorAll('.pending-account-panel [data-dialog-open]')].some(node=>!/^pending-(assign|settle|edit|history)-[0-9a-f-]{36}$/.test(node.dataset.dialogOpen))`));
                const colors = await evaluate(`(()=>{const style=node=>getComputedStyle(document.querySelector(node));const token=name=>getComputedStyle(document.documentElement).getPropertyValue(name).trim();return {search:style('.filter-submit').getPropertyValue('--action-color').trim(),reset:style('.filter-reset').getPropertyValue('--action-color').trim(),filter:style('.filter-more').backgroundColor,searchBackground:style('.filter-submit').backgroundColor,edit:style('.pending-account-panel .action-edit').getPropertyValue('--action-color').trim(),brand:token('--ea-brand'),info:token('--ea-info'),success:token('--ea-success')};})()`);
                assert.equal(colors.search,colors.brand); assert.equal(colors.reset,colors.info); assert.equal(colors.edit,colors.success); assert.equal(colors.filter,colors.searchBackground);
                if(width<992){await evaluate(`document.querySelector('.app-header [data-bs-toggle="offcanvas"]').click()`);await evaluate(`Promise.all(document.querySelector('.app-sidebar').getAnimations().map(animation=>animation.finished))`);}
                assert.ok(await evaluate(`(()=>{const r=document.querySelector('.sidebar-content').getBoundingClientRect(),b=document.querySelector('.sidebar-create').getBoundingClientRect();return b.left>=r.left&&b.right<=r.right&&b.width>=44&&b.height>=44;})()`),`Sidebar creation is reachable @${width}`);
                if(width<992){await key('Escape');await evaluate(`Promise.all(document.querySelector('.app-sidebar').getAnimations().map(animation=>animation.finished))`);}
                const edit = await evaluate(`(()=>{const trigger=document.querySelector('.pending-account-panel .action-edit');trigger.click();return trigger.dataset.dialogOpen;})()`);
                assert.ok(await evaluate(`document.getElementById(${JSON.stringify(edit)}).open`));
                assert.ok(await evaluate(`document.querySelector('dialog[open] form[action*="/pending/"] [name="version"]') !== null`));
                const close = await evaluate(`(()=>{const b=document.querySelector('dialog[open] .modal-heading [data-dialog-close]'),r=b.getBoundingClientRect();return {x:r.left+r.width/2,y:r.top+r.height/2};})()`);
                for(const mode of ['hover','focus']){
                    if(mode==='hover')await send('Input.dispatchMouseEvent',{type:'mouseMoved',...close});
                    else{await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:0,y:0});await key('Tab');await evaluate(`document.querySelector('dialog[open] .modal-heading [data-dialog-close]').focus()`);}
                    const bounds = await evaluate(`(()=>{const tooltip=document.querySelector('.ea-tooltip'),r=tooltip.getBoundingClientRect();return {content:tooltip.textContent,left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:innerWidth,height:innerHeight};})()`);
                    assert.equal(bounds.content,'Chiudi finestra');
                    assert.ok(bounds.left>=12 && bounds.right<=bounds.width-11 && bounds.top>=12 && bounds.bottom<=bounds.height-11,JSON.stringify({width,mode,bounds}));
                }
                await key('Escape');
                await evaluate(`document.querySelector('.pending-account-panel .action-history').click()`);
                assert.ok(await evaluate(`document.querySelector('dialog[open]').textContent.includes('Storico pagamenti')`));
                await key('Escape');
                if(process.env.EA_UI_SCREENSHOTS){await evaluate(`document.activeElement?.blur();window.scrollTo(0,0)`);writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`pending-workspace-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));}
            }
        });
        await scenario('notification cards distinguish urgency and keep expanded updates separated in both themes and responsive widths', async () => {
            for (const width of [320,375,768,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('notifications-presentation.html');
                await evaluate(`document.querySelectorAll('[data-notification-group] > summary').forEach(summary=>summary.click())`);
                for(let index=0;index<100;index++) {
                    if(await evaluate(`document.querySelectorAll('.notification-event').length===6`)) break;
                    await new Promise(resolve=>setTimeout(resolve,20));
                }
                assert.equal(await evaluate(`document.querySelectorAll('.notification-event').length`),6);
                assert.ok(await evaluate(`document.querySelectorAll('.notification-event.unread').length>0 && document.querySelectorAll('.notification-event:not(.unread)').length>0`));
                for(const theme of ['light','dark']) {
                    await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);
                    const cards = await evaluate(`(()=>{
                        const probe=document.createElement('span');document.body.append(probe);
                        const tokenColor=name=>{probe.style.color='var('+name+')';return getComputedStyle(probe).color;};
                        const result=[...document.querySelectorAll('[data-notification-group]')].map(group=>{
                            const style=getComputedStyle(group),summary=group.querySelector('summary');
                            const urgent=summary.textContent.includes('EA-URGENTE');
                            return {urgent,label:group.querySelector('.notification-priority').textContent.trim(),border:style.borderInlineStartColor,expected:tokenColor(urgent?'--ea-danger':'--ea-success'),background:getComputedStyle(summary).backgroundColor,events:[...group.querySelectorAll('.notification-event')].map((event,index,events)=>{const rect=event.getBoundingClientRect(),style=getComputedStyle(event),button=event.querySelector('button').getBoundingClientRect();return {border:style.borderInlineStartColor,bottomBorder:parseFloat(style.borderBottomWidth),padding:parseFloat(style.paddingTop),gap:index?rect.top-events[index-1].getBoundingClientRect().bottom:null,buttonInside:button.left>=rect.left&&button.right<=rect.right+1&&button.bottom<=rect.bottom+1};})};
                        });probe.remove();return result;
                    })()`);
                    assert.equal(cards.length,2);
                    assert.notEqual(cards[0].background,cards[1].background,`priority backgrounds ${theme} @${width}`);
                    for(const card of cards) {
                        assert.equal(card.label,card.urgent?'Urgente':'Standard');
                        assert.equal(card.border,card.expected,`priority color ${theme} @${width}`);
                        for(const event of card.events) {
                            assert.equal(event.border,card.expected,`event color ${theme} @${width}`);
                            assert.ok(event.bottomBorder>=1&&event.padding>=12&&event.buttonInside,`event separation ${theme} @${width}: ${JSON.stringify(event)}`);
                            if(event.gap!==null) assert.ok(event.gap>=12,`event gap ${theme} @${width}: ${event.gap}`);
                        }
                    }
                    assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`notifications overflow ${theme} @${width}`);
                    if(process.env.EA_UI_SCREENSHOTS && [375,1440].includes(width)) {
                        await evaluate(`document.activeElement?.blur();window.scrollTo(0,0)`);
                        writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`notifications-${theme}-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
                    }
                }
            }
        });
        await scenario('marking notifications read preserves the expanded shipment and binds its refreshed history without navigation', async () => {
            await navigate('notifications-presentation.html');
            await evaluate(`window.__notificationsDocument=true;document.querySelector('[data-notification-group] > summary').click();window.__notificationGroup=document.querySelector('[data-notification-group]');`);
            for(let attempt=0;attempt<100;attempt++){if(await evaluate(`document.querySelectorAll('.notification-event').length===3`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            await evaluate(`document.querySelector('form[action$="/notifications/read-all"]').requestSubmit()`);
            for(let attempt=0;attempt<100;attempt++){if(await evaluate(`!window.__notificationGroup.isConnected&&document.querySelectorAll('.notification-event').length===3`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(await evaluate(`window.__notificationsDocument`),true);
            assert.equal(await evaluate(`window.__notificationGroup.isConnected`),false);
            assert.equal(await evaluate(`document.querySelector('[data-notification-group]').open`),true);
            assert.equal(await evaluate(`document.querySelectorAll('.notification-event').length`),3);
        });
        await scenario('workspace registers keep scrolling headings, icon-only creation and touch controls at every requested width', async () => {
            const pages = ['workspace-pending.html','workspace-customers.html','workspace-rates.html','workspace-reports.html','workspace-orders-in-progress.html','workspace-orders-history.html','workspace-orders-incoming.html','workspace-pickups.html','workspace-settings-index.html','workspace-profile-edit.html','workspace-users-index.html','workspace-notifications-index.html','finance-payments.html'];
            for (const width of [320,360,375,390,430,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
                for (const page of pages) {
                    await navigate(page);
                    assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`${page} overflow at ${width}`);
                    assert.ok(await evaluate(`[...document.querySelectorAll('.app-header,.page-heading,.dashboard-heading')].every(node=>!['sticky','fixed'].includes(getComputedStyle(node).position))`),`${page} persistent page title at ${width}`);
                    const invalid = await evaluate(`[...document.querySelectorAll('.action-add')].filter(node=>node.getClientRects().length).filter(node=>node.textContent.trim() || !node.getAttribute('aria-label') || node.getBoundingClientRect().width<44 || node.getBoundingClientRect().height<44).map(node=>({label:node.getAttribute('aria-label'),text:node.textContent.trim(),width:node.getBoundingClientRect().width}))`);
                    assert.deepEqual(invalid,[],`${page} creation action at ${width}`);
                    const headingPosition = await evaluate(`document.querySelector('.app-header').getBoundingClientRect().top+scrollY`);
                    await evaluate(`window.scrollTo({top:450,behavior:'instant'})`);
                    const scrolledHeadingPosition = await evaluate(`document.querySelector('.app-header').getBoundingClientRect().top+scrollY`);
                    assert.ok(Math.abs(scrolledHeadingPosition-headingPosition)<=1,`${page} title follows document scrolling at ${width}`);
                    if(process.env.EA_UI_SCREENSHOTS && [375,768,1024,1440].includes(width)) writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`revision-${page.replace('.html','')}-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
                }
            }
        });
        await scenario('balance account selection searches, removes and clears slugs and cancels mobile filter changes', async () => {
            for (const width of [320,390,768,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
                await navigate('finance-payments.html');
                if(width<576) await evaluate(`document.querySelector('[data-filter-more]').click()`);
                await evaluate(`document.querySelector('[data-account-menu] summary').click()`);
                assert.ok(await evaluate(`document.querySelectorAll('[data-account-choice]').length>=2`),'multiple customer accounts available');
                await evaluate(`[...document.querySelectorAll('[data-account-choice]')].slice(0,2).forEach(node=>node.click())`);
                assert.equal(await evaluate(`document.querySelectorAll('[data-account-selected] button').length`),2);
                const values = await evaluate(`new FormData(document.querySelector('[data-filter-toolbar]')).getAll('customer_ids[]')`);
                assert.equal(new Set(values).size,2);
                assert.ok(values.every(value=>/^[0-9a-f-]{36}$/.test(value)));
                await evaluate(`document.querySelector('[data-account-selected] button').click()`);
                assert.equal(await evaluate(`new FormData(document.querySelector('[data-filter-toolbar]')).getAll('customer_ids[]').length`),1);
                await evaluate(`document.querySelector('[data-account-menu]').open=true;const search=document.querySelector('[data-account-search]');search.value='non-esiste-questo-account';search.dispatchEvent(new Event('input'))`);
                assert.ok(await evaluate(`!document.querySelector('[data-account-empty]').hidden`));
                await evaluate(`document.querySelector('[data-account-clear]').click()`);
                assert.equal(await evaluate(`new FormData(document.querySelector('[data-filter-toolbar]')).getAll('customer_ids[]').length`),0);
                await key('Escape');
                if(width<576) {
                    await evaluate(`document.querySelector('[data-filter-close]').click();document.querySelector('[data-filter-more]').click();document.querySelector('[data-account-choice]').click();document.querySelector('[data-filter-close]').click()`);
                    assert.equal(await evaluate(`new FormData(document.querySelector('[data-filter-toolbar]')).getAll('customer_ids[]').length`),0,'cancel restores full selection');
                }
            }
        });
        const files = scenarioPattern ? [] : readdirSync(temporary).filter(file => file.endsWith('.html') && /<!doctype html>/i.test(readFileSync(join(temporary, file), 'utf8')));
        const failures = [];
        for (const width of [320, 360, 375, 390, 430, 768, 1024, 1440]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 600 });
            for (const file of files) {
                await navigate(file);
                const overflow = await evaluate(`document.documentElement.scrollWidth > innerWidth + 1`);
                if (overflow) failures.push(`${file} overflows at ${width}px: ${JSON.stringify(await evaluate(`Array.from(document.querySelectorAll('main *')).filter(n=>n.getClientRects().length&&n.getBoundingClientRect().right>innerWidth+1).slice(0,8).map(n=>({tag:n.tagName,class:n.className,right:n.getBoundingClientRect().right}))`))}`);
                await assertButtonShapes(`${file} buttons ${width}`);
                const stretchedBadges = await evaluate(`Array.from(document.querySelectorAll('.status-badge,.status-pill')).filter(node=>node.getClientRects().length&&!node.children.length).filter(node=>{const range=document.createRange();range.selectNodeContents(node);return range.getClientRects().length===1&&node.getBoundingClientRect().width>range.getBoundingClientRect().width+34}).map(node=>node.textContent.trim())`);
                if(stretchedBadges.length)failures.push(`${file} has stretched badges at ${width}px: ${stretchedBadges.join(', ')}`);
                if(file === 'orders_2.html') {
                    const overlaps=await evaluate(`(()=>{const card=document.querySelector('.price-card');const button=card?.querySelector('.price-card-heading>.icon-button').getBoundingClientRect();const facts=card?.querySelector('dl').getBoundingClientRect();return button&&facts&&button.left<facts.right&&button.right>facts.left&&button.top<facts.bottom&&button.bottom>facts.top})()`);
                    if(overlaps)failures.push(`${file} price edit overlaps rate timing at ${width}px`);
                }
                if (file === 'orders_in-progress.html') {
                    const layout = await evaluate(`(()=>{const row=document.querySelector('.operational-identity .order-recipient'),name=row.querySelector('.recipient-name').getBoundingClientRect(),dot=row.querySelector('.recipient-dot'),badge=dot.getBoundingClientRect();return {overlap:name.left<badge.right&&name.right>badge.left&&name.top<badge.bottom&&name.bottom>badge.top,label:dot.getAttribute('aria-label'),right:badge.right,width:badge.width};})()`);
                    if(layout.overlap || layout.right>width+1 || !layout.label) failures.push(`${file} recipient indicator is unreadable at ${width}px: ${JSON.stringify(layout)}`);
                }
            }
        }
        await scenario('rendered pages and badges fit mobile, tablet, laptop and desktop with realistic data', () => assert.deepEqual(failures, []));
        const contrastResults = {};
        await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
        for (const theme of ['dark', 'light']) {
            let checked = 0, failed = [];
            for (const file of files) {
                await navigate(file);
                await evaluate(`document.documentElement.dataset.eaTheme=${JSON.stringify(theme)};document.querySelectorAll('details').forEach(node=>node.open=true)`);
                await new Promise(resolve => setTimeout(resolve, 200));
                const result = await contrast(); checked += result.checked; failed.push(...result.failed.map(item=>({page:file,...item})));
            }
            contrastResults[theme] = {checked,failed};
        }
        await scenario('at least 95% of sampled text meets contrast thresholds in both themes', () => {
            for(const [theme,result] of Object.entries(contrastResults)) {
                t.diagnostic(`${theme}: ${result.checked-result.failed.length}/${result.checked} text samples meet contrast`);
                t.diagnostic(JSON.stringify([...new Map(result.failed.map(item => [item.class + item.text, item])).values()].slice(0,25))); 
                assert.ok(result.checked>100);
                assert.ok(result.failed.length/result.checked <= .05, `${theme}: ${JSON.stringify(result.failed.slice(0,35))}`);
            }
        });
        await scenario('Rider AJAX updates state and timeline without navigation, keeps scroll, rejects double click and preserves failed data', async () => {
            await send('Emulation.setDeviceMetricsOverride', { width: 375, height: 800, deviceScaleFactor: 1, mobile: true });
            await navigate('ajax-order.html');
            await evaluate(`window.__documentMarker='unchanged'; document.querySelector('#next-action').scrollIntoView();`);
            for (const [status, label] of [['rider_arriving','Conferma pacco ritirato'],['picked_up','Avvia il trasporto'],['in_transit','Inizia la consegna'],['out_for_delivery','Conferma consegna'],['delivered','Non sono richieste altre operazioni']]) {
                await evaluate(`new Promise(resolve=>{window.scrollTo({top:400,behavior:'instant'});requestAnimationFrame(()=>requestAnimationFrame(resolve));})`);
                const before = await evaluate('scrollY');
                const count = actionRequests;
                await evaluate(`const button=document.querySelector('#next-action .workflow-primary-actions > .action-confirm, #next-action .rider-assignment-controls > .action-confirm');button.click();button.click();`);
                for (let i=0;i<100;i++) {
                    if (await evaluate(`document.querySelector('.order-workflow').textContent.includes(${JSON.stringify(label)}) && !document.querySelector('[data-order-action][aria-busy="true"]')`)) break;
                    await new Promise(resolve=>setTimeout(resolve,20));
                }
                assert.equal(actionRequests, count+1, status+' duplicate requests');
                assert.equal(await evaluate('window.__documentMarker'),'unchanged');
                assert.equal(await evaluate('location.pathname'),'/ajax-order.html');
                await evaluate(`new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))`);
                assert.ok(Math.abs(await evaluate('scrollY')-before)<3, status+' scroll moved from '+before+' to '+await evaluate('scrollY')+' geometry '+JSON.stringify(await evaluate(`({height:innerHeight,body:document.documentElement.scrollHeight,viewport:visualViewport.height,scale:visualViewport.scale})`)));
                assert.ok(await evaluate(`document.querySelector('.order-workflow').textContent.includes(${JSON.stringify(label)})`));
                assert.ok(await evaluate(`document.querySelector('.shipment-progress').textContent.length>20`));
            }
            await navigate('ajax-order.html'); failNextAction=true;
            await evaluate(`const note=document.querySelector('[name="note"]');note.value='Mantieni questa nota';document.querySelector('#next-action').scrollIntoView();window.__documentMarker='unchanged';document.querySelector('#next-action .workflow-primary-actions > .action-confirm, #next-action .rider-assignment-controls > .action-confirm').click();`);
            for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-order-feedback]')?.textContent.includes('conservati')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(await evaluate(`document.querySelector('[name="note"]').value`),'Mantieni questa nota');
            assert.equal(await evaluate(`document.querySelector('#next-action .workflow-primary-actions > .action-confirm, #next-action .rider-assignment-controls > .action-confirm').disabled`),false);
            assert.equal(await evaluate('window.__documentMarker'),'unchanged');
        });

        await scenario('timeline keeps the latest backend state when events arrive during a pending refresh', async () => {
            const original=readFileSync(join(temporary,'ajax-order.html'));
            try {
                await navigate('ajax-order.html');
                await evaluate(`window.__remoteTimeline='same'`);
                writeFileSync(join(temporary,'ajax-order.html'),JSON.parse(readFileSync(join(temporary,'ajax-rider_arriving.json'))).html);
                delayOrderRefresh=true;
                await evaluate(`if(document.querySelector('[data-operations-refresh]')) document.querySelector('[data-operations-refresh]').click(); else window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                await new Promise(resolve=>setTimeout(resolve,50));
                writeFileSync(join(temporary,'ajax-order.html'),JSON.parse(readFileSync(join(temporary,'ajax-picked_up.json'))).html);
                await evaluate(`for(let i=0;i<8;i++)if(document.querySelector('[data-operations-refresh]')) document.querySelector('[data-operations-refresh]').click(); else window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-progress-status="picked_up"]').getAttribute('data-progress-state')==='current'`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                assert.equal(await evaluate(`document.querySelector('[data-progress-status="picked_up"]').getAttribute('data-progress-state')`),'current');
                assert.equal(await evaluate(`document.querySelector('[data-live-order]').dataset.orderVersion`),'4');
                assert.equal(await evaluate(`window.__remoteTimeline`),'same');
            } finally { delayOrderRefresh=false;writeFileSync(join(temporary,'ajax-order.html'),original); }
        });
        await scenario('compact booking, green confirmations, checkout and account prefill at all requested widths', async () => {
            for(const width of [1440,1280,1024,768,375]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('orders_create.html');
                assert.ok(await evaluate(`const a=document.querySelector('.booking-content [for="content_description"]').getBoundingClientRect(),b=document.querySelector('.booking-content [for="urgency"]').getBoundingClientRect();Math.abs(a.top-b.top)<3`));
                await evaluate(`const date=document.querySelector('[name="pickup_date"]');date.value=new Date(Date.now()+2*86400000).toISOString().slice(0,10);date.dispatchEvent(new Event('change',{bubbles:true}));date.closest('.booking-step').scrollIntoView({behavior:'instant'});`);
                await clickPicker('pickup_from');
                await new Promise(resolve=>setTimeout(resolve,160));
                assert.ok(await evaluate(`const popup=document.querySelector('.picker-time');popup.offsetHeight<290 && popup.offsetWidth<=${width<600?width:265}`));
                assert.ok(await evaluate(`const b=document.querySelector('.picker-time .action-confirm');b.getAttribute('aria-label')==='Conferma orario' && b.textContent.trim()==='' && b.offsetWidth>=38 && b.offsetHeight>=38`));
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`time-picker-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
                await key('Escape');
                await evaluate(`document.querySelector('.booking-content').scrollIntoView({behavior:'instant'});`);
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`package-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
                await evaluate(`const select=document.querySelector('[name="customer_id"]'); select.selectedIndex=1;select.dispatchEvent(new Event('change'));`);
                assert.ok(await evaluate(`const s=document.querySelector('[name="customer_id"]'),a=JSON.parse(s.selectedOptions[0].dataset.identity);document.querySelector('[name="store_name"]').value===a.name && document.querySelector('[name="sender_type"]:checked').value===a.sender_type`));
                await navigate('checkout.html');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
                assert.equal(await evaluate(`document.querySelector('.checkout-actions button').textContent.trim()`),'Torna indietro');
                assert.ok(await evaluate(`document.querySelector('.checkout-continue').offsetWidth<350`));
                await evaluate(`document.querySelector('.checkout-actions').scrollIntoView({behavior:'instant'});`);
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`checkout-actions-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
            }
        });

        await scenario('whole shipment row opens with click, Enter and Space while nested controls keep their action', async () => {
            for(const mode of ['click','Enter',' ']) {
                await navigate('orders_in-progress.html');
                const path=await evaluate(`new URL(document.querySelector('[data-order-link]').dataset.orderLink,location.href).pathname`);
                await evaluate(`const row=document.querySelector('[data-order-link]');const button=document.createElement('button');button.textContent='Messaggi test';button.addEventListener('click',()=>window.__nestedClick=true);row.append(button);button.click();`);
                assert.equal(await evaluate('window.__nestedClick'),true);
                assert.equal(await evaluate('location.pathname'),'/orders_in-progress.html');
                if(mode==='click')await evaluate(`document.querySelector('[data-order-link] .operational-route').click()`);
                else {await evaluate(`document.querySelector('[data-order-link]').focus()`);await key(mode,mode===' '?'Space':mode);}
                for(let i=0;i<100;i++){if(await evaluate(`location.pathname===${JSON.stringify(path)}`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                assert.equal(await evaluate('location.pathname'),path);
            }
        });

        await scenario('real HTTP journey: registration, prefilled booking, time, checkout back, admin assignment, Rider delivery and history', async () => {
            const reserve = createServer(); await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
            const port=reserve.address().port;await new Promise(resolve=>reserve.close(resolve));
            const phpOrigin=`http://127.0.0.1:${port}`;
            const router=join(temporary,'router.php');
            writeFileSync(router,`<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) { $file = '${portal}/dist'.$path; header('Content-Type: '.(str_ends_with($file,'.css') ? 'text/css' : (str_ends_with($file,'.js') ? 'text/javascript' : 'font/woff2'))); readfile($file); return; }
if (str_starts_with($path, '/build/') || in_array($path, ['/brand.svg','/favicon.svg'])) { return false; }
if (in_array($path, ['/register','/dashboard','/pickups/new']) || preg_match('#^/pickups/[0-9a-f-]{36}$#',$path)) { header('Content-Type: text/html'); readfile('${portal}/dist/index.html'); return; }
require '${resolve('vendor/autoload.php')}';
$app = require '${resolve('bootstrap/app.php')}';
$app->make(Illuminate\\Contracts\\Http\\Kernel::class)->bootstrap();
$app->make(Illuminate\\Foundation\\Vite::class)->useHotFile('${temporary}/vite.hot');
config(['session.files'=>'${temporary}/sessions', 'session.secure'=>false, 'session.domain'=>null, 'session.encrypt'=>false]);
config(['logging.channels.security.path'=>'${temporary}/security.log']);
Illuminate\\Support\\Facades\\Event::listen(Illuminate\\Notifications\\Events\\NotificationSending::class, function (Illuminate\\Notifications\\Events\\NotificationSending $event): ?bool {
    if ($event->notification instanceof App\\Notifications\\RiderEmailOtp) {
        $file = '${temporary}/otp-'.hash('sha256', $event->notifiable->email).'.json';
        file_put_contents($file, json_encode(['code' => $event->notification->code], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($file, 0600);
        return false;
    }
    return null;
});
Illuminate\\Http\\Request::enableHttpMethodParameterOverride();
$app->handleRequest(Illuminate\\Http\\Request::capture());
`);
            phpServer=spawn('php',['-S',`127.0.0.1:${port}`,'-t',resolve('public'),router],{env:{...process.env,APP_ENV:'testing',APP_URL:phpOrigin,AUTH_SECURITY_STORE:'array',AUTH_PASSWORD_BREACH_CHECK:'false',AUTH_ALERT_QUEUE_CONNECTION:'database',DB_CONNECTION:'sqlite',DB_DATABASE:join(temporary,'browser.sqlite'),DB_URL:'',SESSION_DRIVER:'file',CACHE_STORE:'array',MAIL_MAILER:'array',QUEUE_CONNECTION:'sync',BROADCAST_CONNECTION:'null'},stdio:['ignore','ignore','pipe']});
            let phpErrors='';phpServer.stderr.on('data',data=>phpErrors+=data);
            for(let i=0;i<100;i++){try{const r=await fetch(phpOrigin+'/api/v1/customer/csrf');if(r.ok)break;}catch{}await new Promise(resolve=>setTimeout(resolve,30));}
            const fixtureOrigin=origin;origin=phpOrigin;
            async function waitFor(expression){for(let i=0;i<150;i++){if(await evaluate(`document.readyState==='complete' && Boolean(${expression})`))return;await new Promise(resolve=>setTimeout(resolve,30));}throw new Error('HTTP journey did not reach '+expression+' '+await evaluate('document.body.innerText.slice(-1600)')+' '+phpErrors.slice(-600));}
            async function fill(fields){await evaluate(`for(const [name,value] of Object.entries(${JSON.stringify(fields)})){const input=document.querySelector('[name="'+name+'"]');if(!input)throw new Error('Missing field '+name);const setter=Object.getOwnPropertyDescriptor(input.tagName==='TEXTAREA'?HTMLTextAreaElement.prototype:HTMLInputElement.prototype,'value').set;setter.call(input,value);input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));}`);}
            try {
                await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
                await send('Network.clearBrowserCookies');
                await send('Page.navigate',{url:origin+'/register'});await waitFor(`document.querySelector('.sender-selector')`);
                for(const theme of ['dark','light']){await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);const result=await contrast();assert.equal(result.failed.length,0,JSON.stringify(result.failed));}
                await evaluate(`document.documentElement.dataset.eaTheme='dark'`);
                for(const width of [1440,1280,1024,768,375]) {
                    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                    assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
                    if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`registration-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
                }
                await evaluate(`document.querySelector('[name="sender_type"][value="online_shop"]').click()`);
                await fill({name:'Shop verifica browser',email:'browser@example.test',password:'PasswordSicura123',password_confirmation:'PasswordSicura123'});
                await evaluate(`document.querySelectorAll('.consent input[type="checkbox"]').forEach(input=>{if(!input.checked)input.click();})`);
                await evaluate(`document.querySelector('form').requestSubmit()`);await waitFor(`location.pathname==='/login' && document.querySelector('.portal-auth.is-login form')`);
                await fill({email:'browser@example.test',password:'PasswordSicura123'});
                await evaluate(`document.querySelector('.portal-auth form').requestSubmit()`);await waitFor(`location.pathname==='/verify-access' && document.querySelector('[data-otp-verification] [name="code"]')`);
                const customerCode=JSON.parse(readFileSync(join(temporary,'otp-'+createHash('sha256').update('browser@example.test').digest('hex')+'.json'),'utf8')).code;
                await fill({code:customerCode});
                await evaluate(`document.querySelector('[data-otp-verification]').requestSubmit()`);await waitFor(`location.pathname==='/dashboard'`);
                await send('Page.navigate',{url:origin+'/pickups/new'});await waitFor(`document.querySelector('[name="store_name"]')`);
                assert.equal(await evaluate(`document.querySelector('[name="sender_type"]')`),null);
                assert.match(await evaluate(`document.querySelector('.sender-profile-type').textContent`),/Shop online.*Dal tuo profilo/);
                assert.equal(await evaluate(`document.querySelector('[name="store_name"]').value`),'Shop verifica browser');
                const tomorrow=new Date(Date.now()+2*86400000).toISOString().slice(0,10);
                await fill({pickup_address:'Via Roma',pickup_street_number:'10',pickup_postal_code:'80121',pickup_city:'Napoli',delivery_address:'Via Roma',delivery_city:'Caserta',delivery_street_number:'20',delivery_postal_code:'81100',delivery_province:'Caserta',delivery_region:'Campania',recipient_name:'Destinatario browser',recipient_phone:'+390000000000',pickup_date:tomorrow,parcel_value:'25.00',content_description:'Ricambi automobile'});
                await evaluate(`document.querySelector('[name="pickup_from"]').nextElementSibling.click()`);
                await evaluate(`const inputs=document.querySelectorAll('.picker-time input');inputs[0].value='9';inputs[1].value='0';inputs[1].dispatchEvent(new Event('input'));document.querySelector('.picker-time .action-confirm').click()`);
                assert.equal(await evaluate(`document.querySelector('[name="pickup_from"]').value`),'09:00');
                await waitFor(`document.querySelector('.shipping-quote-summary[data-state="ready"]')`);
                assert.match(await evaluate(`document.querySelector('.shipping-quote-summary').textContent`),/8,50/);
                await evaluate(`document.querySelector('.order-form').requestSubmit()`);await waitFor(`document.querySelector('.checkout-review')`);
                await evaluate(`document.querySelector('.checkout-actions .button-back').click()`);
                assert.equal(await evaluate(`document.querySelector('[name="content_description"]').value`),'Ricambi automobile');
                await evaluate(`document.querySelector('.order-form').requestSubmit()`);await waitFor(`document.querySelector('.checkout-review')`);
                await evaluate(`document.querySelector('.checkout-continue').click()`);
                await waitFor(`document.querySelector('dialog[open] .save-pickup-prompt')`);
                await evaluate(`[...document.querySelectorAll('dialog[open] .modal-actions button')].find(button=>button.textContent.includes('Continua senza salvare')).click()`);
                await waitFor(`/^\\/pickups\\/[0-9a-f-]{36}$/.test(location.pathname)`);
                const orderId=await evaluate(`location.pathname.split('/').at(-1)`);
                async function staff(role) {
                    await send('Page.navigate', {url:'about:blank'});
                    await waitFor(`location.href === 'about:blank'`);
                    await send('Network.clearBrowserCookies');
                    const target = role === 'admin' ? '/orders/create' : '/orders/'+orderId;
                    await send('Page.navigate', {url:origin+target});
                    await waitFor(`document.querySelector('[data-auth-form]')`);
                    await fill({email:role+'@example.test',password:'password'});
                    await evaluate(`document.querySelector('[data-auth-form]').requestSubmit()`);
                    await waitFor(`document.querySelector('[name="code"]') && location.pathname==='/verify-email-otp'`);
                    const otpFile=join(temporary,'otp-'+createHash('sha256').update(role+'@example.test').digest('hex')+'.json');
                    const code=JSON.parse(readFileSync(otpFile,'utf8')).code;
                    await fill({code});
                    await evaluate(`document.querySelector('form[action$="/verify-email-otp/confirm"]').requestSubmit()`);
                    await waitFor(`location.pathname===${JSON.stringify(target)} && document.querySelector(${JSON.stringify(role === 'admin' ? '[data-shipping-quote]' : '#next-action')})`);
                }
                await staff('admin');
                await send('Page.navigate',{url:origin+'/orders/create'});await waitFor(`document.querySelector('[data-shipping-quote]')`);
                assert.equal(await evaluate(`document.querySelector('[data-shipping-quote]').dataset.state`),'empty');
                await fill({delivery_city:'Caserta',delivery_postal_code:'81100'});await waitFor(`document.querySelector('[data-shipping-quote][data-state="ready"]')`);
                assert.match(await evaluate(`document.querySelector('[data-shipping-quote]').textContent`),/8,50.*Caserta.*81100/s);
                await fill({delivery_postal_code:'123'});assert.equal(await evaluate(`document.querySelector('[data-shipping-quote]').dataset.state`),'empty');
                await send('Page.navigate',{url:origin+'/orders/incoming'});await waitFor(`document.querySelector('[data-order-link]')`);
                await evaluate(`document.querySelector('[data-order-link]').click()`);await waitFor(`location.pathname==='/orders/${orderId}' && document.querySelector('#assigned-rider')?.nextElementSibling?.matches('.ea-picker-trigger')`);
                await clickPicker('assigned-rider');await evaluate(`const search=document.querySelector('.ea-picker-popup input');search.value='Rider verifica';search.dispatchEvent(new Event('input'));`);await key('ArrowDown');await key('Enter');
                await evaluate(`document.querySelector('#next-action .workflow-primary-actions > .action-confirm, #next-action .rider-assignment-controls > .action-confirm').click()`);await waitFor(`document.querySelector('[data-order-version="2"]')`);
                await staff('rider');await send('Page.navigate',{url:origin+'/orders/'+orderId});await waitFor(`document.querySelector('#next-action') && document.querySelector('[data-order-version="2"]')`);
                await evaluate(`window.__journeyMarker='same-page'`);
                for(const version of [3,4,5,6,7]) {
                    await evaluate(`new Promise(resolve=>{window.scrollTo({top:350,behavior:'instant'});requestAnimationFrame(()=>requestAnimationFrame(resolve));})`);
                    const scroll=await evaluate('scrollY');
                    await evaluate(`const button=document.querySelector('#next-action .workflow-primary-actions > .action-confirm, #next-action .rider-assignment-controls > .action-confirm');button.click();button.click();`);
                    await waitFor(`document.querySelector('[data-order-version="${version}"]')`);
                    await evaluate(`new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))`);
                    assert.equal(await evaluate('window.__journeyMarker'),'same-page');
                    assert.ok(Math.abs(await evaluate('scrollY')-scroll)<3);
                }
                assert.ok(await evaluate(`document.querySelector('.order-workflow').textContent.includes('Non sono richieste altre operazioni')`));
                await send('Page.navigate',{url:origin+'/orders/history'});await waitFor(`document.querySelector('[data-order-link]')`);
                assert.equal(await evaluate(`document.querySelector('[data-order-link]').dataset.orderLink.endsWith('/orders/${orderId}')`),true);
            } finally {origin=fixtureOrigin;phpServer.kill('SIGTERM');}
        });

        // Keep screenshots outside the repository for inspection.
        const screenshots = process.env.EA_UI_SCREENSHOTS;
        if (screenshots && !scenarioPattern) {
            for (const width of [375,768,1024,1280,1440]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width<600 });
            for (const file of ['dashboard.html', 'orders_incoming.html', 'orders_history.html', 'pickups.html', 'customers.html', 'customer-detail.html', 'rates.html', 'orders_in-progress.html', 'orders_1.html', 'orders_2.html', 'balance.html', 'pending.html', 'orders_create.html', 'checkout.html', 'ajax-order.html', 'login.html', 'welcome.html', 'recipient-incidents.html', 'recipient-incidents_1.html', 'reports.html', 'rider-operations.html', 'rider-history.html', 'rider-detail.html']) {
                await navigate(file);
                writeFileSync(join(screenshots, file.replace('.html', `-${width}.png`)), Buffer.from((await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })).data, 'base64'));
            }
            const filterPages = ['orders_incoming','orders_in-progress','orders_history','pickups','balance','customers','reports','rates','pending','recipient-incidents','rider-operations','rider-detail'];
            const reviewImages = filterPages.map(page=>({page,image:readFileSync(join(screenshots,`${page}-${width}.png`)).toString('base64')}));
            const sheet = await evaluate(`(async()=>{
                const images=${JSON.stringify(reviewImages)};
                const tileWidth=${width}<600?375:600;
                const tileHeight=${width}<600?500:280;
                const canvas=document.createElement('canvas');canvas.width=tileWidth*3;canvas.height=tileHeight*4;
                const context=canvas.getContext('2d');context.fillStyle='#080e19';context.fillRect(0,0,canvas.width,canvas.height);
                for(let index=0;index<images.length;index++){
                    const item=images[index],picture=new Image();picture.src='data:image/png;base64,'+item.image;await picture.decode();
                    const x=(index%3)*tileWidth,y=Math.floor(index/3)*tileHeight;
                    context.fillStyle='#ffffff';context.font='16px sans-serif';context.fillText(item.page+' · ${width}px',x+8,y+20);
                    const left=${width}>=992?266:0,top=125,sourceWidth=${width}-left;
                    context.drawImage(picture,left,top,sourceWidth,${width}<600?450:420,x,y+30,tileWidth,tileHeight-30);
                }
                return canvas.toDataURL('image/png').split(',')[1];
            })()`);
            writeFileSync(join(screenshots,`review-${width}.png`),Buffer.from(sheet,'base64'));
            await navigate('filters-active.html?status=out_for_delivery&zone=Zona&shipping_type=regional&urgency=urgent&page=2');
            writeFileSync(join(screenshots,`filters-active-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
            await navigate('orders_history.html');
            await evaluate(`document.querySelector('[data-filter-more]').click()`);
            writeFileSync(join(screenshots,`filter-panel-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
            await navigate('login.html');
            await evaluate(`document.documentElement.dataset.eaTheme='light'`);
            await new Promise(resolve=>setTimeout(resolve,200));
            writeFileSync(join(screenshots, `login-light-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
            await navigate('rates.html');
            await evaluate(`document.querySelector('[data-rate-edit]').click()`);
            await new Promise(resolve=>setTimeout(resolve,200));
            writeFileSync(join(screenshots, `rate-editor-${width}.png`), Buffer.from((await send('Page.captureScreenshot', {format:'png',captureBeyondViewport:false})).data,'base64'));
            await navigate('orders_1.html');
            await evaluate(`document.getElementById('order-progress-title').scrollIntoView({behavior:'instant'})`);
            await new Promise(resolve=>setTimeout(resolve,200));
            writeFileSync(join(screenshots, `tracking-${width}.png`), Buffer.from((await send('Page.captureScreenshot', {format:'png',captureBeyondViewport:false})).data,'base64'));
            }
        }
    } finally {
        socket?.close();
        if (phpServer && phpServer.exitCode === null) phpServer.kill('SIGTERM');
        if (browser && browser.exitCode === null) await new Promise(resolve => { browser.once('exit', resolve); browser.kill('SIGTERM'); });
        if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
        rmSync(temporary, { recursive: true, force: true, maxRetries: 3 });
    }
});
