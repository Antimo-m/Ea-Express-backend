import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

async function setup(response) {
  let submit, calls = 0, release;
  const button = { textContent: 'Salva', disabled: false };
  const feedback = { setAttribute() {} };
  const form = {
    dataset: {}, action: { name: 'action', value: 'receive' },
    getAttribute: () => '/balance/1/payment',
    querySelector: () => button, append() {}, setAttribute() {}, removeAttribute() {},
    addEventListener: (name, fn) => { if (name === 'submit') submit = fn; },
  };
  const source = (await readFile(new URL('../../resources/js/financial-forms.js', import.meta.url), 'utf8')).replace('export function', 'function');
  const context = {
    document: { querySelectorAll: () => [form], createElement: () => feedback },
    FormData: class {},
    sessionStorage: { getItem: () => null, setItem() {} },
    location: { assign: url => { context.redirect = url; } },
    fetch: async url => { calls++; assert.equal(url, '/balance/1/payment'); await new Promise(resolve => { release = resolve; }); return response; },
  };
  vm.runInNewContext(source, context);
  return { button, feedback, context, submit: () => submit({ preventDefault() {} }), calls: () => calls, release: () => release() };
}

test('A double submit sends one request even with a form control named action', async () => {
  const app = await setup({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ message: 'Registrato', redirect: '/balance' }) });
  const first = app.submit(); await app.submit();
  assert.equal(app.calls(), 1); assert.equal(app.button.disabled, true);
  app.release(); await first;
  assert.equal(app.context.redirect, '/balance');
});

test('A throttled save displays the wait time and never automatically retries', async () => {
  const app = await setup({ ok: false, status: 429, headers: { get: key => key === 'Retry-After' ? '12' : 'application/json' }, json: async () => ({}) });
  const first = app.submit(); app.release(); await first;
  assert.match(app.feedback.textContent, /12 secondi/);
  assert.equal(app.button.disabled, false); assert.equal(app.calls(), 1);
});
