import { openDialog, closeDialog } from './dialogs';
const editor = document.querySelector('#rate-editor');
const archive = document.querySelector('#rate-archive');
const history = document.querySelector('#rate-history');
const form = document.querySelector('[data-rate-form]');
const feedback = document.querySelector('[data-rate-feedback]');
let selected;
let pending = false;
const money = value => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(value / 100);
async function request(url, method = 'GET', data) {
    const response = await fetch(url, {method, credentials:'same-origin', headers:{Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body: data ? JSON.stringify(data) : undefined});
    const result = await response.json().catch(() => ({}));
    if (!response.ok || response.redirected) {
        const error = new Error(response.status >= 500 ? 'Il servizio non è disponibile. Verifica il listino prima di riprovare.' : response.status === 429 ? 'Troppe operazioni ravvicinate. Attendi prima di riprovare.' : response.status === 409 ? 'La tariffa è cambiata. Chiudi la finestra e apri la card aggiornata.' : response.status === 419 || response.status === 401 || response.redirected ? 'Sessione scaduta. Accedi nuovamente.' : Object.values(result.errors || {}).flat().join(' ') || result.message || 'Operazione non riuscita. Riprova.');
        error.fields = result.errors; error.status = response.status; throw error;
    }
    return result;
}
async function refresh() {
    const response = await fetch(location.href, {headers:{Accept:'text/html'}, credentials:'same-origin'});
    if (!response.ok || response.redirected) throw new Error('Ricarica la pagina per vedere il listino aggiornato.');
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const results = page.querySelector('#rates-results');
    if (!results) throw new Error('Ricarica la pagina per vedere il listino aggiornato.');
    document.querySelector('#rates-results').replaceWith(results);
}
async function mutate(dialog, operation) {
    if (pending) return;
    pending = true;
    dialog?.setAttribute('aria-busy','true');
    const buttons = [...(dialog || document.querySelector('#rates-results')).querySelectorAll('button')];
    buttons.forEach(button => button.disabled = true);
    const errorNode = dialog?.querySelector('[data-modal-error]') || feedback;
    errorNode.textContent = '';
    let saved = false;
    try {
        await operation(); saved = true;
        await refresh();
        feedback.textContent = 'Listino aggiornato. Le spedizioni precedenti mantengono il prezzo salvato.';
        dialog?.removeAttribute('aria-busy');
        if (dialog) closeDialog(dialog);
    } catch (error) {
        errorNode.textContent = saved ? 'Operazione salvata. Ricarica la pagina per aggiornare le card.' : error instanceof TypeError ? 'Connessione interrotta. Verifica il listino prima di riprovare.' : error.message;
        if (error.fields && form) {
            for (const name of Object.keys(error.fields)) form.elements.namedItem(name)?.setAttribute('aria-invalid','true');
            form.querySelector('[aria-invalid="true"]')?.focus();
        }
        if (error.status === 409) await refresh().catch(() => {});
    } finally {
        pending = false; dialog?.removeAttribute('aria-busy');
        buttons.forEach(button => button.disabled = false);
    }
}
if (editor) {
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-rate-create],[data-rate-edit],[data-rate-archive],[data-rate-toggle],[data-rate-history]');
        if (!button || pending) return;
        selected = button.closest('[data-rate]') ? JSON.parse(button.closest('[data-rate]').dataset.rate) : null;
        if (button.matches('[data-rate-create],[data-rate-edit]')) {
            form.reset(); form.querySelector('[data-modal-error]').textContent = '';
            form.querySelectorAll('[aria-invalid]').forEach(input => input.removeAttribute('aria-invalid'));
            for (const name of ['city','area','postal_code','zone','street','delivery_time','source_reference']) form.elements.namedItem(name).value = selected?.[name] || '';
            form.elements.price.value = selected ? (selected.price_cents / 100).toFixed(2) : '';
            form.elements.active.checked = selected ? selected.active : true;
            editor.querySelector('h2').textContent = selected ? 'Modifica tariffa' : 'Nuova tariffa';
            openDialog(editor);
        } else if (button.matches('[data-rate-archive]')) {
            archive.querySelector('[data-modal-error]').textContent = '';
            archive.querySelector('[data-archive-summary]').textContent = [selected.city, selected.zone, selected.postal_code, money(selected.price_cents)].filter(Boolean).join(' · ');
            openDialog(archive);
        } else if (button.matches('[data-rate-toggle]')) {
            const rate = selected;
            await mutate(null, () => request(`/rates/${rate.id}/state`, 'PATCH', {active:!rate.active}));
        } else {
            const content = history.querySelector('[data-history-content]'); content.textContent = 'Caricamento…'; openDialog(history);
            try {
                const result = await request(`/rates/${selected.id}/history`);
                content.replaceChildren(...result.data.map(rate => {const row = document.createElement('p'); row.className = 'history-row'; row.textContent = `${new Date(rate.created_at).toLocaleString('it-IT')} · ${money(rate.price_cents)} · ${rate.archived_at ? 'Archiviata' : rate.active ? 'Attiva' : 'Inattiva / sostituita'} · ${rate.source_reference}`; return row;}));
            } catch(error) {content.textContent = error.message;}
        }
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(form)); data.active = form.elements.active.checked;
        for (const name of ['area','postal_code','zone','street','delivery_time']) if (!data[name]) data[name] = null;
        const id = selected?.id;
        mutate(editor, () => request(id ? `/rates/${id}` : '/rates', 'POST', data));
    });
    document.querySelector('[data-archive-confirm]').addEventListener('click', () => {const id = selected.id; mutate(archive, () => request(`/rates/${id}`, 'DELETE'));});
}
