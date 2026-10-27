import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, existsSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, extname } from 'node:path';

const chrome = process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

test('EA pickers and operational pages: keyboard, validation, long content, contrast and responsive', { skip: !existsSync(chrome), timeout: 480000 }, async t => {
    const temporary = mkdtempSync(join(tmpdir(), 'ea-ui-'));
    const scenarioPattern = process.env.EA_UI_SCENARIOS ? new RegExp(process.env.EA_UI_SCENARIOS, 'i') : null;
    const scenario = (name, callback) => t.test(name, { skip: Boolean(scenarioPattern && !scenarioPattern.test(name)) }, callback);
    let browser, server, socket, phpServer;
    let zoneFeedRequests = 0, operationFeedFixture = 'rider-feed.json', operationDetailFixture;
    let financeWrites = 0, financeRejectNext = false;
    let chatData, chatWrites = 0, chatRejectNext = false;
    const chatReceipts = [];
    let actionRequests = 0, failNextAction = false, delayOrderRefresh = false;
    try {
        execFileSync('php', ['artisan', 'test', '--compact', 'tests/Feature/WorkspacePresentationTest.php', 'tests/Feature/OperationalFlowTest.php', 'tests/Feature/OperationalSurfaceTest.php', 'tests/Feature/OperationalDayTest.php', 'tests/Feature/OperationalRevisionTest.php', 'tests/Feature/GpsRemovalAndZoneOrdersTest.php', 'tests/Feature/PickupCalendarTest.php', 'tests/Feature/BalanceExperienceTest.php', 'tests/Feature/RoundedWorkspaceTest.php', 'tests/Feature/WorkspaceUiTest.php', 'tests/Feature/ManualOrderDelayTest.php'], { env: { ...process.env, XDEBUG_MODE: 'off', EA_UI_FIXTURE_DIR: temporary }, stdio: 'pipe' });
        chatData = JSON.parse(readFileSync(join(temporary, 'rounded-chat.json')));
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
            await evaluate(`Array.from(document.querySelectorAll('[role="option"]')).find(node=>node.textContent.startsWith('Storico')).click();document.querySelector('.portal-filter-toolbar').requestSubmit()`);
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('view')`), 'history');
            assert.equal(await evaluate(`document.querySelector('select').value`), 'history');
            assert.match(await evaluate(`document.querySelector('select').nextElementSibling.textContent`), /Storico/);
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
                assert.equal(await evaluate(`document.querySelector('[name="date"]').nextElementSibling.tagName`), 'BUTTON');
                assert.equal(await evaluate(`document.querySelector('[data-operational-map]')`), null);
                await evaluate(`document.querySelector('[data-operations-view="rider"]').click()`);
                assert.equal(await evaluate(`document.querySelector('[data-operations-view="rider"]').getAttribute('aria-pressed')`),'true');
                await evaluate(`document.querySelector('[data-operations-view="zone"]').click()`);
                if(width===1440) {
                    const originalFeed=readFileSync(join(temporary,'rider-feed.json'));
                    const changed=JSON.parse(originalFeed); changed.zones[0].name='Zona aggiornata realtime';
                    writeFileSync(join(temporary,'rider-feed.json'),JSON.stringify(changed));
                    const requestsBefore=zoneFeedRequests;
                    await evaluate(`window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                    for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-operations-list]').textContent.includes('Zona aggiornata realtime')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                    assert.ok(zoneFeedRequests>requestsBefore);
                    assert.ok(await evaluate(`document.querySelector('[data-operations-list]').textContent.includes('Zona aggiornata realtime')`));
                    writeFileSync(join(temporary,'rider-feed.json'),originalFeed);
                }
                await navigate('rider-history.html');
                assert.equal(await evaluate(`document.querySelector('[data-operational-map]')`),null);
                await navigate('rider-detail.html');
                assert.ok(await evaluate(`document.querySelector('.rider-order-events time').textContent.includes('10:30')`));
            }
        });
        await scenario('operational periods preserve Rider perspective, selection and navigation context without reloading', async () => {
            operationFeedFixture='revision-feed.json'; operationDetailFixture='rider-orders.html';
            try {
                await navigate('revision-zone.html');
                await evaluate(`window.__operationsPage='preserved';document.querySelector('[data-operations-view="rider"]').click()`);
                for(const mode of ['week','month','today']) {
                    await evaluate(`document.querySelector('.period-options a[href*="period=${mode}"]').click()`);
                    for(let i=0;i<100;i++){if(await evaluate(`new URL(location.href).searchParams.get('period')==='${mode}' && document.querySelector('[data-zone-updated]').textContent`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                    assert.equal(await evaluate(`document.querySelector('[data-operations-view="rider"]').getAttribute('aria-pressed')`),'true');
                    assert.equal(await evaluate(`window.__operationsPage`),'preserved');
                }
                await evaluate(`document.querySelector('[data-custom-period]').click();const date=document.querySelector('[name="date"]');date.value='2026-09-29';date.dispatchEvent(new Event('change',{bubbles:true}));document.querySelector('[data-operations-period]').requestSubmit()`);
                for(let i=0;i<100;i++){if(await evaluate(`new URL(location.href).searchParams.get('date')==='2026-09-29'`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                assert.equal(await evaluate(`new URL(location.href).searchParams.get('view')`),'rider');
                assert.equal(await evaluate(`window.__operationsPage`),'preserved');
                await evaluate(`window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                await new Promise(resolve=>setTimeout(resolve,200));
                assert.equal(await evaluate(`document.querySelector('[data-operations-view="rider"]').getAttribute('aria-pressed')`),'true');
                for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-explorer-orders] a[href*="return_to="]')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                assert.ok(await evaluate(`document.querySelector('[data-explorer-orders] a[href*="return_to="]').href.includes('view%3Drider')`));
            } finally { operationFeedFixture='rider-feed.json';operationDetailFixture=undefined; }
        });
        await scenario('backlog from earlier days stays separate and updates after delivery at all responsive widths', async () => {
            try {
                for(const width of [1440,1280,1024,768,375]) {
                    operationFeedFixture='backlog-before.json';operationDetailFixture='backlog-orders.html';
                    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                    await navigate('backlog-day.html?period=today&view=rider');
                    assert.equal(await evaluate(`document.querySelector('[data-kpi="total"]').textContent`),'0');
                    assert.equal(await evaluate(`document.querySelector('[data-kpi="backlog"]').textContent`),'1');
                    await evaluate(`document.querySelector('[data-operations-scope="late"]').click()`);
                    for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-explorer-orders]')?.textContent.includes('07/10/2026 23:59') && document.querySelector('[data-explorer-orders]').getAttribute('aria-busy')!=='true'`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                    assert.ok(await evaluate(`document.querySelector('[data-explorer-orders]').textContent.includes('Negozio Rossi')`));
                    assert.equal(await evaluate(`document.querySelector('[data-rider-gps]')`),null);
                    assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth`));
                    if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`backlog-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
                    operationFeedFixture='backlog-after.json';
                    await evaluate(`window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                    for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-kpi="overdue"]').textContent==='0'`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                    assert.equal(await evaluate(`document.querySelector('[data-kpi="backlog"]').textContent`),'0');
                }
                for(const width of [1440,1280,1024,768,375]) {
                    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                    await navigate('revision-rider.html');
                    assert.equal(await evaluate(`document.querySelectorAll('[data-operation-order]').length`),10);
                    assert.equal(await evaluate(`document.querySelectorAll('.workspace-pagination').length`),1);
                    assert.ok(await evaluate(`document.querySelector('.workspace-pagination').textContent.includes('Pagina 1 di 3')`));
                    assert.equal(await evaluate(`document.querySelector('[data-rider-gps]')`),null);
                    assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth`));
                    if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`revision-rider-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
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
                await navigate('zones-50.html?date=2026-10-07&view=zone&selected=Zona+00&sort=volume');
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
                        await evaluate(`window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
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
        await scenario('real daily data updates late counts and cards without GPS or reloading', async () => {
            operationFeedFixture='late-before.json';operationDetailFixture='late-orders-before.html';
            try {
                for(const width of [1440,1280,1024,768,375]){
                    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                    operationFeedFixture='late-before.json';operationDetailFixture='late-orders-before.html';
                    await navigate('late-day.html?view=rider');
                    let dailySnapshot, settled=0;
                    for(let i=0;i<100;i++){
                        dailySnapshot=await evaluate(`({undelivered:document.querySelector('[data-kpi="undelivered"]').textContent,orders:document.querySelector('[data-explorer-orders]')?.textContent || '',busy:document.querySelector('[data-explorer-orders]')?.getAttribute('aria-busy')==='true'})`);
                        settled=dailySnapshot.undelivered==='4' && dailySnapshot.orders.includes('07/10/2026 23:59') && !dailySnapshot.busy?settled+1:0;
                        if(settled>=3)break;
                        await new Promise(resolve=>setTimeout(resolve,20));
                    }
                    assert.equal(dailySnapshot.undelivered,'4');
                    assert.ok(dailySnapshot.orders.includes('07/10/2026 23:59'));
                    assert.equal(dailySnapshot.busy,false);
                    await evaluate(`window.__dailyPage='same'`);
                    operationFeedFixture='late-after.json';operationDetailFixture='late-orders-after.html';
                    await evaluate(`window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                    for(let i=0;i<100;i++){if(await evaluate(`document.querySelector('[data-kpi="delivered"]').textContent==='17'`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                    assert.equal(await evaluate(`document.querySelector('[data-kpi="undelivered"]').textContent`),'3');
                    assert.equal(await evaluate(`document.querySelector('[data-kpi="overdue"]').textContent`),'0');
                    assert.equal(await evaluate(`window.__dailyPage`),'same');
                    assert.equal(await evaluate(`document.querySelector('[data-operational-map]')`),null);
                    if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`late-day-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
                }
            } finally { operationFeedFixture='rider-feed.json';operationDetailFixture=undefined; }
        });
        await scenario('operational units expand with keyboard and keep state through realtime refresh', async () => {
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
                    assert.ok(await evaluate(`document.querySelector('.operational-preview').textContent.includes('CONSEGNA')`));
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
            await evaluate(`document.querySelector('.workflow-primary-actions [aria-label="Annulla spedizione"]').click()`);
            assert.equal(await evaluate(`document.querySelector('dialog[id$="-cancelled"]').open`), true);
            assert.equal(await evaluate(`document.querySelector('dialog[id$="-cancelled"] [name="cancellation_reason"]').value`), 'other');
            await key('Escape');
        });
        await scenario('filters keep their intended hierarchy, identical heights and mobile apply/cancel behavior', async () => {
            const pages = ['orders_incoming.html','orders_in-progress.html','orders_history.html','balance.html','customers.html','customer-detail.html','reports.html','rates.html','pending.html','recipient-incidents.html','rider-operations.html','rider-detail.html'];
            for (const width of [1440,1280,1024,768,375]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                for (const page of pages) {
                    await navigate(page);
                    const geometry = await evaluate(`(()=>{const toolbar=document.querySelector('[data-filter-toolbar]');return {controls:[...toolbar.querySelectorAll('.ea-picker-trigger,.form-control:not(.ea-picker-source),.filter-actions .icon-button,.filter-more:not([hidden]),[data-custom-period]')].filter(node=>node.getClientRects().length).map(node=>({height:node.getBoundingClientRect().height,left:node.getBoundingClientRect().left,right:node.getBoundingClientRect().right})),search:toolbar.querySelector('.filter-search')?.getBoundingClientRect().toJSON(),primary:toolbar.querySelector('.filter-primary').getBoundingClientRect().toJSON(),reset:toolbar.querySelector('.filter-reset').getBoundingClientRect().toJSON()}})()`);
                    assert.ok(geometry.controls.every(control=>Math.abs(control.height-(width<768?44:38))<1),`${page} at ${width}: ${JSON.stringify(geometry.controls)}`);
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
                if(innerWidth<=760)icons+=',.topbar .account';
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
        await scenario('balance operation chooser keeps one modal, preserves failed values and filters, prevents double submission and exposes contextual receipts', async () => {
            for(const width of [320,375,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('balance?ledger=payments&from=2026-10-01&to=2026-10-18');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`balance overflow ${width}`);
                assert.equal(await evaluate(`document.querySelectorAll('.finance-metrics .metric-tile').length`),4);
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
            await evaluate(`window.__eaPreviousDocument=true;const form=document.querySelector('[data-finance-step="cash"] form');form.requestSubmit();form.requestSubmit()`);
            for(let attempt=0;attempt<150;attempt++){if(await evaluate(`!window.__eaPreviousDocument&&document.readyState==='complete'`))break;await new Promise(resolve=>setTimeout(resolve,20));}
            assert.equal(financeWrites,before+2);
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
            for(const view of ['expenses','cash','recorded','reversed']) {
                await navigate('balance?ledger='+view);
                assert.equal(await evaluate(`document.querySelector('.ledger-navigation [aria-current="page"]').href.includes('ledger=${view}')`),true);
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`));
            }
            await navigate('balance?ledger=pending');
            assert.ok(await evaluate(`!document.querySelector('.ledger-navigation a[href*="ledger=pending"]')`));
            assert.ok(await evaluate(`document.querySelector('#cash-details a[href^="/pending"]').textContent.includes('Apri Sospesi')`));
        });
        await scenario('pickup calendar keeps seven columns, true counts, keyboard navigation and server page links at all sizes', async () => {
            for(const width of [320,375,768,1024,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('pickups?month=2026-10');
                assert.equal(await evaluate(`document.querySelectorAll('.pickup-calendar-table th').length`),7);
                assert.equal(await evaluate(`document.querySelectorAll('[data-pickup-date]').length`),31);
                assert.equal(await evaluate(`document.querySelector('[data-pickup-date="2026-10-17"]').dataset.pickupCount`),'47');
                assert.equal(await evaluate(`document.querySelector('[aria-current="date"]').dataset.pickupDate`),'2026-10-17');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`calendar overflow ${width}`);
                assert.ok(await evaluate(`Array.from(document.querySelectorAll('.pickup-calendar-table tr')).every(row=>row.children.length===7)`));
                await assertButtonShapes(`calendar buttons ${width}`);
                for(const theme of ['light','dark']) {
                    await evaluate(`document.documentElement.dataset.eaTheme='${theme}'`);
                    await new Promise(resolve=>setTimeout(resolve,150));
                    assert.deepEqual((await contrast()).failed,[],`calendar contrast ${theme} ${width}`);
                }
                await evaluate(`document.documentElement.dataset.eaTheme='light'`);
                await new Promise(resolve=>setTimeout(resolve,200));
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`pickup-calendar-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
                await navigate('pickups/day?date=2026-10-17');
                assert.equal(await evaluate(`document.querySelectorAll('.pickup-order-card').length`),20);
                assert.equal(await evaluate(`document.querySelector('[data-pickup-day-total]').textContent`),'47');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`daily report overflow ${width}`);
                assert.match(await evaluate(`document.querySelector('.pickup-order-card [data-order-link]').getAttribute('href')`),/\/orders\/[0-9a-f-]{36}$/);
                await assertButtonShapes(`daily report buttons ${width}`);
                if(process.env.EA_UI_SCREENSHOTS) {
                    await evaluate(`window.scrollTo({top:0,behavior:'instant'});new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))`);
                    writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`pickup-day-preview-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false})).data,'base64'));
                    writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`pickup-day-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
                }
            }
            const follow = async selector => {
                await evaluate(`window.__eaPreviousDocument=true;document.querySelector(${JSON.stringify(selector)}).focus()`);
                await key('Enter');
                for(let attempt=0;attempt<100;attempt++) {if(await evaluate(`!window.__eaPreviousDocument&&document.readyState==='complete'`))return;await new Promise(resolve=>setTimeout(resolve,30));}
                throw new Error('Calendar link failed: '+selector);
            };
            await follow('[aria-label="Vai a pagina 2"]');
            assert.match(await evaluate(`location.search`),/date=2026-10-17&page=2/);
            assert.equal(await evaluate(`document.querySelectorAll('.pickup-order-card').length`),20);
            await follow('[aria-label="Pagina successiva"]');
            assert.equal(await evaluate(`document.querySelectorAll('.pickup-order-card').length`),7);
            assert.equal(await evaluate(`document.querySelector('[data-pickup-day-total]').textContent`),'47');
            await follow('.page-back a');
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('month')`),'2026-10');
            await follow('[aria-label="Mese precedente"]');
            assert.match(await evaluate(`document.getElementById('calendar-month').textContent`),/Settembre 2026/);
            await follow('[aria-label="Mese successivo"]');
            await follow('[aria-label="Mese successivo"]');
            assert.match(await evaluate(`document.getElementById('calendar-month').textContent`),/Novembre 2026/);
            await follow('.pickup-calendar-navigation .btn');
            assert.match(await evaluate(`document.getElementById('calendar-month').textContent`),/Ottobre 2026/);
            await follow('[data-pickup-date="2026-10-18"]');
            assert.ok(await evaluate(`document.body.textContent.includes('Nessun ritiro da effettuare')`));
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('date')`),'2026-10-18');
            await navigate('pickups?month=2026-10');
            await evaluate(`window.__originalCalendar=document.querySelector('[data-pickup-date="2026-10-17"]');window.__originalCalendar.focus();window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'orders'}}))`);
            for(let attempt=0;attempt<100;attempt++){if(await evaluate(`!window.__originalCalendar.isConnected`))break;await new Promise(resolve=>setTimeout(resolve,30));}
            assert.ok(await evaluate(`!window.__originalCalendar.isConnected`));
            assert.equal(await evaluate(`document.activeElement.dataset.pickupDate`),'2026-10-17');
        });
        await scenario('customer dashboard preserves booking and slug links, readable themes and accessible responsive navigation', async () => {
            for (const width of [320,360,375,390,430,768,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('dashboard');
                for(let attempt=0;attempt<100;attempt++){if(await evaluate(`!!document.querySelector('.order-row')`))break;await new Promise(resolve=>setTimeout(resolve,20));}
                if(process.env.EA_UI_SCREENSHOTS)writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`portal-dashboard-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true})).data,'base64'));
                await assertButtonShapes(`portal buttons ${width}`);
                assert.equal(await evaluate(`document.querySelector('.brand-wordmark').getAttribute('aria-label')`),'EA-EXPRESS');
                assert.ok(await evaluate(`!!document.querySelector('.dashboard-actions [href="/pickups/new"]')`));
                assert.equal(await evaluate(`document.querySelector('.order-row').getAttribute('href')`),'/shipments/24369069-5868-49fd-b04c-3709fa3b5a81');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`), `portal overflow at ${width}`);
                assert.ok(await evaluate(`document.querySelector('.sidebar').getBoundingClientRect().width>0 || document.querySelector('.menu-toggle').getBoundingClientRect().width>0`));
                if(width<761){
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
        await scenario('manual delay controls keep role permissions, persistent history and correction forms readable at every requested width', async () => {
            for (const width of [320,360,375,390,430,768,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
                await navigate('delay-rider-unreported.html');
                assert.equal(await evaluate(`document.querySelector('.order-delay-panel button[type="submit"]').textContent.trim()`),'Segnala ritardo');
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`delay action overflow at ${width}`);
                await navigate('delay-rider-reported.html');
                assert.ok(await evaluate(`document.querySelector('.order-delay-badge').textContent.includes('In ritardo')`));
                assert.ok(await evaluate(`!document.querySelector('.order-delay-panel form')`),'duplicate rider action absent');
                assert.ok(await evaluate(`document.querySelector('.tracking-timeline').textContent.includes('Ritardo segnalato')`));
                await navigate('delay-admin-correction.html');
                await evaluate(`document.querySelector('.order-delay-panel summary').click();document.querySelector('#delay-correction').value='Correzione motivata e conservata nello storico'`);
                assert.ok(await evaluate(`document.querySelector('.order-delay-panel form').checkValidity()`));
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`delay correction overflow at ${width}`);
                await navigate('delay-admin-terminal.html');
                assert.ok(await evaluate(`document.querySelector('.order-delay-badge').textContent.includes('Ritardo segnalato')`),'completed shipment keeps historical label');
                assert.ok(await evaluate(`![...document.querySelectorAll('button')].some(node=>node.textContent.includes('Segnala ritardo'))`));
            }
        });
        await scenario('workspace registers keep scrolling headings, icon-only creation and touch controls at every requested width', async () => {
            const pages = ['workspace-pending.html','workspace-customers.html','workspace-rates.html','workspace-reports.html','workspace-orders-in-progress.html','workspace-orders-history.html','workspace-orders-incoming.html','workspace-pickups-grouped.html','workspace-settings-index.html','workspace-profile-edit.html','workspace-users-index.html','workspace-notifications-index.html','finance-payments.html'];
            for (const width of [320,360,375,390,430,768,1440]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});
                for (const page of pages) {
                    await navigate(page);
                    assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),`${page} overflow at ${width}`);
                    assert.ok(await evaluate(`[...document.querySelectorAll('.app-header,.page-heading,.dashboard-heading')].every(node=>!['sticky','fixed'].includes(getComputedStyle(node).position))`),`${page} persistent page title at ${width}`);
                    const invalid = await evaluate(`[...document.querySelectorAll('.action-add')].filter(node=>node.getClientRects().length).filter(node=>node.textContent.trim() || !node.getAttribute('aria-label') || node.getBoundingClientRect().width<44 || node.getBoundingClientRect().height<44).map(node=>({label:node.getAttribute('aria-label'),text:node.textContent.trim(),width:node.getBoundingClientRect().width}))`);
                    assert.deepEqual(invalid,[],`${page} creation action at ${width}`);
                    await evaluate(`window.scrollTo(0,450)`);
                    assert.ok(await evaluate(`document.querySelector('.app-header').getBoundingClientRect().top<0 || document.documentElement.scrollHeight<=innerHeight`),`${page} title scrolls at ${width}`);
                    if(process.env.EA_UI_SCREENSHOTS && [320,390,768,1440].includes(width)) writeFileSync(join(process.env.EA_UI_SCREENSHOTS,`revision-${page.replace('.html','')}-${width}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
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
        for (const width of [320, 360, 375, 390, 430, 768, 1440]) {
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
                    const overlap = await evaluate(`(()=>{const row=document.querySelector('.recipient-identity');const name=row?.querySelector('span').getBoundingClientRect();const badge=row?.querySelector('.recipient-status').getBoundingClientRect();return name&&badge&&name.left<badge.right&&name.right>badge.left&&name.top<badge.bottom&&name.bottom>badge.top})()`);
                    if (overlap) failures.push(`${file} recipient badge overlaps at ${width}px`);
                    const below=await evaluate(`(()=>{const row=document.querySelector('.recipient-identity');return row.querySelector('.recipient-status').getBoundingClientRect().top >= row.querySelector('.recipient-name').getBoundingClientRect().bottom-1})()`);
                    if (!below) failures.push(`${file} recipient badge is not below name at ${width}px`);
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
                await evaluate(`window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
                await new Promise(resolve=>setTimeout(resolve,50));
                writeFileSync(join(temporary,'ajax-order.html'),JSON.parse(readFileSync(join(temporary,'ajax-picked_up.json'))).html);
                await evaluate(`for(let i=0;i<8;i++)window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'order'}}))`);
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
                assert.ok(await evaluate(`const a=document.querySelector('.booking-content [for="category"]').getBoundingClientRect(),b=document.querySelector('.booking-content [for="urgency"]').getBoundingClientRect();Math.abs(a.top-b.top)<3`));
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
Illuminate\\Http\\Request::enableHttpMethodParameterOverride();
$app->handleRequest(Illuminate\\Http\\Request::capture());
`);
            phpServer=spawn('php',['-S',`127.0.0.1:${port}`,'-t',resolve('public'),router],{env:{...process.env,APP_ENV:'testing',APP_URL:phpOrigin,DB_CONNECTION:'sqlite',DB_DATABASE:join(temporary,'browser.sqlite'),DB_URL:'',SESSION_DRIVER:'file',CACHE_STORE:'array',MAIL_MAILER:'array',QUEUE_CONNECTION:'sync',BROADCAST_CONNECTION:'null'},stdio:['ignore','ignore','pipe']});
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
                await evaluate(`document.querySelector('form').requestSubmit()`);await waitFor(`location.pathname==='/dashboard'`);
                await send('Page.navigate',{url:origin+'/pickups/new'});await waitFor(`document.querySelector('[name="store_name"]')`);
                assert.equal(await evaluate(`document.querySelector('[name="sender_type"]:checked').value`),'online_shop');
                assert.equal(await evaluate(`document.querySelector('[name="store_name"]').value`),'Shop verifica browser');
                const tomorrow=new Date(Date.now()+2*86400000).toISOString().slice(0,10);
                await fill({pickup_address:'Via Roma',pickup_street_number:'10',pickup_postal_code:'80121',pickup_city:'Napoli',delivery_address:'Via Roma',delivery_city:'Caserta',delivery_street_number:'20',delivery_postal_code:'81100',recipient_name:'Destinatario browser',recipient_phone:'+390000000000',pickup_date:tomorrow,parcel_value:'25.00',content_description:'Ricambi automobile'});
                await evaluate(`document.querySelector('[name="pickup_from"]').nextElementSibling.click()`);
                await evaluate(`const inputs=document.querySelectorAll('.picker-time input');inputs[0].value='9';inputs[1].value='0';inputs[1].dispatchEvent(new Event('input'));document.querySelector('.picker-time .action-confirm').click()`);
                assert.equal(await evaluate(`document.querySelector('[name="pickup_from"]').value`),'09:00');
                await waitFor(`document.querySelector('.shipping-quote-summary[data-state="ready"]')`);
                assert.match(await evaluate(`document.querySelector('.shipping-quote-summary').textContent`),/8,50/);
                await evaluate(`document.querySelector('.order-form').requestSubmit()`);await waitFor(`document.querySelector('.checkout-review')`);
                await evaluate(`document.querySelector('.checkout-actions .secondary').click()`);
                assert.equal(await evaluate(`document.querySelector('[name="content_description"]').value`),'Ricambi automobile');
                await evaluate(`document.querySelector('.order-form').requestSubmit()`);await waitFor(`document.querySelector('.checkout-review')`);
                await evaluate(`document.querySelector('.checkout-continue').click()`);await waitFor(`/^\\/pickups\\/[0-9a-f-]{36}$/.test(location.pathname)`);
                const orderId=await evaluate(`location.pathname.split('/').at(-1)`);
                async function staff(role){await send('Network.clearBrowserCookies');await send('Page.navigate',{url:origin+'/login'});await waitFor(`document.querySelector('[data-auth-form]')`);await fill({email:role+'@example.test',password:'password'});await evaluate(`fetch(document.querySelector('[data-auth-form]').action,{method:'POST',body:new FormData(document.querySelector('[data-auth-form]')),credentials:'same-origin',redirect:'manual'}).then(()=>true)`);}
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
