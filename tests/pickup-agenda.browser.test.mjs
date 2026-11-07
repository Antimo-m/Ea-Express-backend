import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { extname, join, resolve } from 'node:path';

const chrome = process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

test('pickup agenda keeps whole accounts, daily counts and delegated label actions after live updates', { skip: !existsSync(chrome), timeout: 120000 }, async t => {
    const directory = mkdtempSync(join(tmpdir(), 'ea-pickup-browser-'));
    let server, browser, socket;
    try {
        try {
            execFileSync('php', ['artisan', 'test', '--compact', 'tests/Feature/PickupCalendarTest.php'], { env: { ...process.env, XDEBUG_MODE: 'off', EA_UI_FIXTURE_DIR: directory }, stdio: 'pipe' });
        } catch (error) {
            throw new Error(`Pickup fixture tests failed:\n${error.stdout?.toString() || error.message}`, { cause: error });
        }
        const markup = file => readFileSync(join(directory, file), 'utf8').replace(/https?:\/\/localhost(?::\d+)?/g, '').replace(/https?:\/\/[^/"\s]+\/build\//g, '/build/');
        server = createServer((request, response) => {
            const url = new URL(request.url, 'http://localhost');
            if (url.pathname === '/pickups') {
                response.setHeader('Content-Type', 'text/html');
                response.end(markup(`pickup-calendar-${url.searchParams.get('month') || '2026-10'}.html`));
                return;
            }
            if (url.pathname === '/pickups/day') {
                const page = url.searchParams.get('page') || '1';
                response.setHeader('Content-Type', 'text/html');
                response.end(markup(url.searchParams.get('date') === '2026-10-17' ? `pickup-day-page-${page}.html` : 'pickup-day-empty.html'));
                return;
            }
            if (/^\/orders\/[0-9a-f-]{36}$/.test(url.pathname)) {
                response.setHeader('Content-Type', 'text/html');
                response.end(`<main data-opened-order="${url.pathname.split('/').at(-1)}">Dettaglio ordine selezionato</main>`);
                return;
            }
            if (request.headers.accept?.includes('application/json')) {
                response.setHeader('Content-Type', 'application/json');
                response.end(JSON.stringify({ unread: 0, data: [], meta: { current_page: 1, last_page: 1, total: 0 }, enabled: false }));
                return;
            }
            const file = resolve('public', `.${url.pathname}`);
            if (file.startsWith(resolve('public') + '/') && existsSync(file) && extname(file)) {
                response.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.woff': 'font/woff' })[extname(file)] || 'application/octet-stream');
                response.end(readFileSync(file));
                return;
            }
            response.statusCode = 404;
            response.end();
        });
        await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
        const origin = `http://127.0.0.1:${server.address().port}`;
        browser = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-sandbox', '--disable-breakpad', '--disable-crash-reporter', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${join(directory, 'chrome')}`, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
        const endpoint = await new Promise((resolve, reject) => {
            let output = '';
            const timer = setTimeout(() => reject(new Error('Chrome did not start: '+output.slice(-1000))), 15000);
            browser.once('exit', code => { clearTimeout(timer); reject(new Error(`Chrome exited (${code}): ${output.slice(-1000)}`)); });
            browser.stderr.on('data', chunk => {
                output += chunk;
                const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/);
                if (match) { clearTimeout(timer); resolve(match[1]); }
            });
        });
        const pages = await (await fetch(`http://127.0.0.1:${new URL(endpoint).port}/json/list`)).json();
        socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
        await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
        let sequence = 0;
        const pending = new Map();
        socket.addEventListener('message', ({ data }) => {
            const result = JSON.parse(data);
            const callback = pending.get(result.id);
            if (!callback) return;
            pending.delete(result.id);
            if (result.error) callback.reject(new Error(result.error.message));
            else callback.resolve(result.result);
        });
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            const timer = setTimeout(() => { pending.delete(id); reject(new Error(`Chrome timed out: ${method}`)); }, 10000);
            pending.set(id, { resolve: result => { clearTimeout(timer); resolve(result); }, reject: error => { clearTimeout(timer); reject(error); } });
            socket.send(JSON.stringify({ id, method, params }));
        });
        const evaluate = async expression => {
            const result = await send('Runtime.evaluate', { expression: `(() => { return eval(${JSON.stringify(expression)}); })()`, awaitPromise: true, returnByValue: true });
            if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
            return result.result.value;
        };
        const waitFor = async expression => {
            for (let attempt = 0; attempt < 150; attempt++) {
                if (await evaluate(expression)) return;
                await new Promise(resolve => setTimeout(resolve, 20));
            }
            throw new Error('Pickup browser did not reach: '+expression);
        };
        const navigate = async path => {
            await evaluate('window.__previousPickupDocument = true');
            await send('Page.navigate', { url: `${origin}${path}` });
            await waitFor(`!window.__previousPickupDocument && document.readyState==='complete' && location.pathname===${JSON.stringify(path.split('?')[0])}`);
        };
        for (const width of [320, 375, 390, 430, 768, 1440]) {
            await t.test(`calendar, grouped pages and label actions fit ${width}px`, async () => {
                await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 600 });
                await navigate('/pickups?month=2026-10');
                assert.equal(await evaluate(`document.querySelectorAll('.pickup-calendar-table th').length`), 7);
                assert.equal(await evaluate(`document.querySelector('[data-pickup-date="2026-10-17"]').dataset.pickupCount`), '47');
                assert.equal(await evaluate(`document.querySelector('a[href*="/pickups/grouped"]')`), null);
                assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`), `calendar overflow at ${width}px`);
                const seen = new Set();
                const accounts = new Set();
                for (const [page, expectedCards, expectedGroups] of [[1, 26, 20], [2, 20, 20], [3, 1, 1]]) {
                    await navigate(`/pickups/day?date=2026-10-17&page=${page}`);
                    assert.equal(await evaluate(`document.querySelector('[data-pickup-day-total]').textContent`), '47');
                    assert.equal(await evaluate(`document.querySelectorAll('.pickup-order-card').length`), expectedCards);
                    assert.equal(await evaluate(`document.querySelectorAll('[data-pickup-account]').length`), expectedGroups);
                    assert.ok(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`), `day page ${page} overflow at ${width}px`);
                    const rows = await evaluate(`[...document.querySelectorAll('[data-pickup-account]')].map(group=>({account:group.dataset.pickupAccount,orders:[...group.querySelectorAll('[data-operational-order]')].map(order=>({slug:order.dataset.operationalOrder,href:order.querySelector('[data-order-link]').getAttribute('href')}))}))`);
                    for (const group of rows) {
                        assert.match(group.account, /^[0-9a-f-]{36}$/);
                        assert.equal(accounts.has(group.account), false, 'account split across pages');
                        accounts.add(group.account);
                        for (const order of group.orders) {
                            assert.equal(seen.has(order.slug), false, 'order duplicated across pages');
                            seen.add(order.slug);
                            assert.equal(order.href, `/orders/${order.slug}`);
                        }
                    }
                    if (page < 3) assert.match(await evaluate(`document.querySelector('[rel="next"]').getAttribute('href')`), /date=2026-10-17.*page=/);
                }
                assert.equal(seen.size, 47);
                assert.equal(accounts.size, 41);
                await navigate('/pickups/day?date=2026-10-17');
                assert.equal(await evaluate(`document.querySelector('[data-pickup-account]').querySelectorAll('.pickup-order-card').length`), 7);
                await evaluate(`window.__pickupDocument='same';window.__oldPickupForm=document.querySelectorAll('[data-pickup-account] form')[1];window.dispatchEvent(new CustomEvent('ea:workspace-updated',{detail:{kind:'orders'}}))`);
                await waitFor(`!window.__oldPickupForm.isConnected`);
                assert.equal(await evaluate(`window.__pickupDocument`), 'same');
                await evaluate(`window.__labelSubmissions=[];document.addEventListener('submit',event=>{if(event.target.matches('[data-pickup-account] form')){event.preventDefault();window.__labelSubmissions.push({account:event.target.closest('[data-pickup-account]').dataset.pickupAccount,action:event.target.getAttribute('action'),slugs:new FormData(event.target).getAll('ids[]')});}});document.querySelectorAll('[data-pickup-account]')[1].querySelector('[data-select-labels]').click()`);
                assert.equal(await evaluate(`document.querySelectorAll('[data-pickup-account]')[1].querySelectorAll('input:checked').length`), 0);
                assert.equal(await evaluate(`document.querySelector('[data-pickup-account]').querySelectorAll('input:checked').length`), 7);
                const selected = await evaluate(`document.querySelectorAll('[data-pickup-account]')[1].querySelector('[data-operational-order]').dataset.operationalOrder`);
                await evaluate(`document.querySelectorAll('[data-pickup-account]')[1].querySelector('[data-print-all-labels]').click()`);
                const submissions = await evaluate(`window.__labelSubmissions`);
                assert.equal(submissions.length, 1);
                const submission = submissions[0];
                assert.equal(submission.account, await evaluate(`document.querySelectorAll('[data-pickup-account]')[1].dataset.pickupAccount`));
                assert.equal(submission.action, '/labels');
                assert.deepEqual(submission.slugs, [selected]);
                assert.equal(await evaluate(`document.querySelector('[data-pickup-account]').querySelectorAll('input:checked').length`), 7);
            });
        }
        const detail = await evaluate(`document.querySelector('.pickup-order-card [data-order-link]').getAttribute('href')`);
        await evaluate(`document.querySelector('.pickup-order-card [data-order-link]').click()`);
        await waitFor(`document.querySelector('[data-opened-order]')!==null`);
        assert.equal(await evaluate('location.pathname'), detail);
    } finally {
        socket?.close();
        if (browser && browser.exitCode === null) await new Promise(resolve => { browser.once('exit', resolve); browser.kill('SIGTERM'); });
        if (server) { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
        rmSync(directory, { recursive: true, force: true, maxRetries: 3 });
    }
});
