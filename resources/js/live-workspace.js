import { restoreOrderPreviews } from './operational-orders';
import { applyOrderSnapshot } from './order-snapshot';
import { connectWorkspace } from './workspace-realtime';
import { observeMessageReceipts } from './message-receipts';
import { createRefreshQueue } from './refresh-queue';
import { bindFinancialForms } from './financial-forms';

const regions = createRefreshQueue(async () => {
  if (!document.querySelector('[data-live-region]')) return;
  const response = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' });
  if (!response.ok || response.redirected) return;
  const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
  for (const region of document.querySelectorAll('[data-live-region]')) {
    const replacement = fresh.querySelector(`[data-live-region="${region.dataset.liveRegion}"]`);
    if (region.querySelector('dialog[open], form[aria-busy="true"], .ea-picker-trigger[aria-expanded="true"]') || (region.contains(document.activeElement) && document.activeElement.matches('input,select,textarea,.ea-picker-trigger'))) continue;
    if (replacement) {
      const oldRows = new Map([...region.querySelectorAll('[data-operational-order]')].map(row => [row.dataset.operationalOrder, row.dataset.orderState]));
      const openSlugs = [...region.querySelectorAll('[data-order-preview-toggle][aria-expanded="true"]')].map(button => button.closest('[data-operational-order]').dataset.operationalOrder);
      const focused = document.activeElement;
      const focusSlug = focused?.closest('[data-operational-order]')?.dataset.operationalOrder;
      const focusPreview = focused?.matches('[data-order-preview-toggle]');
      region.replaceChildren(...replacement.childNodes);
      restoreOrderPreviews(region, openSlugs);
      for (const row of region.querySelectorAll('[data-operational-order]')) {
        const previous = oldRows.get(row.dataset.operationalOrder);
        if (previous !== undefined && previous !== row.dataset.orderState) row.classList.add('is-updated');
        if (previous === undefined) row.classList.add('is-new');
        if (row.dataset.operationalOrder === focusSlug) row.querySelector(focusPreview ? '[data-order-preview-toggle]' : '[data-order-link]')?.focus({ preventScroll: true });
      }
      window.dispatchEvent(new CustomEvent('ea:order-list-rendered', { detail: { region: region.dataset.liveRegion } }));
    }
    bindFinancialForms(region);
  }
});
let realtimeConnected = false;
window.addEventListener('ea:realtime-status', event => { realtimeConnected = event.detail === 'connected'; });
window.addEventListener('ea:workspace-polled', () => { if (!realtimeConnected) regions.refresh(); });
window.addEventListener('ea:workspace-updated', () => regions.refresh());
export async function staffRequest(path, { method = 'GET', data } = {}) {
  const response = await fetch(path, { method, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, ...(data ? { body: JSON.stringify(data) } : {}) });
  if (!response.ok) { const error = new Error(response.status === 401 || response.status === 419 ? 'Sessione scaduta. Accedi nuovamente.' : 'Aggiornamento non disponibile. Riprova.'); error.status = response.status; throw error; }
  return response.json();
}
if (document.body.dataset.notificationsUrl) {
  const disconnect = connectWorkspace(staffRequest);
  window.addEventListener('pagehide', () => disconnect());
  window.addEventListener('pageshow', event => { if (event.persisted) connectWorkspace(staffRequest); });
}
const chat = document.querySelector('[data-live-chat]');
if (chat) {
  const list = chat.querySelector('[data-conversation-list]');
  const form = chat.querySelector('.message-composer');
  const feedback = document.createElement('p'); feedback.className = 'small text-secondary'; feedback.setAttribute('role', 'status'); list.after(feedback);
  let loading = false, again = false, cleanup = () => {};
  async function refresh() {
    if (loading) { again = true; return; }
    loading = true;
    try {
      const result = await staffRequest(location.pathname + location.search);
      const atEnd = list.scrollHeight - list.scrollTop - list.clientHeight < 60;
      const oldScroll = list.scrollTop;
      cleanup();
      list.replaceChildren();
      [...result.data].reverse().forEach(message => {
        const article = document.createElement('article'); article.dataset.messageId = message.slug;
        article.className = `message-bubble ${message.sender === 'team' ? 'message-team' : ''}`;
        const author = document.createElement('strong'); author.textContent = message.sender === 'team' ? 'EA-Express' : 'Cliente';
        const body = document.createElement('p'); body.className = 'message-body mb-1 mt-2'; body.textContent = message.body;
        const meta = document.createElement('small'); meta.textContent = new Date(message.created_at).toLocaleString('it-IT') + (message.sender === 'team' ? ` · ${message.read_at ? 'Letto' : message.delivered_at ? 'Consegnato' : 'Inviato'}` : '');
        article.append(author, body, meta); list.append(article);
      });
      list.scrollTop = atEnd ? list.scrollHeight : oldScroll;
      if (form) cleanup = observeMessageReceipts(list, result.data, (ids, state) => staffRequest(`${location.pathname}/read`, { method: 'PATCH', data: { ids, state } }), 'team', error => { feedback.textContent = error.message; });
    } catch (error) { feedback.textContent = error.message; }
    finally { loading = false; if (again) { again = false; refresh(); } }
  }
  window.addEventListener('ea:workspace-updated', event => {
    if (!event.detail.order_slug || event.detail.order_slug === chat.dataset.liveChat) refresh();
  });
  let submissionKey, submittedBody;
  form?.addEventListener('submit', async event => {
    event.preventDefault(); const button = form.querySelector('button'); if (button.disabled) return;
    if (!submissionKey || submittedBody !== form.elements.body.value) { submissionKey = crypto.randomUUID(); submittedBody = form.elements.body.value; }
    button.disabled = true; feedback.textContent = 'Invio in corso…';
    try { await staffRequest(form.action, { method: 'POST', data: { body: form.elements.body.value, submission_key: submissionKey } }); form.elements.body.value = ''; submissionKey = undefined; feedback.textContent = 'Messaggio inviato'; list.scrollTop = list.scrollHeight; await refresh(); }
    catch (error) { feedback.textContent = error.message; }
    finally { button.disabled = false; }
  });
  refresh();
}
let updating = false;
window.addEventListener('ea:workspace-updated', async event => {
  const section = document.querySelector('[data-live-order]');
  if (!section || section.querySelector('dialog[open], form[aria-busy="true"], .ea-picker-trigger[aria-expanded="true"]') || updating || event.detail.kind === 'order-action' || event.detail.kind === 'receipts' || event.detail.kind === 'messages' || (event.detail.order_slug && section.dataset.liveOrder !== event.detail.order_slug)) return;
  updating = true;
  try {
    const response = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' } });
    if (!response.ok || response.redirected) return;
    const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
    if (section.querySelector('dialog[open], form[aria-busy="true"], .ea-picker-trigger[aria-expanded="true"]')) return;
    applyOrderSnapshot(fresh.documentElement.outerHTML);
  } finally { updating = false; }
});

