import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve, extname } from 'node:path';
import { tmpdir } from 'node:os';

const chrome = process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
test('Bilancio: categorie, dettagli, filtri, paginazione, stampa vuota e viewport reali', { skip: !existsSync(chrome), timeout: 180000 }, async t => {
    const temporary = mkdtempSync(join(tmpdir(), 'ea-finance-review-'));
    let browser, socket, rejectWrite = true, writes = 0;
    try {
        execFileSync('php', ['-d', 'memory_limit=512M', 'vendor/bin/phpunit', 'tests/Feature/FinancialIntegrityRevisionTest.php', '--filter=test_categories_have_one_paginated_register'], { env: { ...process.env, XDEBUG_MODE: 'off', EA_UI_FIXTURE_DIR: temporary }, stdio: 'pipe' });
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
        if (url.pathname === '/balance') {
            const category = url.searchParams.get('ledger') || 'payments';
            const file = 'financial-' + category + (url.searchParams.get('movements_page') === '2' ? '-page2' : '') + '.html';
            response.setHeader('Content-Type', 'text/html'); response.end(markup(file)); return;
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
        for (const width of [320, 360, 375, 390, 430, 768, 1440]) await t.test(width + 'px: categorie, importi, filtri, dettagli e modali', async () => {
            await viewport(width);
            for (const category of ['payments', 'expenses', 'cash', 'refunds', 'history', 'pending', 'reversed']) {
                await navigate('financial-' + category + '.html');
                await checkBounds(category + ' @' + width);
                const selector = category === 'pending' ? '.finance-mobile-list details' : category === 'reversed' ? '.finance-mobile-list details' : '.money-row';
                if (!['pending', 'reversed'].includes(category) || width < 768) {
                    const found = await evaluate(`return !!document.querySelector(${JSON.stringify(selector)});`);
                    if (found) {
                        await click(selector + ' > summary');
                        assert.ok(await evaluate(`return document.querySelector(${JSON.stringify(selector)})?.open;`));
                        await checkBounds(category + ' dettagli @' + width);
                        await click(selector + ' > summary');
                        assert.ok(!await evaluate(`return document.querySelector(${JSON.stringify(selector)})?.open;`));
                    }
                }
                if (category === 'payments') {
                    const metrics = await evaluate('return [...document.querySelectorAll(".money-row-amount,time")].filter(n=>n.getClientRects().length).map(n=>({width:n.clientWidth,scroll:n.scrollWidth,text:n.textContent}));');
                    assert.ok(metrics.every(n => n.scroll <= n.width + 1), JSON.stringify(metrics));
                }
            }
            await navigate('financial-cash.html');
            await click('.money-row > summary');
            await click('[data-dialog-open^="cash-edit-"]');
            await waitFor('document.querySelector("dialog[open]")');
            await checkBounds('modifica contanti @' + width);
            await click('dialog[open] [data-dialog-close]');
            await waitFor('!document.querySelector("dialog[open]")');
            await click('[data-dialog-open="balance-add"]');
            await waitFor('document.querySelector("#balance-add[open]")');
            await click('[data-finance-go="expense"]');
            await checkBounds('nuova spesa @' + width);
            await click('dialog[open] [data-dialog-close]');
            await waitFor('!document.querySelector("dialog[open]")');
            await navigate('financial-payments.html');
            if (width < 768) assert.equal(await evaluate('return getComputedStyle(document.querySelector(".ledger-desktop-categories")).display;'), 'none');
            const more = await evaluate('return !!document.querySelector("[data-filter-more]")?.getClientRects().length;');
            if (more) {
                await click('[data-filter-more]');
                await waitFor('document.querySelector("[data-filter-dialog][open]")');
                await checkBounds('filtri avanzati @' + width);
                await click('[data-filter-close]');
                await waitFor('!document.querySelector("[data-filter-dialog][open]")');
            }
            const pager = '.finance-ledger .workspace-pagination a';
            await click(pager);
            await waitFor('location.search.includes("movements_page=2")');
            await checkBounds('pagina 2 @' + width);
            assert.equal(await evaluate('return document.querySelectorAll(".money-row").length;'), 2);
            await navigate('financial-labels-empty.html');
            await click('[data-label-availability]');
            await waitFor('document.querySelector("[data-label-status]")');
            assert.equal(await evaluate('return document.querySelector("[data-label-status]").textContent;'), 'Al momento non ci sono ordini da stampare');
            await checkBounds('stampa vuota @' + width);
            if (process.env.EA_REVISION_SCREENSHOTS) {
                await navigate('financial-payments.html');
                writeFileSync(join(process.env.EA_REVISION_SCREENSHOTS, 'bilancio-' + width + '.png'), Buffer.from((await send('Page.captureScreenshot', {format:'png',captureBeyondViewport:true})).data, 'base64'));
            }
        });
        assert.deepEqual(runtimeErrors, []);
    } finally {
        socket?.close();
        if (browser && browser.exitCode === null) await new Promise(resolve => { browser.once('exit', resolve); browser.kill('SIGTERM'); });
        server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
        rmSync(temporary, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
    }
});
