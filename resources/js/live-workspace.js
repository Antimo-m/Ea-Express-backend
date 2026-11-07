import { cancelToast, transientFeedback } from './toast-feedback';
import { restoreOrderPreviews } from './operational-orders';
import { applyOrderSnapshot } from './order-snapshot';
import { connectWorkspace } from './workspace-realtime';
import { observeMessageReceipts } from './message-receipts';
import { createRefreshQueue } from './refresh-queue';
import { bindFinancialForms } from './financial-forms';

const regions = createRefreshQueue(async () => {
  if (document.querySelector('.order-archive')) return;
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
      const focusedConversation = focused?.closest('[data-conversation-link]')?.dataset.conversationLink;
      const conversationScroll = region.querySelector('[data-scroll-key="conversations"]')?.scrollTop;
      const currentConversation = region.querySelector('[data-conversation-link][aria-current]');
      const currentCopy = currentConversation?.cloneNode(true);
      const focusSlug = focused?.closest('[data-operational-order]')?.dataset.operationalOrder;
      const pickupDate = focused?.closest('[data-pickup-date]')?.dataset.pickupDate;
      const focusPreview = focused?.matches('[data-order-preview-toggle]');
      region.replaceChildren(...replacement.childNodes);
      if (region.dataset.currentConversation) {
        const selected = [...region.querySelectorAll('[data-conversation-link]')].find(link => link.dataset.conversationLink === region.dataset.currentConversation);
        region.querySelectorAll('[data-conversation-link]').forEach(link => link.removeAttribute('aria-current'));
        if (selected) selected.setAttribute('aria-current', 'true');
        else if (currentCopy) region.querySelector('[data-inbox-list]')?.prepend(currentCopy);
      }
      if (conversationScroll !== undefined) region.querySelector('[data-scroll-key="conversations"]').scrollTop = conversationScroll;
      if (focusedConversation) [...region.querySelectorAll('[data-conversation-link]')].find(link => link.dataset.conversationLink === focusedConversation)?.focus({ preventScroll: true });
      restoreOrderPreviews(region, openSlugs);
      for (const row of region.querySelectorAll('[data-operational-order]')) {
        const previous = oldRows.get(row.dataset.operationalOrder);
        if (previous !== undefined && previous !== row.dataset.orderState) row.classList.add('is-updated');
        if (previous === undefined) row.classList.add('is-new');
        if (row.dataset.operationalOrder === focusSlug) row.querySelector(focusPreview ? '[data-order-preview-toggle]' : '[data-order-link]')?.focus({ preventScroll: true });
      }
      if (pickupDate) region.querySelector(`[data-pickup-date="${pickupDate}"]`)?.focus({ preventScroll: true });
      window.dispatchEvent(new CustomEvent('ea:order-list-rendered', { detail: { region: region.dataset.liveRegion } }));
    }
    bindFinancialForms(region);
  }
});
let realtimeConnected = false;
window.addEventListener('ea:realtime-status', event => { realtimeConnected = event.detail === 'connected'; });
window.addEventListener('ea:workspace-polled', () => { if (!realtimeConnected) regions.refresh(); });
window.addEventListener('ea:workspace-updated', event => { if (event.detail?.kind !== 'workspace-mutation') regions.refresh(); });
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
  const messagesUrl = new URL(chat.dataset.messagesUrl || location.href, location.origin);
  const list = chat.querySelector('[data-conversation-list]');
  const form = chat.querySelector('.message-composer');
  const feedback = document.createElement('p'); feedback.className = 'small text-secondary'; feedback.setAttribute('role', 'status'); list.after(feedback);
  let loading = false, again = false, cleanup = () => {};
  async function refresh() {
    if (loading) { again = true; return; }
    loading = true;
    list.setAttribute('aria-busy', 'true');
    try {
      const result = await staffRequest(messagesUrl.pathname + messagesUrl.search);
      const atEnd = list.scrollHeight - list.scrollTop - list.clientHeight < 60;
      const oldScroll = list.scrollTop;
      cleanup();
      list.replaceChildren();
      [...result.data].reverse().forEach(message => {
        const article = document.createElement('article'); article.dataset.messageId = message.slug;
        article.className = `message-bubble ${message.sender === 'team' ? 'message-team' : ''}`;
        const header = document.createElement('header'); header.className = 'message-meta';
        const author = document.createElement('strong'); author.textContent = message.sender === 'team' ? 'EA-Express' : 'Cliente';
        const time = document.createElement('time'); time.dateTime = message.created_at;
        time.textContent = new Date(message.created_at).toLocaleString('it-IT', { timeZone: 'Europe/Rome', dateStyle: 'short', timeStyle: 'short' });
        header.append(author, time);
        const body = document.createElement('p'); body.className = 'message-body'; body.textContent = message.body;
        const receipt = document.createElement('small'); receipt.className = 'message-receipt';
        receipt.textContent = message.sender === 'team' ? (message.read_at ? 'Letto' : message.delivered_at ? 'Consegnato' : 'Inviato') : (message.read_at ? 'Letto dal team' : 'Da leggere');
        article.append(header, body, receipt); list.append(article);
      });
      list.scrollTop = atEnd ? list.scrollHeight : oldScroll;
      if (form) cleanup = observeMessageReceipts(list, result.data, (ids, state) => staffRequest(`${messagesUrl.pathname}/read`, { method: 'PATCH', data: { ids, state } }), 'team', error => { feedback.textContent = error.message; });
      if (feedback.getAttribute('role') === 'alert') { feedback.textContent = ''; feedback.setAttribute('role', 'status'); }
    } catch (error) { cancelToast(feedback); feedback.hidden = false; feedback.textContent = error.message; feedback.setAttribute('role', 'alert'); }
    finally { loading = false; list.removeAttribute('aria-busy'); if (again) { again = false; refresh(); } }
  }
  chat.querySelector('[data-chat-refresh]')?.addEventListener('click', refresh);
  window.addEventListener('ea:workspace-updated', event => {
    if (!event.detail.order_slug || event.detail.order_slug === chat.dataset.liveChat) refresh();
  });
  let submissionKey, submittedBody;
  form?.addEventListener('submit', async event => {
    event.preventDefault(); const button = form.querySelector('button'); if (button.disabled) return;
    if (!submissionKey || submittedBody !== form.elements.body.value) { submissionKey = crypto.randomUUID(); submittedBody = form.elements.body.value; }
    button.disabled = true; cancelToast(feedback); feedback.hidden = false; feedback.setAttribute('role', 'status'); feedback.textContent = 'Invio in corso…';
    try { await staffRequest(form.action, { method: 'POST', data: { body: submittedBody, submission_key: submissionKey } }); if (form.elements.body.value === submittedBody) form.elements.body.value = ''; submissionKey = undefined; transientFeedback(feedback, 'Messaggio inviato'); list.scrollTop = list.scrollHeight; await refresh(); }
    catch (error) { cancelToast(feedback); feedback.hidden = false; feedback.textContent = error.message; feedback.setAttribute('role', 'alert'); }
    finally { button.disabled = false; }
  });
  refresh();
}
const orderRefresh = createRefreshQueue(async () => {
  const section = document.querySelector('[data-live-order]');
  if (!section || section.querySelector('dialog[open], form[aria-busy="true"], .ea-picker-trigger[aria-expanded="true"]')) return;
  const response = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' } });
  if (!response.ok || response.redirected) return;
  const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
  if (section.querySelector('dialog[open], form[aria-busy="true"], .ea-picker-trigger[aria-expanded="true"]')) return;
  applyOrderSnapshot(fresh.documentElement.outerHTML);
});
const refreshOrder = ({ detail = {} } = {}) => {
  const section = document.querySelector('[data-live-order]');
  if (!section || ['order-action', 'receipts', 'messages', 'workspace-mutation'].includes(detail.kind) || (detail.order_slug && section.dataset.liveOrder !== detail.order_slug)) return;
  return orderRefresh.refresh();
};
window.addEventListener('ea:workspace-updated', refreshOrder);
window.addEventListener('ea:workspace-polled', () => { if (!realtimeConnected) refreshOrder(); });
window.addEventListener('pageshow', event => { if (event.persisted) refreshOrder(); });
document.addEventListener('close', event => { if (event.target.closest('[data-live-order]')) refreshOrder(); }, true);

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
  if (!container || notificationsRefreshing || ['receipts', 'workspace-mutation'].includes(event.detail?.kind)) return;
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