const tracking = document.querySelector('[data-public-tracking]');
if (tracking) {
  connectWorkspace((path, options) => staffRequest(location.pathname + path, options));
  window.addEventListener('ea:realtime-status', event => { tracking.querySelector('[data-realtime-state]').textContent = event.detail === 'connected' ? 'Aggiornamenti in tempo reale attivi' : 'Riconnessione agli aggiornamenti…'; });
  let refreshing = false;
  window.addEventListener('ea:workspace-updated', async () => {
    if (refreshing) return;
    refreshing = true;
    try { const response = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' } }); if (!response.ok) return; const fresh = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('.tracking-document'); if (fresh) tracking.querySelector('.tracking-document').replaceWith(fresh); }
    finally { refreshing = false; }
  });
}

let notificationsRefreshing = false;
window.addEventListener('ea:workspace-updated', async event => {
  const container = document.querySelector('[data-live-notifications]');
  if (!container || notificationsRefreshing || event.detail.kind === 'receipts') return;
  notificationsRefreshing = true;
  try {
    const response = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' } }); if (!response.ok || response.redirected) return;
    const updated = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('[data-live-notifications]');
    if (!updated) return;
    const open = new Set([...container.querySelectorAll('details[open]')].map(item => item.dataset.historyUrl));
    container.replaceWith(updated);window.dispatchEvent(new Event('ea:notification-groups-rendered'));
    updated.querySelectorAll('details').forEach(item => { item.open = open.has(item.dataset.historyUrl); });
  } catch { /* Il prossimo evento o la riconnessione recupererà gli aggiornamenti. */ }
  finally { notificationsRefreshing = false; }
});
