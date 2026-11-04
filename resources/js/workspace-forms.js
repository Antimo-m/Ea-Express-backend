import { applyWorkspaceSnapshot, refreshWorkspace, workspaceFeedback } from './workspace-updates';

const pending = new WeakSet();
const specialized = '[data-financial-form],[data-order-action],[data-rate-form],[data-prevent-repeat],.message-composer,.staff-booking-form';

export function handlesWorkspaceForm(form) {
    if (!document.body.classList.contains('ea-app') || !form.closest('main') || form.method.toLowerCase() === 'get' || form.matches(specialized)) return false;
    const target = new URL(form.action, location.href);
    if (target.origin !== location.origin) return false;
    const method = form.querySelector('[name="_method"]')?.value.toLowerCase();
    if (['/logout', '/orders', '/orders/checkout/edit'].includes(target.pathname) || (target.pathname === '/profile' && method === 'delete')) return false;
    return !/^\/notifications\/[^/]+$/.test(target.pathname) || target.pathname.endsWith('/read-all');
}

document.addEventListener('submit', async event => {
    const form = event.target;
    if (event.defaultPrevented || !handlesWorkspaceForm(form)) return;
    event.preventDefault();
    if (pending.has(form)) return;
    pending.add(form);
    const data = new FormData(form);
    if (event.submitter?.name) data.append(event.submitter.name, event.submitter.value);
    const buttons = [...form.querySelectorAll('button')];
    const states = buttons.map(button => button.disabled);
    buttons.forEach(button => { button.disabled = true; });
    form.setAttribute('aria-busy', 'true');
    let saved = false;
    let feedback = form.querySelector('[data-workspace-form-feedback]');
    if (!feedback) { feedback = document.createElement('p'); feedback.dataset.workspaceFormFeedback = ''; feedback.className = 'form-feedback'; form.append(feedback); }
    feedback.textContent = ''; feedback.setAttribute('role', 'status');
    try {
        const response = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const json = response.headers.get('content-type')?.includes('application/json');
        const result = json ? await response.json() : null;
        const destination = new URL(response.url);
        if (response.redirected && destination.origin === location.origin && /^\/(login|verify-email-otp)(\/|$)/.test(destination.pathname)) {
            location.assign(destination.href);
            return;
        }
        if (!response.ok) {
            throw new Error(Object.values(result?.errors || {}).flat().join(' ') || result?.message || (response.status === 419 || response.status === 401 ? 'Sessione scaduta. Accedi nuovamente.' : 'Operazione non riuscita. Riprova.'));
        }
        saved = true;
        form.removeAttribute('aria-busy');
        form.closest('dialog')?.close();
        if (!json && destination.pathname === location.pathname && destination.search === location.search) {
            applyWorkspaceSnapshot(new DOMParser().parseFromString(await response.text(), 'text/html'), { form });
        } else {
            await refreshWorkspace({ form });
        }
        workspaceFeedback(result?.message || document.querySelector('[data-workspace-feedback]')?.textContent || 'Operazione completata.');
        window.dispatchEvent(new CustomEvent('ea:workspace-updated', { detail: { kind: 'workspace-mutation' } }));
    } catch (error) {
        if (saved) {
            workspaceFeedback('Operazione salvata. Non è stato possibile aggiornare i dati visualizzati.', { error: true, retry: () => refreshWorkspace({ form }) });
        } else {
            feedback.textContent = error instanceof TypeError ? 'Connessione interrotta. Verifica lo stato prima di riprovare.' : error.message;
            feedback.setAttribute('role', 'alert');
        }
    } finally {
        if (!saved) buttons.forEach((button, index) => { button.disabled = states[index]; });
        pending.delete(form);
        form.removeAttribute('aria-busy');
    }
});
