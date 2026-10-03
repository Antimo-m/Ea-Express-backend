import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, existsSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, extname } from 'node:path';

const chrome = process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

test('EA pickers and operational pages: keyboard, validation, long content, contrast and responsive', { skip: !existsSync(chrome), timeout: 120000 }, async t => {
    const temporary = mkdtempSync(join(tmpdir(), 'ea-ui-'));
    let browser, server, socket;
    let zoneFeedRequests = 0;
    try {
        execFileSync('php', ['artisan', 'test', '--compact', 'tests/Feature/WorkspacePresentationTest.php'], { env: { ...process.env, EA_UI_FIXTURE_DIR: temporary }, stdio: 'pipe' });
        const manifest = JSON.parse(readFileSync('public/build/manifest.json'));
        const styles = ['resources/css/app.css', 'resources/css/experience.css'].map(key => `<link rel="stylesheet" href="/build/${manifest[key].file}">`).join('');
        const fixture = `<!doctype html><html lang="it"><head><meta name="viewport" content="width=device-width,initial-scale=1">${styles}</head><body><main style="padding:20px;max-width:800px;margin:auto">
          <form id="fixture"><label for="choice">Rider disponibile</label><select id="choice" name="rider" required><option value="">Seleziona…</option>${Array.from({ length: 15 }, (_, index) => `<option value="${index}" ${index === 3 ? 'disabled' : ''}>${index === 12 ? 'Pierluigi Di Stefano' : `Rider ${index}`}</option>`).join('')}</select>
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
            if (url.pathname === '/pending') { response.setHeader('Content-Type', 'text/html'); response.end(readFileSync(join(portal, 'dist/index.html'))); return; }
            if (url.pathname === '/api/v1/customer/auth/me') { response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({user:{id:1,name:'Centro Distribuzione Elettrodomestici Napoli Nord',email:'test@example.test',role:'customer',sender_type:'business'}})); return; }
            if (url.pathname === '/api/v1/customer/pending') { response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({data:[],totals:{incoming:12550,outgoing:0},meta:{current_page:1,last_page:1,total:0}})); return; }
            if (/^\/orders\/\d+\/location$/.test(url.pathname)) { response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({order_id:Number(url.pathname.split('/')[2]),state:'ready',eligible:false,status_label:'In consegna',rider:{name:'Amministratore operativo EA Express'},location:null,pickup:{point:null},delivery:{point:null}})); return; }
            if (url.pathname === '/rider-operations/feed') { zoneFeedRequests++; response.setHeader('Content-Type','application/json'); response.end(readFileSync(join(temporary,'rider-feed.json'))); return; }
            if (url.pathname === '/fixture') { response.setHeader('Content-Type', 'text/html'); response.end(fixture); return; }
            const file = url.pathname.startsWith('/assets/') ? resolve(portal, 'dist', `.${url.pathname}`) : url.pathname.startsWith('/build/') ? resolve('public', `.${url.pathname}`) : ['/brand.svg', '/favicon.svg'].includes(url.pathname) ? resolve('public', `.${url.pathname}`) : url.pathname === '/resources/js/form-popovers.js' ? resolve('resources/js/form-popovers.js') : join(temporary, url.pathname.slice(1));
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
        const origin = `http://127.0.0.1:${server.address().port}`;
        browser = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${join(temporary, 'chrome')}`, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
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
                if (await evaluate(`document.readyState === 'complete' && (location.pathname === '/fixture' ? window.ready : location.pathname === '/pending' ? document.querySelector('.ea-picker-trigger') : document.querySelector('.ea-picker-trigger') || !document.querySelector('select,input[type="date"],input[type="month"]'))`)) return;
                await new Promise(resolve => setTimeout(resolve, 30));
            }
            throw new Error(`Page did not initialize: ${path}`);
        };
        const key = async (key, code = key) => { await send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: ({ Enter: 13, Escape: 27, Tab: 9, ArrowDown: 40, ArrowUp: 38, ArrowLeft: 37, ArrowRight: 39 })[key] }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: ({ Enter: 13, Escape: 27, Tab: 9, ArrowDown: 40, ArrowUp: 38, ArrowLeft: 37, ArrowRight: 39 })[key] }); };
        const clickPicker = id => evaluate(`document.getElementById(${JSON.stringify(id)}).nextElementSibling.click()`);
        await navigate('fixture');
        await t.test('native controls are hidden and keyboard searchable select sends original payload', async () => {
            assert.equal(await evaluate(`getComputedStyle(document.getElementById('choice')).display`), 'none');
            await clickPicker('choice');
            await evaluate(`const search=document.querySelector('.ea-picker-popup input'); search.value='Pierluigi'; search.dispatchEvent(new Event('input'));`);
            await key('ArrowDown'); await key('Enter');
            assert.equal(await evaluate(`document.getElementById('choice').value`), '12');
            assert.equal(await evaluate(`new FormData(document.getElementById('fixture')).get('rider')`), '12');
            assert.match(await evaluate(`document.activeElement.getAttribute('aria-label')`), /Pierluigi/);
            await clickPicker('choice'); await key('Escape');
            assert.equal(await evaluate(`document.querySelector('.ea-picker-popup')`), null);
        });
        await t.test('calendar bounds, month navigation and seven-day keyboard movement', async () => {
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
        await t.test('time constraints reject out of range and off-step values', async () => {
            await clickPicker('time');
            const setClock = (hours, minutes) => evaluate(`const inputs=document.querySelectorAll('.time-popover-fields input'); inputs[0].value=${JSON.stringify(hours)}; inputs[1].value=${JSON.stringify(minutes)}; inputs[1].dispatchEvent(new Event('input'));`);
            await setClock('13', '0'); assert.equal(await evaluate(`document.querySelector('.picker-content > button').disabled`), true);
            await setClock('10', '7'); assert.equal(await evaluate(`document.querySelector('.picker-content > button').disabled`), true);
            await setClock('10', '45'); await evaluate(`document.querySelector('.picker-content > button').click()`);
            assert.equal(await evaluate(`document.getElementById('time').value`), '10:45');
        });
        await t.test('datetime validates date plus time and month picker has range bounds', async () => {
            await clickPicker('datetime');
            await evaluate(`const inputs=document.querySelectorAll('.time-popover-fields input'); inputs[0].value='9'; inputs[1].value='30'; inputs[0].dispatchEvent(new Event('input'));`);
            assert.equal(await evaluate(`document.querySelector('.picker-content > button').disabled`), true);
            await evaluate(`const input=document.querySelector('.time-popover-fields input'); input.value='11'; input.dispatchEvent(new Event('input'));document.querySelector('.picker-content > button').click();`);
            assert.equal(await evaluate(`document.getElementById('datetime').value`), '2026-10-12T11:30');
            await clickPicker('month'); assert.equal(await evaluate(`document.querySelector('.month-grid button').disabled`), true);
            await evaluate(`document.querySelectorAll('.month-grid button')[10].click()`);
            assert.equal(await evaluate(`document.getElementById('month').value`), '2026-11');
        });
        await t.test('free text suggestions, dynamic controls, disabled and reset remain synchronized', async () => {
            await clickPicker('business'); await evaluate(`const input=document.querySelector('.picker-content input'); input.value='Artigianato'; input.dispatchEvent(new Event('input'));`); await key('Enter');
            assert.equal(await evaluate(`document.getElementById('business').value`), 'Artigianato');
            await evaluate(`document.getElementById('fixture').insertAdjacentHTML('beforeend','<select id="dynamic"><option>Nuovo filtro</option></select>')`);
            assert.equal(await evaluate(`Boolean(document.getElementById('dynamic').nextElementSibling?.matches('.ea-picker-trigger'))`), true);
            await evaluate(`document.getElementById('dynamic').disabled=true`);
            assert.equal(await evaluate(`document.getElementById('dynamic').nextElementSibling.disabled`), true);
            await evaluate(`document.getElementById('fixture').reset()`);
            assert.match(await evaluate(`document.getElementById('date').nextElementSibling.textContent`), /12\/10\/2026/);
        });
        await t.test('open dropdown follows live options, preserves search and focus, and closes when loading', async () => {
            await clickPicker('choice');
            await evaluate(`const search=document.querySelector('.picker-content input');search.value='Pierluigi';search.dispatchEvent(new Event('input'));document.getElementById('choice').options[13].label='Pierluigi Di Stefano — turno pomeriggio'`);
            assert.match(await evaluate(`document.querySelector('[role="option"]').textContent`), /turno pomeriggio/);
            assert.equal(await evaluate(`document.querySelector('.picker-content input').value`), 'Pierluigi');
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
        await t.test('popup enters top layer inside modal and Escape preserves parent', async () => {
            await evaluate(`document.getElementById('modal').showModal()`); await clickPicker('nested');
            assert.equal(await evaluate(`document.querySelector('.ea-picker-popup').matches(':popover-open')`), true);
            assert.equal(await evaluate(`document.querySelector('.ea-picker-popup').closest('dialog').id`), 'modal');
            await key('Escape'); assert.equal(await evaluate(`document.getElementById('modal').open`), true);
            await evaluate(`document.getElementById('modal').close()`);
        });
        await t.test('mobile sheet fits 375px and traps focus', async () => {
            await send('Emulation.setDeviceMetricsOverride', { width: 375, height: 812, deviceScaleFactor: 1, mobile: true });
            await clickPicker('date');
            const rect = await evaluate(`(()=>{const r=document.querySelector('.ea-picker-popup').getBoundingClientRect();return {left:r.left,right:r.right,bottom:r.bottom,width:innerWidth,height:innerHeight}})()`);
            assert.ok(rect.left >= 0 && rect.right <= rect.width && rect.bottom <= rect.height, JSON.stringify(rect));
            await evaluate(`document.querySelector('.ea-picker-popup button:last-child').focus()`); await key('Tab');
            assert.equal(await evaluate(`document.querySelector('.ea-picker-popup').contains(document.activeElement)`), true);
            await key('Escape');
        });
        await t.test('React controlled selects preserve onChange, URL filters and labels after rerender', async () => {
            await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
            await navigate('pending');
            await evaluate(`document.querySelector('select').nextElementSibling.click()`);
            await evaluate(`Array.from(document.querySelectorAll('[role="option"]')).find(node=>node.textContent.startsWith('Storico')).click()`);
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('view')`), 'history');
            assert.equal(await evaluate(`document.querySelector('select').value`), 'history');
            assert.match(await evaluate(`document.querySelector('select').nextElementSibling.textContent`), /Storico/);
            await evaluate(`document.querySelectorAll('select')[1].nextElementSibling.click()`);
            await key('ArrowDown');
            await key('Enter');
            await evaluate(`new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)))`);
            assert.equal(await evaluate(`document.querySelectorAll('select')[1].value`), 'incoming');
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('direction')`), 'incoming');
        });
        await t.test('reports use compact orange controls, rate editor groups history, and login supports password visibility', async () => {
            await navigate('reports.html');
            assert.ok(await evaluate(`document.querySelector('[name="month"]').nextElementSibling.getBoundingClientRect().width < 240`));
            assert.equal(await evaluate(`getComputedStyle(document.querySelector('.filter-actions .icon-button')).backgroundColor`), 'rgb(255, 150, 63)');
            await navigate('rates.html');
            await evaluate(`document.querySelector('[data-rate-edit]').click()`);
            assert.equal(await evaluate(`document.getElementById('rate-editor').open`), true);
            assert.equal(await evaluate(`document.querySelector('[data-rate-save]').dataset.tooltip`), 'Salva tariffa');
            assert.equal(await evaluate(`document.querySelector('[data-rate-form] [name="max_weight_kg"]')`), null);
            assert.equal(await evaluate(`document.querySelector('[data-rate-form] [name="max_dimension_cm"]')`), null);
            assert.equal(await evaluate(`document.querySelector('[data-editor-history]').parentElement.classList.contains('rate-timing-actions')`), true);
            assert.equal(await evaluate(`document.querySelector('#rate-editor .modal-actions [data-dialog-close]').textContent.trim()`), '');
            assert.equal(await evaluate(`document.querySelector('#rate-editor .modal-actions [data-dialog-close]').getAttribute('aria-label')`), 'Torna indietro');
            assert.ok(await evaluate(`document.querySelector('#rate-editor .modal-actions [data-dialog-close]').getBoundingClientRect().height <= 36`));
            await send('Emulation.setDeviceMetricsOverride',{width:375,height:1000,deviceScaleFactor:1,mobile:true});
            assert.ok(await evaluate(`document.querySelector('#rate-editor .modal-actions [data-dialog-close]').getBoundingClientRect().width <= 36`));
            await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
            assert.ok(await evaluate(`document.querySelector('.sidebar-create').getBoundingClientRect().height <= 36`));
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
        await t.test('login is centered and rider zones, map and dates work on desktop tablet and mobile', async () => {
            for (const width of [375,768,1024,1280,1440]) {
                await send('Emulation.setDeviceMetricsOverride', {width,height:1000,deviceScaleFactor:1,mobile:width<600});
                await navigate('login.html');
                assert.ok(await evaluate(`(()=>{const form=document.querySelector('[data-auth-form]').getBoundingClientRect(),button=document.querySelector('.auth-submit').getBoundingClientRect();return Math.abs((button.left+button.width/2)-(form.left+form.width/2))<2})()`), `login centered at ${width}`);
                await navigate('orders_in-progress.html');
                assert.ok(await evaluate(`Array.from(document.querySelectorAll('.filter-field-small input')).every(input=>input.getBoundingClientRect().height<=36)`));
                await navigate('rider-operations.html');
                assert.ok(await evaluate(`document.querySelectorAll('.rider-zone').length>=9`));
                assert.equal(await evaluate(`document.querySelector('[name="date"]').nextElementSibling.tagName`), 'BUTTON');
                assert.ok(await evaluate(`document.querySelector('.map-rider-pin')?.href.includes('/orders/')`));
                assert.equal(await evaluate(`document.querySelectorAll('.rider-operational-card[data-rider-state="live"]').length`), 1);
                assert.ok(await evaluate(`document.querySelector('.map-zone').textContent.includes('Zona operativa')`));
                assert.equal(await evaluate(`document.querySelector('[data-rider-grid]').hidden`),false);
                await evaluate(`document.querySelector('[data-operations-view="zone"]').click()`);
                assert.equal(await evaluate(`document.querySelector('[data-zone-grid]').hidden`),false);
                if(width===375) assert.equal(await evaluate(`getComputedStyle(document.querySelector('.rider-zone-grid')).gridTemplateColumns.split(' ').length`),1);
                if(width===1440) {
                    const originalFeed=readFileSync(join(temporary,'rider-feed.json'));
                    const changed=JSON.parse(originalFeed); changed.zones[0].name='Zona aggiornata realtime';
                    writeFileSync(join(temporary,'rider-feed.json'),JSON.stringify(changed));
                    const requestsBefore=zoneFeedRequests;
                    await evaluate(`for(let i=0;i<8;i++)window.dispatchEvent(new CustomEvent('ea:rider-location',{detail:{order_id:i+1}}))`);
                    await new Promise(resolve=>setTimeout(resolve,2200));
                    assert.equal(zoneFeedRequests-requestsBefore,1,'GPS burst is combined into one feed request');
                    assert.equal(await evaluate(`document.querySelector('.rider-zone h2').textContent`),'Zona aggiornata realtime');
                    writeFileSync(join(temporary,'rider-feed.json'),originalFeed);
                }
                await navigate('rider-history.html');
                const historicalRequests=zoneFeedRequests;
                await evaluate(`window.dispatchEvent(new CustomEvent('ea:rider-location',{detail:{order_id:1}}))`);
                await new Promise(resolve=>setTimeout(resolve,100));
                assert.equal(zoneFeedRequests,historicalRequests);

                assert.equal(await evaluate(`document.querySelector('[data-operational-map]')`),null);
                assert.ok(await evaluate(`document.querySelector('.rider-zone-person .icon-button').href.includes('date=')`));
                await navigate('rider-detail.html');
                assert.ok(await evaluate(`document.querySelector('.rider-activity-timeline time').textContent.includes('10:30')`));
            }
        });
        await t.test('GPS badge is compact at top left and dark feedback is green or red', async () => {
            await navigate('orders_1.html');
            assert.equal(await evaluate(`document.querySelector('[data-gps-badge]').textContent.trim()`),'GPS');
            assert.equal(await evaluate(`document.querySelector('[data-rider-gps]').dataset.gpsState`),'disabled');
            assert.equal(await evaluate(`getComputedStyle(document.querySelector('.gps-live-dot')).backgroundColor`),'rgb(255, 146, 155)');
            await evaluate(`document.querySelector('[data-rider-gps]').dataset.gpsState='active'`);
            assert.equal(await evaluate(`getComputedStyle(document.querySelector('.gps-live-dot')).backgroundColor`),'rgb(112, 215, 168)');
            await evaluate(`document.querySelector('[data-rider-gps]').dataset.gpsState='disabled';for(const kind of ['success','danger','warning']){const box=document.createElement('div');box.className='alert alert-'+kind;box.dataset.fixtureFeedback=kind;box.textContent='Esito operazione';document.querySelector('main').prepend(box)}`);
            assert.equal(await evaluate(`getComputedStyle(document.querySelector('[data-fixture-feedback="success"]')).backgroundColor`),'rgb(18, 61, 44)');
            for(const kind of ['danger','warning']) assert.equal(await evaluate(`getComputedStyle(document.querySelector('[data-fixture-feedback="${kind}"]')).backgroundColor`),'rgb(76, 29, 37)');
            await send('Emulation.setDeviceMetricsOverride',{width:375,height:1000,deviceScaleFactor:1,mobile:true});
            assert.ok(await evaluate(`(()=>{const badge=document.querySelector('[data-gps-badge]').getBoundingClientRect();return badge.left<=20&&badge.top<150&&badge.width<90})()`));
            await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        });
        await t.test('searchable selectors remain compact with 5 50 and 200 accounts', async () => {
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
        await t.test('rider view stays usable with 3 10 and 20 riders at four viewport sizes', async () => {
            const originalFeed=readFileSync(join(temporary,'rider-feed.json'));
            const originalPage=readFileSync(join(temporary,'rider-operations.html'),'utf8');
            try {
                for (const count of [3,10,20]) {
                    const data=JSON.parse(originalFeed);data.riders=data.riders.filter(rider=>rider.total_count>0).slice(0,count);
                    writeFileSync(join(temporary,'rider-feed.json'),JSON.stringify(data));
                    writeFileSync(join(temporary,'rider-scale.html'),originalPage.replace(/(<script[^>]*data-zone-initial[^>]*>)[\s\S]*?(<\/script>)/,(_,start,end)=>start+JSON.stringify(data)+end));
                    for (const width of [375,768,1280,1440]) {
                        await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                        await navigate('rider-scale.html');
                        assert.equal(await evaluate(`document.querySelectorAll('.rider-operational-card').length`),count);
                        assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth`));
                        assert.ok(await evaluate(`Array.from(document.querySelectorAll('.rider-operational-card')).every(card=>card.getBoundingClientRect().width<=innerWidth)`));
                        await evaluate(`document.querySelector('[data-operations-view="zone"]').click()`);
                        assert.equal(await evaluate(`document.querySelector('[data-zone-grid]').hidden`),false);
                    }
                }
            } finally {writeFileSync(join(temporary,'rider-feed.json'),originalFeed);}
        });
        await t.test('reporting has four adjacent period presets and one custom date range', async () => {
            for (const page of ['stores.html','balance.html']) {
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
        await t.test('person detail edits and removes individual incidents in accessible dialogs', async () => {
            await navigate('recipient-incidents_1.html');
            await evaluate(`document.querySelector('.recipient-detail-card .card-heading [data-dialog-open]').focus();document.querySelector('.recipient-detail-card .card-heading [data-dialog-open]').click()`);
            assert.equal(await evaluate(`document.querySelector('dialog[id^="person-correction-"]').open`), true);
            assert.equal(await evaluate(`document.activeElement.name`), 'recipient_name');
            await evaluate(`document.querySelector('dialog[open] [name="correction_reason"]').value='Verifica dati del destinatario';document.querySelector('dialog[open] [name="action"][value="dismiss"]').click()`);
            assert.equal(await evaluate(`document.getElementById('action-confirmation').open`), true);
            await key('Escape'); await key('Escape');
            assert.equal(await evaluate(`document.activeElement.getAttribute('aria-label')`), 'Modifica dati o rimuovi segnalazione');
        });
        await t.test('pickup action group submits original form, retains notes and shows cancellation dialog', async () => {
            const orderFile = readdirSync(temporary).filter(file=>/^orders_\d+\.html$/.test(file)).find(file=>readFileSync(join(temporary,file),'utf8').includes('aria-label="Conferma pacco ritirato"'));
            assert.ok(orderFile);
            await navigate(orderFile);
            assert.equal(await evaluate(`document.querySelector('.workflow-primary-actions [type="submit"]').form.querySelector('[name="status"]').value`), 'picked_up');
            await evaluate(`document.querySelector('[data-dialog-open^="transition-note-"]').click();document.querySelector('#primary-note').value='Nota interna conservata'`);
            assert.equal(await evaluate(`new FormData(document.querySelector('[id^="primary-transition-"]')).get('note')`), 'Nota interna conservata');
            await key('Escape');
            await evaluate(`document.querySelector('.workflow-primary-actions [aria-label="Annulla spedizione"]').click()`);
            assert.equal(await evaluate(`document.querySelector('dialog[id$="-cancelled"]').open`), true);
            assert.equal(await evaluate(`document.querySelector('dialog[id$="-cancelled"] [name="cancellation_reason"]').value`), 'other');
            await key('Escape');
        });
        await t.test('filters keep their intended hierarchy, identical heights and mobile apply/cancel behavior', async () => {
            const pages = ['orders_incoming.html','orders_in-progress.html','orders_history.html','pickups.html','balance.html','stores.html','reports.html','rates.html','pending.html','recipient-incidents.html','rider-operations.html','rider-detail.html'];
            for (const width of [1440,1280,1024,768,375]) {
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<600});
                for (const page of pages) {
                    await navigate(page);
                    const geometry = await evaluate(`(()=>{const toolbar=document.querySelector('[data-filter-toolbar]');return {controls:[...toolbar.querySelectorAll('.ea-picker-trigger,.form-control:not(.ea-picker-source),.filter-actions .icon-button,.filter-more:not([hidden]),[data-custom-period]')].filter(node=>node.getClientRects().length).map(node=>({height:node.getBoundingClientRect().height,left:node.getBoundingClientRect().left,right:node.getBoundingClientRect().right})),search:toolbar.querySelector('.filter-search')?.getBoundingClientRect().toJSON(),primary:toolbar.querySelector('.filter-primary').getBoundingClientRect().toJSON(),reset:toolbar.querySelector('.filter-reset').getBoundingClientRect().toJSON()}})()`);
                    assert.ok(geometry.controls.every(control=>Math.abs(control.height-38)<1),`${page} at ${width}: ${JSON.stringify(geometry.controls)}`);
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
                        if(oldValue!==undefined) {
                            await evaluate(`const select=document.querySelector('[data-filter-dialog-fields] select');select.selectedIndex=1;select.dispatchEvent(new Event('change',{bubbles:true}))`);
                            await key('Escape');
                            assert.equal(await evaluate(`document.querySelector('.filter-primary select,.filter-secondary select')?.value`),oldValue);
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
        await t.test('active secondary filters show counts and chips remove only their own query and pagination', async () => {
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
            await navigate('statistics-custom.html?period=custom&from=2026-01-01&to=2026-01-31&status=delivered&page=2');
            const rangeUrl=new URL(await evaluate(`document.querySelector('.active-filter-chip[aria-label^="Rimuovi filtro Periodo"]').href`));
            for(const name of ['period','from','to','page']) assert.equal(rangeUrl.searchParams.get(name),null);
            assert.equal(rangeUrl.searchParams.get('status'),'delivered');
            await navigate('statistics-year.html?period=year&year=2025&status=delivered');
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
        const files = readdirSync(temporary).filter(file => file.endsWith('.html'));
        const failures = [];
        for (const width of [375, 768, 1024, 1280, 1440]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 600 });
            for (const file of files) {
                await navigate(file);
                const overflow = await evaluate(`document.documentElement.scrollWidth > innerWidth + 1`);
                if (overflow) failures.push(`${file} overflows at ${width}px: ${JSON.stringify(await evaluate(`Array.from(document.querySelectorAll('main *')).filter(n=>n.getClientRects().length&&n.getBoundingClientRect().right>innerWidth+1).slice(0,8).map(n=>({tag:n.tagName,class:n.className,right:n.getBoundingClientRect().right}))`))}`);
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
        await t.test('rendered pages and badges fit mobile, tablet, laptop and desktop with realistic data', () => assert.deepEqual(failures, []));
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
        await t.test('at least 95% of sampled text meets contrast thresholds in both themes', () => {
            for(const [theme,result] of Object.entries(contrastResults)) {
                t.diagnostic(`${theme}: ${result.checked-result.failed.length}/${result.checked} text samples meet contrast`);
                t.diagnostic(JSON.stringify([...new Map(result.failed.map(item => [item.class + item.text, item])).values()].slice(0,25))); 
                assert.ok(result.checked>100);
                assert.ok(result.failed.length/result.checked <= .05, `${theme}: ${JSON.stringify(result.failed.slice(0,35))}`);
            }
        });
        // Keep screenshots outside the repository for inspection.
        const screenshots = process.env.EA_UI_SCREENSHOTS;
        if (screenshots) {
            for (const width of [375,768,1024,1280,1440]) {
            await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width<600 });
            for (const file of ['orders_incoming.html', 'orders_history.html', 'pickups.html', 'stores.html', 'rates.html', 'orders_in-progress.html', 'orders_1.html', 'orders_2.html', 'balance.html', 'pending.html', 'orders_create.html', 'login.html', 'welcome.html', 'recipient-incidents.html', 'recipient-incidents_1.html', 'reports.html', 'rider-operations.html', 'rider-history.html', 'rider-detail.html']) {
                await navigate(file);
                writeFileSync(join(screenshots, file.replace('.html', `-${width}.png`)), Buffer.from((await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })).data, 'base64'));
            }
            const filterPages = ['orders_incoming','orders_in-progress','orders_history','pickups','balance','stores','reports','rates','pending','recipient-incidents','rider-operations','rider-detail'];
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
            await evaluate(`document.getElementById('order-tracking').scrollIntoView({behavior:'instant'})`);
            await new Promise(resolve=>setTimeout(resolve,200));
            writeFileSync(join(screenshots, `tracking-${width}.png`), Buffer.from((await send('Page.captureScreenshot', {format:'png',captureBeyondViewport:false})).data,'base64'));
            }
        }
    } finally {
        socket?.close();
        if (browser && browser.exitCode === null) await new Promise(resolve => { browser.once('exit', resolve); browser.kill('SIGTERM'); });
        if (server) await new Promise(resolve => server.close(resolve));
        rmSync(temporary, { recursive: true, force: true, maxRetries: 3 });
    }
});
