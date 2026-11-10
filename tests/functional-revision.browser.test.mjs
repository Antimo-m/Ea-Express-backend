import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve, extname } from 'node:path';
import { tmpdir } from 'node:os';

const chrome = process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
test('Revisione funzionale: mobile, cassa touch, validazione contestuale e stampa A6', { skip: !existsSync(chrome), timeout: 180000 }, async t => {
    const temporary = mkdtempSync(join(tmpdir(), 'ea-functional-revision-'));
    let browser, socket, rejectWrite = true, writes = 0;
    try {
        execFileSync('php', ['-d', 'memory_limit=512M', 'vendor/bin/phpunit', 'tests/Feature/FunctionalRevisionTest.php', 'tests/Feature/WorkspacePresentationTest.php', 'tests/Feature/BalanceExperienceTest.php', 'tests/Feature/ShipmentCardInformationTest.php'], { env: { ...process.env, XDEBUG_MODE: 'off', EA_UI_FIXTURE_DIR: temporary }, stdio: 'pipe' });
    } catch (error) { throw new Error(error.stdout?.toString() || error.message, { cause: error }); }
    const markup = file => readFileSync(join(temporary, file), 'utf8').replace(/https?:\/\/localhost(?::\d+)?/g, '').replace(/https?:\/\/[^/"\s]+\/build\//g, '/build/');
    const server = createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (request.method === 'POST' && /^\/(expenses|movements)(\/|$)/.test(url.pathname)) {
            request.resume(); request.on('end', () => {
                writes++;
                response.setHeader('Content-Type', 'application/json');
                if (rejectWrite) { rejectWrite = false; response.statusCode = 422; response.end(JSON.stringify({ errors: { amount: ['Importo non valido: verifica il valore inserito.'] } })); }
                else { response.statusCode = 201; response.end(JSON.stringify({ message: 'Operazione registrata.' })); }
            }); return;
        }
        const fixture = url.pathname.replace(/^\//, '') || 'dashboard.html';
        if (/^[a-z0-9_-]+\.html$/.test(fixture) && existsSync(join(temporary, fixture))) {
            response.setHeader('Content-Type', 'text/html'); response.end(markup(fixture)); return;
        }
        if (url.pathname.startsWith('/resources/js/')) {
            const file = resolve('.' + url.pathname + (extname(url.pathname) ? '' : '.js'));
            if (file.startsWith(resolve('resources/js') + '/') && existsSync(file)) { response.setHeader('Content-Type', 'text/javascript'); response.end(readFileSync(file)); return; }
        }
        const file = resolve('public', '.' + url.pathname);
        if (file.startsWith(resolve('public') + '/') && existsSync(file) && extname(file)) {
            response.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.woff': 'font/woff' })[extname(file)] || 'application/octet-stream');
            response.end(readFileSync(file)); return;
        }
        response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({ enabled: false, data: [], groups: [], unread: 0 }));
    });
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const origin = 'http://127.0.0.1:' + server.address().port;
        browser = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', '--user-data-dir=' + join(temporary, 'chrome'), 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
        const endpoint = await new Promise((resolve, reject) => {
            let output = ''; const timer = setTimeout(() => reject(new Error(output)), 15000);
            browser.stderr.on('data', chunk => { output += chunk; const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timer); resolve(match[1]); } });
            browser.once('exit', code => { clearTimeout(timer); reject(new Error('Chrome ' + code)); });
        });
        const pages = await (await fetch('http://127.0.0.1:' + new URL(endpoint).port + '/json/list')).json();
        socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
        await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
        let sequence = 0; const pending = new Map(); const runtimeErrors = [];
        socket.addEventListener('message', ({ data }) => { const result = JSON.parse(data), handler = pending.get(result.id); if (result.method === 'Runtime.exceptionThrown') runtimeErrors.push(result.params.exceptionDetails.exception?.description || result.params.exceptionDetails.text); if (handler) { pending.delete(result.id); result.error ? handler.reject(new Error(result.error.message)) : handler.resolve(result.result); } });
        const send = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence, timer = setTimeout(() => reject(new Error('Chrome timeout ' + method)), 15000); pending.set(id, { resolve: value => { clearTimeout(timer); resolve(value); }, reject: error => { clearTimeout(timer); reject(error); } }); socket.send(JSON.stringify({ id, method, params })); });
        const evaluate = async source => { const result = await send('Runtime.evaluate', { expression: '(()=>{' + source + '})()', returnByValue: true, awaitPromise: true }); if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text); return result.result.value; };
        const waitFor = async expression => { for (let i = 0; i < 150; i++) { if (await evaluate('return Boolean(' + expression + ');')) return; await new Promise(resolve => setTimeout(resolve, 30)); } throw new Error('Not ready: ' + expression + ' ' + await evaluate('return document.body.innerText.slice(-500);')); };
        const navigate = async file => { await evaluate('window.__previous=true;'); await send('Page.navigate', { url: origin + '/' + file }); await waitFor('!window.__previous && document.readyState==="complete"'); await evaluate('return document.fonts.ready.then(()=>true);'); };
        await send('Runtime.enable');
        await send('Emulation.setFocusEmulationEnabled', { enabled: true });
        let currentWidth;
        const viewport = async width => { currentWidth = width; await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 600 }); await send('Emulation.setTouchEmulationEnabled', { enabled: width < 600 }); };
        const click = async selector => {
            const point = await evaluate(`const node=[...document.querySelectorAll(${JSON.stringify(selector)})].find(node=>node.getClientRects().length);if(!node)throw new Error('Control missing: '+${JSON.stringify(selector)});node.scrollIntoView({block:'center',behavior:'instant'});const r=node.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};`);
            if (currentWidth < 600) { await send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [point] }); await send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); }
            else { await send('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button: 'left', clickCount: 1 }); await send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button: 'left', clickCount: 1 }); }
        };
        const checkBounds = async label => {
            const result = await evaluate(`return {width:innerWidth,scroll:document.documentElement.scrollWidth,hidden:!document.querySelector('dialog[open]')&&(getComputedStyle(document.documentElement).overflowX==='hidden'||getComputedStyle(document.body).overflowX==='hidden'),overflow:[...document.querySelectorAll('main *')].filter(node=>{const r=node.getBoundingClientRect();return r.width&&r.right>innerWidth+1&&getComputedStyle(node).position!=='fixed';}).slice(0,6).map(node=>node.className)};`);
            assert.ok(!result.hidden && result.scroll <= result.width + 1, label + ' ' + JSON.stringify(result));
        };
        const setValue = (selector, value) => evaluate(`const field=document.querySelector(${JSON.stringify(selector)});field.value=${JSON.stringify(value)};field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));`);
        for (const width of [320, 360, 375, 390, 430, 768, 1440]) await t.test(width + 'px: navigazione, filtri, cassa, errori e tooltip touch', async () => {
            await viewport(width);
            for (const file of ['dashboard.html', 'orders_incoming.html', 'orders_in-progress.html', 'orders_create.html', 'pending.html', 'reports.html', 'rates.html', 'revision-finance-cash.html', 'revision-finance-payments.html', 'finance-expenses.html', 'revision-finance-reversals.html', 'shipment-cards-admin.html', 'shipment-cards-rider.html']) {
                await navigate(file); await checkBounds(file + ' @' + width);
                if (width < 992) assert.equal(await evaluate('return document.querySelector(".app-header .header-title")?.getClientRects().length || 0;'), 0);
            }
            await navigate('orders_create.html');
            await evaluate('const select=document.querySelector(".staff-booking-form [name=customer_id]");select.selectedIndex=1;select.dispatchEvent(new Event("change",{bubbles:true}));');
            await waitFor('document.querySelector("label[for=store_name] [data-required-marker]")');
            assert.equal(await evaluate('return document.querySelectorAll("label[for=store_name] [data-required-marker]").length;'), 1, 'Il mittente obbligatorio conserva un solo asterisco dopo la selezione account');
            await click('[name="pickup_from"] + .ea-picker-trigger');
            await waitFor('document.querySelector(".picker-time")');
            const clock = await evaluate('const fields=document.querySelector(".picker-time .time-popover-fields").getBoundingClientRect(),button=document.querySelector(".picker-time .action-confirm").getBoundingClientRect();return {bottomAlignment:Math.abs(fields.bottom-button.bottom),height:button.height,left:button.left,bottom:button.bottom,width:innerWidth,viewportHeight:innerHeight};');
            assert.ok(clock.bottomAlignment < 2 && clock.height >= 44 && clock.left >= 0 && clock.bottom <= clock.viewportHeight, 'Conferma orario allineata: ' + JSON.stringify(clock));
            await click('.picker-time .picker-heading button');
            if (width < 768) {
                await navigate('revision-finance-reversals.html');
                await click('[data-cash-reversal] > summary');
                await click('[data-cash-reversal][open] .row-actions-menu > summary');
                await click('[data-cash-reversal][open] [data-dialog-open^="receipt-state-"]');
                await waitFor('document.querySelector("dialog[open] [name=action][value=restore]")');
                assert.equal(await evaluate('return document.querySelectorAll("dialog[id^=receipt-state-]").length;'), 1);
                await checkBounds('ripristino mobile @' + width);
                await click('dialog[open] [data-dialog-close]');
            }
            await navigate('revision-finance-cash.html');
            if (width < 768) {
                await click('.money-row > summary');
                assert.ok(await evaluate('return !!document.querySelector(".money-row[open] .money-row-detail").getClientRects().length;'));
                if (process.env.EA_REVISION_SCREENSHOTS) writeFileSync(join(process.env.EA_REVISION_SCREENSHOTS, 'cash-detail-' + width + '.png'), Buffer.from((await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })).data, 'base64'));
                await click('.money-register [data-dialog-open^="cash-edit-"]');
                await waitFor('document.querySelector("dialog[open] input[name=amount]")');
                await checkBounds('modifica movimento @' + width);
                await click('dialog[open] [data-dialog-close]');
                await click('.money-register form[data-confirm] button');
                await waitFor('document.querySelector("#action-confirmation[open]")');
                const actions = await evaluate('const back=document.querySelector("#action-confirmation .modal-back").getBoundingClientRect(),confirm=document.querySelector("#action-confirmation [data-confirm-action]").getBoundingClientRect();return {back:back.x,confirm:confirm.x};');
                assert.ok(actions.confirm < actions.back);
                await click('#action-confirmation [data-dialog-close]');
            }
            await click('[data-dialog-open="balance-add"]');
            await click('[data-finance-go="expense"]');
            await click('[data-finance-step="expense"] button[type="submit"]');
            await waitFor('document.querySelector("[data-finance-step=expense] [data-inline-validation]")');
            assert.equal(await evaluate('return document.activeElement.name;'), 'description');
            assert.ok(await evaluate('return [...document.querySelectorAll("[data-finance-step=expense] input[required]")].every(field=>[...field.labels].some(label=>label.textContent.includes("*")));'));
            await setValue('[data-finance-step="expense"] [name="description"]', 'Carburante e spese delle consegne con descrizione lunga');
            const invalidAmountWrites = writes;
            await setValue('[data-finance-step="expense"] [name="amount"]', '12.345');
            await click('[data-finance-step="expense"] button[type="submit"]');
            await waitFor('document.querySelector("[data-finance-step=expense] [name=amount]").getAttribute("aria-invalid")==="true"');
            assert.equal(writes, invalidAmountWrites, 'Importi con più di due decimali bloccati prima della richiesta');
            assert.ok(await evaluate('return document.getElementById(document.activeElement.dataset.inlineErrorId).textContent.includes("due decimali");'));
            await setValue('[data-finance-step="expense"] [name="amount"]', '12.50');
            rejectWrite = true; const before = writes;
            await click('[data-finance-step="expense"] button[type="submit"]');
            await waitFor('document.querySelector("[data-finance-step=expense] [name=amount]").getAttribute("aria-invalid")==="true"');
            assert.equal(writes, before + 1);
            assert.equal(await evaluate('return document.activeElement.name;'), 'amount');
            assert.ok(await evaluate('return document.getElementById(document.activeElement.dataset.inlineErrorId).textContent.includes("Importo non valido");'));
            await checkBounds('422 inline @' + width);
            await setValue('[data-finance-step="expense"] [name="amount"]', '14.75');
            assert.equal(await evaluate('return document.querySelector("[data-finance-step=expense] [name=amount]").getAttribute("aria-invalid");'), null);
            await click('[data-finance-step="expense"] button[type="submit"]');
            await waitFor('!document.querySelector("#balance-add[open]") && document.querySelector("[data-workspace-feedback]")');
            for (const side of ['left', 'right']) {
                await evaluate(`const button=document.createElement('button');button.id='edge-${side}';button.type='button';button.textContent='?';button.dataset.tooltip='Descrizione operativa molto lunga per verificare il posizionamento vicino al bordo dello schermo';button.style.cssText='position:fixed;${side}:1px;top:80px;width:44px;height:44px;z-index:2000';document.body.append(button);`);
                if (currentWidth < 600) {
                    const point = await evaluate(`const r=document.getElementById('edge-${side}').getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};`);
                    await send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [point] });
                    await new Promise(resolve => setTimeout(resolve, 520));
                    await send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
                } else {
                    await evaluate(`document.getElementById('edge-${side}').focus();`);
                }
                await waitFor('document.querySelector(".ea-tooltip")');
                const bounds = await evaluate('const r=document.querySelector(".ea-tooltip").getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:innerWidth,height:innerHeight};');
                assert.ok(bounds.left >= 11 && bounds.right <= bounds.width - 11 && bounds.top >= 11 && bounds.bottom <= bounds.height - 11, JSON.stringify(bounds));
                await evaluate(`document.getElementById('edge-${side}').remove();document.body.focus();`);
            }
            if (process.env.EA_REVISION_SCREENSHOTS) writeFileSync(join(process.env.EA_REVISION_SCREENSHOTS, 'cash-' + width + '.png'), Buffer.from((await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })).data, 'base64'));
        });
        assert.deepEqual(runtimeErrors, [], 'Nessuna eccezione JavaScript nelle pagine e interazioni');
        await t.test('stampa multipla: tre etichette, prezzo centrato, destinatario equilibrato e tre pagine A6', async () => {
            await viewport(768); await navigate('revision-labels.html');
            await send('Emulation.setEmulatedMedia', { media: 'print' });
            const labels = await evaluate('return [...document.querySelectorAll(".label")].map(label=>{const r=label.getBoundingClientRect(),price=label.querySelector(".label-total strong").getBoundingClientRect();return {width:r.width,height:r.height,center:Math.abs((r.left+r.right)/2-(price.left+price.right)/2),recipient:parseFloat(getComputedStyle(label.querySelector(".recipient")).fontSize),route:parseFloat(getComputedStyle(label.querySelector(".route-address dd")).fontSize)};});');
            assert.equal(labels.length, 3);
            assert.ok(labels.every(label => label.center < 2 && label.recipient <= label.route), JSON.stringify(labels));
            const pdf = Buffer.from((await send('Page.printToPDF', { printBackground: true, preferCSSPageSize: true, paperWidth: 105 / 25.4, paperHeight: 148 / 25.4, marginTop: 0, marginBottom: 0, marginLeft: 0, marginRight: 0 })).data, 'base64');
            assert.equal((pdf.toString('latin1').match(/\/Type\s*\/Page\b/g) || []).length, 3, 'Una pagina A6 per etichetta: ' + JSON.stringify(labels));
            if (process.env.EA_REVISION_SCREENSHOTS) writeFileSync(join(process.env.EA_REVISION_SCREENSHOTS, 'etichette-A6.pdf'), pdf);
            await send('Emulation.setEmulatedMedia', { media: '' });
        });
    } finally {
        socket?.close();
        if (browser && browser.exitCode === null) await new Promise(resolve => { browser.once('exit', resolve); browser.kill('SIGTERM'); });
        server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
        rmSync(temporary, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
    }
});
