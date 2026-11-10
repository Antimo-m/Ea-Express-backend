import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join, resolve, extname } from 'node:path';
import { tmpdir } from 'node:os';

const chrome = process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
test('Collaudo produzione: selezione stampa, badge, azioni e Rider a tutte le larghezze', { skip: !existsSync(chrome), timeout: 180000 }, async t => {
    const temporary = mkdtempSync(join(tmpdir(), 'ea-functional-revision-'));
    let browser, socket;
    try {
        execFileSync('php', ['-d', 'memory_limit=512M', 'vendor/bin/phpunit', 'tests/Feature/ProductionRevisionTest.php', '--filter=test_revision_browser_fixtures'], { env: { ...process.env, XDEBUG_MODE: 'off', EA_UI_FIXTURE_DIR: temporary }, stdio: 'pipe' });
    } catch (error) { throw new Error(error.stdout?.toString() || error.message, { cause: error }); }
    const markup = file => readFileSync(join(temporary, file), 'utf8').replace(/https?:\/\/localhost(?::\d+)?/g, '').replace(/https?:\/\/[^/"\s]+\/build\//g, '/build/');
    const server = createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
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
        for (const width of [320, 360, 375, 390, 430, 768, 1024, 1440]) await t.test(`${width}px: pagine e selezione di stampa`, async () => {
            try {
            await viewport(width);
            for (const file of ['revision-dashboard.html', 'revision-history.html', 'revision-order.html', 'revision-customer.html', 'revision-rider.html', 'revision-zones.html', 'revision-create.html']) {
                await navigate(file); await checkBounds(file + ' @' + width);
            }
            if (width < 992) {
                await click('[aria-label="Apri menu"]');
                await waitFor('document.querySelector("#app-navigation.show")');
                assert.ok(await evaluate('const box=document.querySelector("#app-navigation").getBoundingClientRect();return box.left>=-1 && box.right<=innerWidth+1;'));
                await click('[aria-label="Chiudi menu"]');
                await waitFor('!document.querySelector("#app-navigation.show")');
                await checkBounds('sidebar chiusa @'+width);
            }
            const addAlignment=await evaluate('const container=document.querySelector("#app-navigation .sidebar-content"),button=container.querySelector(".sidebar-create"),box=container.getBoundingClientRect();return Math.abs(box.left+container.clientWidth-24-button.getBoundingClientRect().right);');
            if (width >= 992) assert.ok(addAlignment < 2, 'Aggiunta sidebar a destra: '+addAlignment);
            await navigate('revision-history.html');
            await evaluate('window.__prints=[];window.open=(url)=>{window.__prints.push(String(url));};');
            await click('[data-history-print-start]');
            assert.equal(await evaluate('return window.__prints.length;'), 0);
            await click('[data-history-label-day="2026-11-09"]');
            assert.match(await evaluate('return document.querySelector("[data-history-print-status]").textContent;'), /25 etichette/);
            await checkBounds('selezione giornata @' + width);
            await click('[data-history-print-confirm]');
            const day = new URL(await evaluate('return window.__prints[0];'));
            assert.equal(day.searchParams.get('scope'), 'history');
            assert.equal(day.searchParams.get('day'), '2026-11-09');
            await click('[data-history-print-cancel]');
            assert.equal(await evaluate('return document.querySelector(".order-archive").dataset.historyPrinting;'), undefined);
            await click('[data-history-print-start]');
            await click('[data-history-label-single]');
            assert.match(await evaluate('return document.querySelector("[data-history-print-status]").textContent;'), /1 etichetta/);
            await click('[data-history-print-confirm]');
            const single = new URL(await evaluate('return window.__prints[1];'));
            assert.equal(single.searchParams.getAll('ids[]').length, 1);
            assert.equal(single.searchParams.has('day'), false);
            await navigate('revision-order.html');
            assert.equal(await evaluate('return document.body.textContent.includes("Tracking vettore");'), false);
            assert.equal(await evaluate('return document.body.textContent.includes("Incasso effettivo registrato");'), false);
            assert.ok(await evaluate('return Boolean(document.querySelector(".price-card .tone-green"));'));
            const heading = await evaluate('const actions=document.querySelector(".order-detail-heading > .row-actions").getBoundingClientRect(),header=document.querySelector(".order-detail-heading").getBoundingClientRect();return Math.abs(actions.right-header.right);');
            assert.ok(heading < 2, 'Azioni dettaglio a destra: '+heading);
            if (process.env.EA_REVISION_SCREENSHOTS && [375,1440].includes(width)) writeFileSync(join(process.env.EA_REVISION_SCREENSHOTS, `ordine-${width}.png`), Buffer.from((await send('Page.captureScreenshot', {format:'png',captureBeyondViewport:false})).data,'base64'));
            await navigate('revision-pending-price.html');
            const pendingTone=await evaluate('const badge=document.querySelector(".price-card .tone-blue"),sample=document.createElement("span");sample.style.color="var(--ea-info)";document.body.append(sample);const matches=getComputedStyle(badge).color===getComputedStyle(sample).color;sample.remove();return matches;');
            assert.equal(pendingTone, true, 'Prezzo da confermare realmente blu');
            await navigate('revision-zones.html');
            await waitFor(`document.querySelector('[data-operations-key="Zona condivisa"]')`);
            await click('[data-operations-key="Zona condivisa"]');
            assert.match(await evaluate(`return document.querySelector('[data-operations-key="Zona condivisa"] .explorer-rider-summary').textContent;`), /\+3/);
            assert.equal(await evaluate('return document.querySelectorAll(".explorer-assignments .assignment-name").length;'), 6);
            await checkBounds('zona con sei Rider @'+width);
            if (process.env.EA_REVISION_SCREENSHOTS && [375,1440].includes(width)) writeFileSync(join(process.env.EA_REVISION_SCREENSHOTS, `zona-${width}.png`), Buffer.from((await send('Page.captureScreenshot', {format:'png',captureBeyondViewport:false})).data,'base64'));
            const alignment=await evaluate('const search=document.querySelector(".operations-period-filter .filter-submit").getBoundingClientRect(),period=document.querySelector(".operations-period-filter .period-options a").getBoundingClientRect();return Math.abs(search.top-period.top);');
            assert.ok(alignment < 3, 'Search allineato al periodo: '+alignment);
            await navigate('revision-create.html');
            await waitFor('document.querySelector("input[list] + .ea-picker-trigger")');
            await click('input[list] + .ea-picker-trigger');
            await waitFor('document.querySelector(".ea-picker-popup .picker-content > .action-confirm")');
            const fieldConfirm=await evaluate('const content=document.querySelector(".ea-picker-popup .picker-content"),button=content.querySelector(":scope > .action-confirm"),r=button.getBoundingClientRect(),p=content.getBoundingClientRect();return {last:content.lastElementChild===button,right:Math.abs(p.right-r.right)};');
            assert.ok(fieldConfirm.last && fieldConfirm.right < 2, JSON.stringify(fieldConfirm));
            await evaluate('document.dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true}));');
            await navigate('revision-create.html');
            await click('[data-dialog-open="cancel-order"]');
            await waitFor('document.querySelector("#cancel-order[open]")');
            const cancellation=await evaluate('const button=document.querySelector("#cancel-order .action-delete"),back=document.querySelector("#cancel-order .modal-back");return {label:button.textContent.trim(),color:getComputedStyle(button).backgroundColor,left:button.getBoundingClientRect().left,back:back.getBoundingClientRect().left};');
            assert.equal(cancellation.label, 'Annulla compilazione');
            assert.ok(cancellation.left < cancellation.back, JSON.stringify(cancellation));
            await checkBounds('annullamento @'+width);
            } catch (error) { process.stderr.write(error.stack+'\n'); throw error; }
        });
        assert.deepEqual(runtimeErrors, []);
    } finally {
        socket?.close();
        if (browser && browser.exitCode === null) await new Promise(resolve => { browser.once('exit', resolve); browser.kill('SIGTERM'); });
        server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
        rmSync(temporary, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
    }
});
