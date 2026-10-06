import { applyOrderSnapshot } from './order-snapshot';
import { closeDialog } from './dialogs';

const pending = new Set();
document.addEventListener('submit', async event => {
    const form = event.target;
    if (!form.matches('[data-order-action]') || event.defaultPrevented) return;
    event.preventDefault();
    const orderId = document.querySelector('[data-live-order]')?.dataset.liveOrder;
    if (pending.has(orderId)) return;
    pending.add(orderId);
    const scroll = { x: window.scrollX, y: window.scrollY };
    const previousAnchor = document.documentElement.style.overflowAnchor;
    document.documentElement.style.overflowAnchor = 'none';
    const button = event.submitter;
    const dialog = form.closest('dialog');
    let feedback = form.querySelector('[data-order-feedback]');
    if (!feedback) {
        feedback = document.createElement('p');
        feedback.dataset.orderFeedback = '';
        feedback.className = 'order-action-feedback';
        feedback.setAttribute('role', 'status');
        form.append(feedback);
    }
    const data = new FormData(form);
    form.setAttribute('aria-busy', 'true');
    if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
    feedback.classList.remove('text-danger');
    feedback.textContent = 'Aggiornamento in corso…';
    try {
        const response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || response.redirected) {
            const message = response.status === 401 || response.status === 419 ? 'Sessione scaduta. Accedi nuovamente.' : Object.values(result.errors || {}).flat().join(' ') || result.message || 'Aggiornamento non riuscito. Riprova.';
            throw new Error(message);
        }
        form.removeAttribute('aria-busy');
        if (dialog) closeDialog(dialog);
        applyOrderSnapshot(result.html, { force: true, scroll });
        const region = document.querySelector('#next-action') || document.querySelector('[data-live-order]');
        const success = document.createElement('p'); success.className = 'order-action-feedback text-success'; success.setAttribute('role', 'status'); success.textContent = result.message;
        region?.append(success);
        region?.querySelector('.workflow-primary-actions > button.action-confirm, .rider-assignment-controls > button.action-confirm')?.focus({ preventScroll: true });
        window.dispatchEvent(new CustomEvent('ea:workspace-updated', { detail: { order_slug: orderId, kind: 'order-action' } }));
    } catch (error) {
        feedback.classList.add('text-danger');
        feedback.textContent = error instanceof TypeError ? 'Connessione interrotta. I dati sono conservati: riprova.' : error.message;
    } finally {
        pending.delete(orderId);
        window.scrollTo({ left: scroll.x, top: scroll.y, behavior: 'instant' });
        requestAnimationFrame(() => { window.scrollTo({ left: scroll.x, top: scroll.y, behavior: 'instant' }); document.documentElement.style.overflowAnchor = previousAnchor; });
        form.removeAttribute('aria-busy');
        if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
    }
});

const interactive = 'a,button,input,select,textarea,label,summary,[role="button"],[contenteditable], [data-dialog-open]';
document.addEventListener('click', event => {
    const row = event.target.closest('[data-order-link]');
    if (!row || event.defaultPrevented || event.button !== 0 || event.target.closest(interactive) || window.getSelection()?.toString()) return;
    if (event.ctrlKey || event.metaKey) window.open(row.dataset.orderLink, '_blank', 'noopener');
    else location.assign(row.dataset.orderLink);
});
document.addEventListener('keydown', event => {
    if (!event.target.matches('[data-order-link]') || !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    location.assign(event.target.dataset.orderLink);
});
