import { connectWorkspace } from './workspace-realtime';
import { observeMessageReceipts } from './message-receipts';
import { createRefreshQueue } from './refresh-queue';

const regions = createRefreshQueue(async () => {
  if (!document.querySelector('[data-live-region]')) return;
  const response = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' });
  if (!response.ok || response.redirected) return;
  const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
  for (const region of document.querySelectorAll('[data-live-region]')) {
    const replacement = fresh.querySelector(`[data-live-region="${region.dataset.liveRegion}"]`);
    if (region.contains(document.activeElement) && document.activeElement.matches('input,select,textarea')) continue;
    if (replacement) region.replaceChildren(...replacement.childNodes);
  }
});
let realtimeConnected = false;
window.addEventListener('ea:realtime-status', event => { realtimeConnected = event.detail === 'connected'; });
window.addEventListener('ea:workspace-polled', () => { if (!realtimeConnected) regions.refresh(); });
window.addEventListener('ea:workspace-updated', () => regions.refresh());
export async function staffRequest(path, { method = 'GET', data } = {}) {
  const response = await fetch(path, { method, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, ...(data ? { body: JSON.stringify(data) } : {}) });
  if (!response.ok) throw new Error(response.status === 401 || response.status === 419 ? 'Sessione scaduta. Accedi nuovamente.' : 'Aggiornamento non disponibile. Riprova.');
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
        const article = document.createElement('article'); article.dataset.messageId = message.id;
        article.className = `message-bubble ${message.sender === 'courier' ? 'message-team' : ''}`;
        const author = document.createElement('strong'); author.textContent = message.sender === 'courier' ? 'EA-Express' : 'Cliente';
        const body = document.createElement('p'); body.className = 'message-body mb-1 mt-2'; body.textContent = message.body;
        const meta = document.createElement('small'); meta.textContent = new Date(message.created_at).toLocaleString('it-IT') + (message.sender === 'courier' ? ` · ${message.read_at ? 'Letto' : message.delivered_at ? 'Consegnato' : 'Inviato'}` : '');
        article.append(author, body, meta); list.append(article);
      });
      list.scrollTop = atEnd ? list.scrollHeight : oldScroll;
      if (form) cleanup = observeMessageReceipts(list, result.data, (ids, state) => staffRequest(`${location.pathname}/read`, { method: 'PATCH', data: { ids, state } }), 'courier', error => { feedback.textContent = error.message; });
    } catch (error) { feedback.textContent = error.message; }
    finally { loading = false; if (again) { again = false; refresh(); } }
  }
  window.addEventListener('ea:workspace-updated', event => {
    if (!event.detail.order_id || event.detail.order_id === Number(chat.dataset.liveChat)) refresh();
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
  if (!section || updating || event.detail.kind === 'receipts' || event.detail.kind === 'messages' || (event.detail.order_id && Number(section.dataset.liveOrder) !== event.detail.order_id)) return;
  updating = true;
  try {
    const response = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' } });
    if (!response.ok || response.redirected) return;
    const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
    for (const selector of ['.order-overview', '.order-information', '.order-history']) {
      const current = section.querySelector(selector), replacement = fresh.querySelector(selector);
      if (current && replacement) current.replaceWith(replacement);
    }
    const workflow = section.querySelector('.order-workflow');
    if (workflow && !workflow.contains(document.activeElement)) workflow.replaceWith(fresh.querySelector('.order-workflow'));
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
