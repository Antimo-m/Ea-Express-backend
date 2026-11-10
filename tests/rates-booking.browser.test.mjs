import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { execFileSync, spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { extname, join, resolve } from 'node:path';
import { tmpdir } from 'node:os';

const chrome = process.env.EA_BROWSER_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

test('Listini e ritiro: CAP inline, orari e contenuto nei viewport richiesti', { skip: !existsSync(chrome), timeout: 180000 }, async t => {
    const temporary = mkdtempSync(join(tmpdir(), 'ea-rates-booking-'));
    let browser, socket;
    try {
        execFileSync('php', ['-d', 'memory_limit=512M', 'vendor/bin/phpunit', 'tests/Feature/TerritorialRevisionTest.php', '--filter=test_revised_views_use_real_pagination_and_place_cash_below_the_accounting_table'], {
            env: { ...process.env, EA_UI_CAPTURE_DIR: temporary }, stdio: 'pipe',
        });
    } catch (error) {
        throw new Error(error.stdout?.toString() || error.message, { cause: error });
    }
    const markup = file => readFileSync(join(temporary, file), 'utf8').replace(/https?:\/\/localhost(?::\d+)?/g, '').replace(/https?:\/\/[^/"\s]+\/build\//g, '/build/');
    const mutations = [];
    const server = createServer(async (request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (url.pathname === '/rates' && request.method === 'POST') {
            let body = '';
            for await (const chunk of request) body += chunk;
            const data = JSON.parse(body);
            mutations.push(data);
            response.setHeader('Content-Type', 'application/json');
            if (data.postal_codes.includes('abcde')) {
                response.statusCode = 422;
                response.end(JSON.stringify({ errors: { 'postal_codes.1': ['Ogni CAP deve contenere 5 cifre.'] } }));
            } else {
                response.statusCode = 201;
                response.end(JSON.stringify({ data }));
            }
            return;
        }
        if (url.pathname === '/rates' || url.pathname === '/orders/create') {
            response.setHeader('Content-Type', 'text/html');
            response.end(markup(url.pathname === '/rates' ? 'rates.html' : 'create.html'));
            return;
        }
        if (url.pathname === '/booking-rules') {
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({ server_now: '2026-11-09T13:10:25+01:00', lead_minutes: 0, timezone: 'Europe/Rome' }));
            return;
        }
        if (url.pathname === '/rates/quote') {
            const inactive = url.searchParams.get('city') === 'Caserta';
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({ available: !inactive, territory_valid: true, availability_status: inactive ? 'inactive' : 'available', postal_code: inactive ? '81100' : '81031', price_cents: inactive ? null : 875, shipping_type: 'regional', delivery_time: '24 ore', reason: inactive ? 'Destinazione disattivata.' : null }));
            return;
        }
        const file = resolve('public', '.' + url.pathname);
        if (file.startsWith(resolve('public') + '/') && existsSync(file) && extname(file)) {
            response.setHeader('Content-Type', ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.woff': 'font/woff', '.svg': 'image/svg+xml' })[extname(file)] || 'application/octet-stream');
            response.end(readFileSync(file));
            return;
        }
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({ enabled: false, data: [], groups: [], unread: 0 }));
    });
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const origin = 'http://127.0.0.1:' + server.address().port;
        browser = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', '--user-data-dir=' + join(temporary, 'chrome'), 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
        const endpoint = await new Promise((resolve, reject) => {
            let output = '';
            const timer = setTimeout(() => reject(new Error(output)), 15000);
            browser.stderr.on('data', chunk => {
                output += chunk;
                const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/);
                if (match) { clearTimeout(timer); resolve(match[1]); }
            });
            browser.once('exit', code => { clearTimeout(timer); reject(new Error('Chrome ' + code)); });
        });
        const pages = await (await fetch('http://127.0.0.1:' + new URL(endpoint).port + '/json/list')).json();
        socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
        await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
        let sequence = 0;
        const pending = new Map();
        socket.addEventListener('message', ({ data }) => {
            const result = JSON.parse(data), handler = pending.get(result.id);
            if (!handler) return;
            pending.delete(result.id);
            result.error ? handler.reject(new Error(result.error.message)) : handler.resolve(result.result);
        });
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence, timer = setTimeout(() => reject(new Error('Chrome timeout ' + method)), 15000);
            pending.set(id, { resolve: value => { clearTimeout(timer); resolve(value); }, reject: error => { clearTimeout(timer); reject(error); } });
            socket.send(JSON.stringify({ id, method, params }));
        });
        const evaluate = async source => {
            const result = await send('Runtime.evaluate', { expression: '(()=>{' + source + '})()', returnByValue: true, awaitPromise: true });
            if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
            return result.result.value;
        };
        const waitFor = async expression => {
            for (let attempt = 0; attempt < 160; attempt++) {
                if (await evaluate('return Boolean(' + expression + ');')) return;
                await new Promise(resolve => setTimeout(resolve, 30));
            }
            throw new Error('Not ready: ' + expression + ' ' + await evaluate('return document.body.innerText.slice(-700);'));
        };
        await send('Emulation.setFocusEmulationEnabled', { enabled: true });
        const navigate = async path => {
            await evaluate('window.__previous=true;');
            await send('Page.navigate', { url: origin + path });
            await waitFor('!window.__previous && document.readyState === "complete" && document.querySelector("h1")');
            await evaluate('return document.fonts.ready.then(()=>true);');
        };
        const tap = async selector => {
            await evaluate('return new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));');
            const point = await evaluate('const node=document.querySelector(' + JSON.stringify(selector) + ');node.scrollIntoView({block:"center",behavior:"instant"});const r=node.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};');
            await send('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button: 'left', clickCount: 1 });
            await send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button: 'left', clickCount: 1 });
        };
        const fill = (name, value, selector = 'form') => evaluate('const field=document.querySelector(' + JSON.stringify(selector) + ').elements.namedItem(' + JSON.stringify(name) + ');field.value=' + JSON.stringify(value) + ';field.dispatchEvent(new Event("input",{bubbles:true}));field.dispatchEvent(new Event("change",{bubbles:true}));');
        const check = async label => {
            const result = await evaluate('return {width:innerWidth,scroll:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll("main input:not([type=hidden]),main button,dialog[open] input:not([type=hidden]),dialog[open] button,dialog[open] .field-error")].filter(node=>{const r=node.getBoundingClientRect();return r.width&&getComputedStyle(node).visibility!=="hidden"&&(r.left < -1 || r.right>innerWidth+1);}).map(node=>({name:node.name,css:node.className}))};');
            assert.ok(result.scroll <= result.width + 1, label + ' document ' + JSON.stringify(result));
            assert.deepEqual(result.overflow, [], label + ' controls');
        };
        for (const width of [320, 360, 375, 390, 430, 768, 1440]) {
            await t.test(width + 'px: modifica CAP con errore inline e correzione, form ritiro e conferma annullamento', async () => {
                await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 600 });
                await navigate('/rates');
                await tap('[data-rate-create]');
                await waitFor('document.querySelector("#rate-editor").open');
                await fill('city', 'Aversa', '[data-rate-form]');
                await fill('price', '8,75', '[data-rate-form]');
                await fill('postal_code', '81031, abcde', '[data-rate-form]');
                await tap('[data-rate-save]');
                await waitFor('document.querySelector("#rate-postal[aria-invalid=true]")');
                assert.match(await evaluate('return document.querySelector("#rate-editor").innerText;'), /Ogni CAP deve contenere 5 cifre/);
                assert.equal(await evaluate('return document.activeElement.id;'), 'rate-postal');
                await check('CAP inline ' + width);
                await fill('postal_code', '81031', '[data-rate-form]');
                assert.equal(await evaluate('return document.querySelector("#rate-postal").hasAttribute("aria-invalid");'), false);
                await tap('[data-rate-save]');
                await waitFor('!document.querySelector("#rate-editor").open');
                assert.deepEqual(mutations.at(-1).postal_codes, ['81031']);
                assert.equal(mutations.at(-1).price, '8,75');

                await navigate('/orders/create');
                await waitFor('document.querySelector("[name=pickup_from]").value === "13:11"');
                for (const service of ['external', 'regional']) {
                    await evaluate(`const select=document.querySelector('[name="shipping_type"]');select.value=${JSON.stringify(service)};select.dispatchEvent(new Event('change',{bubbles:true}));`);
                    const required = await evaluate('return ["delivery_postal_code","delivery_province","delivery_region"].map(name=>document.querySelector(`[name="${name}"]`).required);');
                    assert.deepEqual(required, [true, true, true]);
                }
                await evaluate('document.querySelector(".staff-booking-form").requestSubmit();');
                for (const name of ['delivery_postal_code', 'delivery_province', 'delivery_region']) {
                    assert.match(await evaluate(`return document.querySelector('[name="${name}"]').closest('.field').textContent;`), /Questo campo è obbligatorio/);
                }
                assert.equal(await evaluate('return document.querySelector("[name=pickup_to]").value;'), '14:11');
                await fill('content_description', 'Documenti', '.staff-booking-form');
                assert.equal(await evaluate('return document.querySelector("[name=category]").value;'), 'documents');
                await fill('content_description', 'Ricambi per automobile molto dettagliati', '.staff-booking-form');
                assert.equal(await evaluate('return document.querySelector("[name=category]").value;'), 'custom');
                await fill('delivery_city', 'Aversa', '.staff-booking-form');
                await waitFor('document.querySelector("[data-shipping-quote]").dataset.state === "ready"');
                assert.equal(await evaluate('return document.querySelector("[name=delivery_postal_code]").value;'), '81031');
                await fill('delivery_city', 'Caserta', '.staff-booking-form');
                await waitFor('document.querySelector("[data-shipping-quote]").dataset.state === "inactive"');
                assert.equal(await evaluate('return document.querySelector("[data-booking-review]").disabled;'), true);
                await fill('delivery_city', 'Aversa', '.staff-booking-form');
                await waitFor('!document.querySelector("[data-booking-review]").disabled');
                await check('ritiro ' + width);
                await tap('[data-dialog-open="cancel-order"]');
                await waitFor('document.querySelector("#cancel-order").open');
                await check('annulla ritiro ' + width);
                await tap('#cancel-order [data-dialog-close]');
                await waitFor('!document.querySelector("#cancel-order").open');
            });
        }
    } finally {
        socket?.close();
        browser?.kill('SIGTERM');
        await new Promise(resolve => server.close(resolve));
        await new Promise(resolve => setTimeout(resolve, 150));
        rmSync(temporary, { recursive: true, force: true });
    }
});
