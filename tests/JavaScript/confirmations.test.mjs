import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

async function setup() {
  const listeners = new Map();
  const controls = new Map();
  const attributes = new Map();
  const confirm = {
    disabled: false, dataset: {}, classList: {toggle() {}, add() {}},
    replaceChildren() {}, append() {}, setAttribute() {}, removeAttribute() {},
    parentElement: {prepend() {}, append() {}},
    addEventListener: (_, callback) => { confirm.click = callback; },
  };
  const cancel = { disabled: false };
  const dialog = {
    open: false,
    setAttribute: (key, value) => attributes.set(key, value),
    removeAttribute: key => attributes.delete(key),
    querySelectorAll: () => [confirm, cancel],
    querySelector: selector => {
      if (selector === '[data-confirm-action]') return confirm;
      if (!controls.has(selector)) controls.set(selector, { textContent: '' });
      return controls.get(selector);
    },
  };
  const source = (await readFile(new URL('../../resources/js/confirmations.js', import.meta.url), 'utf8'))
    .replace(/^import .*;\n/gm, '').replace('export function', 'function');
  const context = {
    document: { createElement: () => ({setAttribute() {}}), querySelector: () => dialog, addEventListener: (name, callback) => listeners.set(name, callback) },
    window: { addEventListener() {} },
    openDialog: () => { dialog.open = true; },
    closeDialog: () => { dialog.open = false; },
    handlesWorkspaceForm: () => false,
    queueMicrotask, Error, TypeError,
  };
  vm.runInNewContext(source, context);
  return { context, dialog, confirm, cancel, controls, submit: listeners.get('submit') };
}

function formEvent(form, submitter) {
  return { target: form, submitter, prevented: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() {} };
}

test('A destructive submission waits for confirmation and preserves the original action button', async () => {
  const app = await setup();
  let sends = 0;
  const trigger = { dataset: { confirm: 'Annulla sospeso', confirmName: 'Cliente Napoli' }, disabled: false };
  const form = {
    dataset: {}, querySelector: () => ({ value: 'patch' }), hasAttribute: () => false,
    setAttribute() {}, querySelectorAll: () => [trigger],
    requestSubmit(button) {
      assert.equal(button, trigger);
      const event = formEvent(form, button);
      app.submit(event);
      if (!event.prevented) sends++;
    },
  };
  const initial = formEvent(form, trigger);
  app.submit(initial);
  assert.equal(initial.prevented, true);
  assert.equal(app.dialog.open, true);
  assert.equal(sends, 0);
  assert.match(app.controls.get('[data-confirm-summary]').textContent, /Cliente Napoli/);

  await Promise.all([app.confirm.click(), app.confirm.click()]);
  assert.equal(sends, 1);
  assert.equal(app.dialog.open, false);
  assert.equal(trigger.disabled, true);
  const repeated = formEvent(form, trigger);
  app.submit(repeated);
  assert.equal(repeated.prevented, true);
});

test('API confirmation locks the dialog and sends only one operation until the response arrives', async () => {
  const app = await setup();
  let calls = 0, release;
  app.context.confirmAction({ name: 'Napoli', onConfirm: () => {
    calls++;
    return new Promise(resolve => { release = resolve; });
  } });
  const pending = app.confirm.click();
  await app.confirm.click();
  assert.equal(calls, 1);
  assert.equal(app.confirm.disabled, true);
  assert.equal(app.cancel.disabled, true);
  assert.equal(app.dialog.open, true);
  release();
  await pending;
  assert.equal(app.dialog.open, false);
  assert.equal(app.confirm.disabled, false);
});

test('An API failure stays in the dialog and allows an explicit retry without automatic resubmission', async () => {
  const app = await setup();
  let calls = 0;
  app.context.confirmAction({ name: 'Tariffa Napoli', onConfirm: async () => {
    if (++calls === 1) throw new Error('Operazione non riuscita. Riprova.');
  } });
  await app.confirm.click();
  assert.equal(calls, 1);
  assert.equal(app.dialog.open, true);
  assert.equal(app.confirm.disabled, false);
  assert.equal(app.controls.get('[data-modal-error]').textContent, 'Operazione non riuscita. Riprova.');
  await app.confirm.click();
  assert.equal(calls, 2);
  assert.equal(app.dialog.open, false);
});

test('Ordinary edits do not require destructive confirmation', async () => {
  const app = await setup();
  const event = formEvent({ dataset: {}, querySelector: () => ({ value: 'patch' }) }, { dataset: {} });
  app.submit(event);
  assert.equal(event.prevented, false);
  assert.equal(app.dialog.open, false);
});
