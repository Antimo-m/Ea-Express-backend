import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, extname } from 'node:path';

const chrome = process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

test('Rider workspace: ranges, status sorting, stable state and mobile controls', { skip: !existsSync(chrome), timeout: 180000 }, async t => {
    const temporary = mkdtempSync(join(tmpdir(), 'ea-rider-ui-'));
    let server, browser, socket;
    let feedRequests = 0, dayRequests = 0, failedRange = false;
    const errors = [];
    try {
        execFileSync('php', ['-d', 'memory_limit=512M', 'vendor/bin/phpunit', 'tests/Feature/RiderWorkspaceRevisionTest.php'], { env: { ...process.env, XDEBUG_MODE: 'off', EA_RIDER_FIXTURE_DIR: temporary }, stdio: 'pipe' });
        const metadata = JSON.parse(readFileSync(join(temporary, 'metadata.json')));
        const markup = file => readFileSync(join(temporary, file)).toString().replace(/https?:\/\/localhost(?::\d+)?/g, '').replace(/https?:\/\/[^/"\s]+\/build\//g, '/build/');
        const dayPath = `/rider-operations/${metadata.rider}`;
        const range = '?period=custom&from=2026-09-15&to=2026-09-17';
        server = createServer((request, response) => {
            const url = new URL(request.url, 'http://localhost');
            let fixture;
            if (url.pathname === '/rider-operations') fixture = 'index.html';
            else if (url.pathname === '/settings/users/create') fixture = 'create.html';
            else if (url.pathname === '/settings/users') fixture = 'users.html';
            else if (url.pathname === dayPath) {
                dayRequests++;
                if (failedRange && request.headers['x-requested-with']) { failedRange = false; response.statusCode = 503; response.end('Non disponibile'); return; }
                fixture = !url.searchParams.has('from') ? 'day-today.html' : url.searchParams.get('from') === '2026-09-17' ? 'day-same.html' : url.searchParams.get('state') === 'completed' ? 'day-completed.html' : url.searchParams.get('page') === '2' ? 'day-page2.html' : 'day-range.html';
            } else if (url.pathname === '/rider-operations/feed') { feedRequests++; response.setHeader('Content-Type', 'application/json'); response.end(readFileSync(join(temporary, 'feed.json'))); return; }
            else if (url.pathname === '/rider-operations/detail') fixture = `detail-${url.searchParams.get('zone') || 'Napoli'}.html`;
            if (fixture) { response.setHeader('Content-Type', 'text/html'); response.end(markup(fixture)); return; }
            const file = url.pathname.startsWith('/build/') ? resolve('public', `.${url.pathname}`) : resolve('public', `.${url.pathname}`);
            if (existsSync(file) && extname(file)) { response.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.svg': 'image/svg+xml' })[extname(file)] || 'application/octet-stream'); response.end(readFileSync(file)); return; }
            response.setHeader('Content-Type', 'application/json'); response.end(JSON.stringify({ data: [], groups: [], unread: 0, available: false, enabled: false, now: '2026-11-06T12:00:00+01:00' }));
        });
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const origin = `http://127.0.0.1:${server.address().port}`;
        browser = spawn(chrome, ['--headless=new', '--disable-background-timer-throttling', '--disable-renderer-backgrounding', '--disable-backgrounding-occluded-windows', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${join(temporary, 'chrome')}`, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
        const endpoint = await new Promise((resolve, reject) => {
            let output = '';
            const timeout = setTimeout(() => reject(new Error('Browser startup timed out')), 15000);
            browser.once('exit', code => { clearTimeout(timeout); reject(new Error(`Browser exited (${code})`)); });
            browser.stderr.on('data', chunk => { output += chunk; const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timeout); resolve(match[1]); } });
        });
        const pages = await (await fetch(`http://127.0.0.1:${new URL(endpoint).port}/json/list`)).json();
        socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
        await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
        let sequence = 0;
        const pending = new Map();
        socket.addEventListener('message', ({ data }) => {
            const result = JSON.parse(data);
            if (result.method === 'Runtime.exceptionThrown') errors.push(result.params.exceptionDetails.exception?.description || result.params.exceptionDetails.text);
            if (!pending.has(result.id)) return;
            const task = pending.get(result.id); pending.delete(result.id);
            if (result.error) task.reject(new Error(result.error.message)); else task.resolve(result.result);
        });
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`Browser timeout: ${method}`)); }, 15000);
            pending.set(id, { resolve: value => { clearTimeout(timeout); resolve(value); }, reject: error => { clearTimeout(timeout); reject(error); } }); socket.send(JSON.stringify({ id, method, params }));
        });
        const evaluate = async expression => {
            const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
            if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
            return result.result.value;
        };
        const until = async expression => {
            for (let count = 0; count < 150; count++) { if (await evaluate(expression)) return; await wait(30); }
            throw new Error(`Condition not reached: ${expression}`);
        };
        const navigate = async path => {
            await evaluate('window.__previousRiderDocument = true');
            await send('Page.navigate', { url: origin + path });
            await until('!window.__previousRiderDocument && document.readyState === "complete" && (!document.querySelector("select,input[type=date],input[type=month]") || document.querySelector(".ea-picker-trigger"))');
            await evaluate('document.fonts.ready.then(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))))');
        };
        const viewport = width => send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
        const change = (selector, value, type = 'change') => evaluate(`{const input=document.querySelector(${JSON.stringify(selector)}); input.value=${JSON.stringify(value)}; input.dispatchEvent(new Event(${JSON.stringify(type)},{bubbles:true}));}`);
        await send('Runtime.enable');
        await send('Page.enable');

        await t.test('all four presets stay inline and buttons fit at six viewport widths', async () => {
            for (const width of [320, 375, 390, 430, 768, 1440]) {
                await viewport(width); await navigate('/rider-operations' + range);
                const layout = await evaluate(`(() => { const controls=[...document.querySelectorAll('.operations-period-filter .period-options a,.operations-period-filter [data-custom-period]')].map(node=>({text:node.textContent.trim(),x:node.getBoundingClientRect().x,y:node.getBoundingClientRect().y,right:node.getBoundingClientRect().right,height:node.getBoundingClientRect().height}));return {controls,overflow:document.documentElement.scrollWidth>innerWidth+1};})()`);
                assert.equal(layout.controls.length, 4, `${width}: four preset controls`);
                assert.equal(Math.max(...layout.controls.map(node => node.y)) - Math.min(...layout.controls.map(node => node.y)) < 3, true, `${width}: presets share one line ${JSON.stringify(layout)}`);
                assert.equal(layout.controls.every(node => node.x >= 0 && node.right <= width + 1 && node.height >= 40), true, `${width}: touch controls within screen`);
                assert.equal(layout.overflow, false, `${width}: no horizontal document overflow`);
                await navigate('/settings/users/create');
                const actions = await evaluate(`(() => {const row=document.querySelector('.rider-create-actions'),buttons=[...row.querySelectorAll('a,button')];return {texts:buttons.map(node=>node.textContent.trim()),icons:row.querySelectorAll('i').length,center:Math.abs((row.getBoundingClientRect().left+row.getBoundingClientRect().right)/2-(buttons[0].getBoundingClientRect().left+buttons[1].getBoundingClientRect().right)/2),overflow:document.documentElement.scrollWidth>innerWidth+1};})()`);
                assert.deepEqual(actions.texts, ['Annulla', 'Aggiungi']); assert.equal(actions.icons, 0); assert.ok(actions.center < 3); assert.equal(actions.overflow, false);
            }
        });

        await t.test('sorting reflects delivered, pending and unassigned status and keeps canonical range links', async () => {
            await viewport(1440); await navigate('/rider-operations' + range);
            for (const [sort, expected] of [['delivered', 'Caserta'], ['pending', 'Napoli'], ['unassigned', 'Aversa']]) {
                await change('[data-operations-sort]', sort);
                assert.equal(await evaluate('document.querySelector("[data-operations-list] button strong").textContent'), expected);
            }
            assert.equal(await evaluate('document.querySelector("[data-operations-sort] option[value=volume]")'), null);
            assert.deepEqual(await evaluate('[...document.querySelectorAll("[data-kpi=delivered],[data-kpi=pending],[data-kpi=unassigned]")].map(node=>Number(node.textContent))'), [5, 21, 3]);
            await evaluate('document.querySelector("[data-operations-key=Napoli]").click()');
            const link = new URL(await evaluate('document.querySelector(".assignment-name[href]").href'));
            assert.equal(link.pathname, dayPath); assert.equal(link.searchParams.get('from'), '2026-09-15'); assert.equal(link.searchParams.get('to'), '2026-09-17'); assert.equal(link.searchParams.get('zone'), 'Napoli');
            await evaluate('document.querySelector("[data-operations-view=rider]").click()');
            await evaluate(`document.querySelector('[data-operations-key="${metadata.rider}"]').click()`);
            const full = new URL(await evaluate('[...document.querySelectorAll(".explorer-detail-heading a")].find(node=>node.textContent.includes("Dettaglio giornata")).href'));
            assert.equal(full.searchParams.get('from'), '2026-09-15'); assert.equal(full.searchParams.get('to'), '2026-09-17'); assert.equal(full.searchParams.get('period'), 'custom');
        });

        await t.test('filters, selection, open content and scroll survive polling signals and more than thirty seconds', async () => {
            await navigate('/rider-operations' + range);
            await change('[data-operations-sort]', 'pending'); await change('[data-operations-search]', 'Nap', 'input');
            await evaluate('window.__riderStableNode=document.querySelector("[data-operations-detail]").firstElementChild; document.querySelector("[data-operations-detail]").insertAdjacentHTML("beforeend","<details open data-user-open><summary>Informazioni aperte</summary>Contenuto</details>");window.scrollTo({top:250,behavior:"instant"});window.__riderScroll=scrollY;');
            const before = feedRequests;
            await evaluate('for(const name of ["ea:workspace-polled","ea:workspace-updated","pageshow","visibilitychange"]){window.dispatchEvent(new CustomEvent(name,{detail:{kind:"order.updated"}}));document.dispatchEvent(new Event(name));}');
            await wait(32000);
            assert.equal(feedRequests, before, 'operational feed is never polled automatically');
            assert.equal(await evaluate('document.querySelector("[data-operations-detail]").firstElementChild===window.__riderStableNode && document.querySelector("[data-user-open]").open'), true);
            assert.equal(await evaluate('scrollY===window.__riderScroll'), true, JSON.stringify(await evaluate('({actual:scrollY,expected:window.__riderScroll})')));
            assert.equal(await evaluate('document.querySelector("[data-operations-search]").value'), 'Nap');
            assert.equal(await evaluate('new URL(location.href).searchParams.get("selected")'), 'Napoli');
            await evaluate('document.querySelector("[data-operations-refresh]").click()'); await until('!document.querySelector("[data-zone-error]").textContent');
            for (let count = 0; count < 100 && feedRequests === before; count++) await wait(20);
            assert.equal(feedRequests, before + 1); assert.equal(await evaluate('document.querySelector("[data-operations-search]").value'), 'Nap');
        });

        await t.test('rider date ranges, pagination, state, same-day and reset update through AJAX without reload', async () => {
            for (const width of [320, 375, 390, 430, 768, 1440]) {
                await viewport(width); await navigate(dayPath + range);
                assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'), true, `${width}: rider day fits`);
                const bounds = await evaluate(`(() => {const buttons=[...document.querySelector('.rider-range-actions').children].map(node=>node.getBoundingClientRect());return buttons.map(node=>({x:node.x,right:node.right,y:node.y}));})()`);
                assert.ok(bounds[1].x > bounds[0].x && Math.abs(bounds[0].y - bounds[1].y) < 2, `${width}: search is on the far right`);
            }
            await evaluate('window.__riderDocumentMarker="retained";window.scrollTo({top:300,behavior:"instant"});window.__riderScroll=scrollY;');
            await evaluate('document.querySelector(".workspace-pagination a[rel=next]").click()');
            await until('location.search.includes("page=2") && !document.querySelector("[data-rider-day-results]").hasAttribute("aria-busy")');
            assert.equal(await evaluate('document.querySelectorAll("[data-rider-delivery]").length'), 2);
            assert.equal(await evaluate('window.__riderDocumentMarker'), 'retained'); assert.equal(await evaluate('scrollY===window.__riderScroll'), true, JSON.stringify(await evaluate('({actual:scrollY,expected:window.__riderScroll})')));
            await evaluate('[...document.querySelectorAll(".rider-day-tabs a")].find(node=>node.textContent.includes("Completate")).click()');
            await until('location.search.includes("state=completed") && !document.querySelector("[data-rider-day-results]").hasAttribute("aria-busy")');
            assert.equal(await evaluate('document.querySelectorAll("[data-rider-delivery]").length'), 1);
            await change('[data-rider-day-period] [name=from]', '2026-09-17');
            await change('[data-rider-day-period] [name=to]', '2026-09-17');
            await evaluate('document.querySelector("[data-rider-day-period]").requestSubmit()');
            await until('new URL(location.href).searchParams.get("from")==="2026-09-17" && !document.querySelector("[data-rider-day-results]").hasAttribute("aria-busy")');
            assert.equal(await evaluate('document.querySelector("[data-rider-day-caption]").textContent.trim()'), '17/09/2026');
            failedRange = true;
            await change('[data-rider-day-period] [name=from]', '2026-09-15');
            await evaluate('document.querySelector("[data-rider-day-period]").requestSubmit()'); await until('!document.querySelector("[data-rider-day-error]").hidden');
            assert.equal(await evaluate('new URL(location.href).searchParams.get("from")'), '2026-09-17', 'failure retains the last successful URL and results');
            await evaluate('document.querySelector("[data-rider-day-reset]").click()');
            await until('!location.search && document.querySelector("[data-rider-day-caption]").textContent.trim()==="06/11/2026"');
            assert.deepEqual(await evaluate('[...document.querySelectorAll("[data-rider-day-period] input[type=date]")].map(node=>({value:node.value,label:node.nextElementSibling.textContent.trim()}))'), [{ value: '2026-11-06', label: '06/11/2026' }, { value: '2026-11-06', label: '06/11/2026' }]);
            assert.equal(await evaluate('document.querySelector("[data-rider-day-period] [name=state]").value'), 'all');
            assert.equal(await evaluate('document.querySelector("[data-rider-day-period] [name=to]").min'), '2026-11-06');
            assert.equal(await evaluate(`document.querySelector('a[aria-label="Torna ai Rider"]').getAttribute('href')`), '/settings/users');
            assert.equal(await evaluate('window.__riderDocumentMarker'), 'retained');
            const before = dayRequests;
            await evaluate('window.dispatchEvent(new Event("ea:workspace-polled"));window.dispatchEvent(new CustomEvent("ea:workspace-updated",{detail:{kind:"order.updated"}}));'); await wait(500);
            assert.equal(dayRequests, before, 'rider results are excluded from global automatic HTML refresh');
        });
        assert.deepEqual(errors, [], 'no uncaught browser errors');
    } finally {
        socket?.close();
        browser?.kill('SIGTERM');
        if (browser?.exitCode === null) await new Promise(resolve => { browser.once('exit', resolve); setTimeout(resolve, 3000); });
        if (server) await new Promise(resolve => { server.closeAllConnections?.(); server.close(resolve); });
        rmSync(temporary, { recursive: true, force: true });
    }
});
