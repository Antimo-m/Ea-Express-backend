import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

async function setup(response, submitter, saved = null) {
  let submit, calls = 0, release;
  const attributes = new Map([['aria-label', 'Salva']]);
  const node = (name, children = []) => ({ name, children, cloneNode(deep) { assert.equal(deep, true); return node(this.name, this.children.map(child => child.cloneNode(true))); } });
  const button = {
    childNodes: [node('svg', [node('path')]), node('span', [node('Salva')])], disabled: false,
    get innerHTML() { throw new Error('HTML parsing is forbidden'); },
    set innerHTML(value) { throw new Error('HTML parsing is forbidden'); },
    getAttribute: key => attributes.get(key) ?? null,
    setAttribute: (key, value) => attributes.set(key, value), removeAttribute: key => attributes.delete(key),
    replaceChildren(...nodes) { this.childNodes = nodes; },
  };
  const feedback = { dataset: {}, setAttribute() {} };
  const form = {
    dataset: {}, action: { name: 'action', value: 'receive' },
    getAttribute: () => '/payments/24369069-5868-49fd-b04c-3709fa3b5a81',
    closest: () => null, querySelector: () => button, append() {}, setAttribute() {}, removeAttribute() {},
    addEventListener: (name, fn) => { if (name === 'submit') submit = fn; },
  };
  const source = (await readFile(new URL('../../resources/js/financial-forms.js', import.meta.url), 'utf8')).replace(/^import .*;\n/gm, '').replace('export function', 'function');
  const context = {
    document: { addEventListener() {}, querySelectorAll: () => [form], querySelector: selector => selector === 'main' ? null : form, prepend: notice => { context.notice = notice; }, createElement: () => feedback },
    FormData: class { append(name, value) { context.submitted = { name, value }; } },
    sessionStorage: { getItem: () => saved, setItem() {}, removeItem() {} },
    URL, location: { href: 'https://ea.example.test/balance', assign: url => { context.redirect = url; } },
    refreshWorkspace: async ({form: refreshed}) => { context.refreshedForm = refreshed; },
    workspaceFeedback: message => { context.savedMessage = message; },
    showFieldErrors: (_, errors) => { context.fieldErrors = errors; },
    window: { dispatchEvent: event => { context.updatedEvent = event; } }, CustomEvent,
    fetch: async url => { calls++; assert.equal(url, '/payments/24369069-5868-49fd-b04c-3709fa3b5a81'); await new Promise(resolve => { release = resolve; }); return response; },
  };
  vm.runInNewContext(source, context);
  return { button, feedback, context, form, submit: () => submit({ preventDefault() {}, submitter: submitter ? Object.assign(button, submitter) : undefined }), calls: () => calls, release: () => release() };
}

test('A double submit sends one request even with a form control named action', async () => {
  const app = await setup({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ message: 'Registrato', redirect: '/balance' }) });
  const first = app.submit(); await app.submit();
  assert.equal(app.calls(), 1); assert.equal(app.button.disabled, true);
  app.release(); await first;
  assert.equal(app.context.refreshedForm, app.form);
  assert.equal(app.context.savedMessage, 'Registrato');
  assert.equal(app.context.updatedEvent.type, 'ea:workspace-updated');
  assert.equal(app.context.redirect, undefined);
});

test('A throttled save displays the wait time and never automatically retries', async () => {
  const app = await setup({ ok: false, status: 429, headers: { get: key => key === 'Retry-After' ? '12' : 'application/json' }, json: async () => ({}) });
  const first = app.submit(); app.release(); await first;
  assert.match(app.feedback.textContent, /12 secondi/);
  assert.equal(app.button.disabled, false); assert.equal(app.calls(), 1);
});

test('The selected action is submitted for a pending account with multiple submit buttons', async () => {
  const app = await setup({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ redirect: '/pending' }) }, { name: 'action', value: 'cancel' });
  const first = app.submit(); app.release(); await first;
  assert.equal(app.context.submitted.name, 'action');
  assert.equal(app.context.submitted.value, 'cancel');
  assert.equal(app.context.refreshedForm, app.form);
  assert.equal(app.context.redirect, undefined);
});

test('Validation errors stay in the form and allow correction without navigating', async () => {
  const app = await setup({ ok: false, status: 422, headers: { get: () => 'application/json' }, json: async () => ({ errors: { amount: ['Importo non valido.'] } }) });
  const first = app.submit(); app.release(); await first;
  assert.equal(app.feedback.textContent, 'Importo non valido.');
  assert.equal(app.context.redirect, undefined);
  assert.equal(app.button.disabled, false);
});

for (const label of [null, '', 'Conferma pagamento']) {
  test(`A failed save preserves nested icons and restores aria-label ${JSON.stringify(label)}`, async () => {
    const payload = '<img src=x onerror=alert(1)>';
    const app = await setup({ ok: false, status: 409, headers: { get: () => 'application/json' }, json: async () => ({ message: payload }) });
    if (label === null) app.button.removeAttribute('aria-label');
    else app.button.setAttribute('aria-label', label);
    const original = app.button.childNodes;
    const first = app.submit();
    assert.equal(app.button.getAttribute('aria-busy'), 'true');
    app.release(); await first;
    assert.equal(app.feedback.textContent, payload);
    assert.equal(app.button.getAttribute('aria-label'), label);
    assert.equal(app.button.getAttribute('aria-busy'), null);
    assert.equal(app.button.disabled, false);
    assert.equal(app.button.childNodes[0].name, 'svg');
    assert.equal(app.button.childNodes[0].children[0].name, 'path');
    assert.equal(app.button.childNodes[1].children[0].name, 'Salva');
    assert.notEqual(app.button.childNodes[0], original[0]);
  });
}

for (const redirect of ['https://evil.example/', '//evil.example/', 'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', 'http://[invalid']) {
  test(`A successful save cannot navigate to ${redirect}`, async () => {
    const app = await setup({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ redirect }) });
    const first = app.submit(); app.release(); await first;
    assert.equal(app.context.redirect, undefined);
    assert.equal(app.context.refreshedForm, app.form);
  });
}

test('Stored feedback is rendered as literal text when rebinding a region without main', async () => {
  const payload = '<script>alert(1)</script>';
  const app = await setup({}, undefined, payload);
  assert.equal(app.context.notice.textContent, payload);
});
