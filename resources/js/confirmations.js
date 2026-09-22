import { openDialog, closeDialog } from './dialogs';
const dialog = document.querySelector('#action-confirmation');
let operation;
let busy = false;
const approved = new WeakSet();
const submitted = new WeakSet();

export function confirmAction({ name, action = 'Elimina', description = '', onConfirm }) {
    if (!dialog || busy) return;
    operation = onConfirm;
    dialog.querySelector('[data-confirm-summary]').textContent = action === 'Elimina' ? `Sei sicuro di voler eliminare «${name}»?` : `Confermi l’operazione «${action}» su «${name}»?`;
    dialog.querySelector('[data-confirm-description]').textContent = description;
    dialog.querySelector('[data-modal-error]').textContent = '';
    dialog.querySelector('[data-confirm-label]').textContent = action;
    openDialog(dialog);
}

if (dialog) {
    document.addEventListener('submit', event => {
        const form = event.target;
        const trigger = event.submitter;
        const destructive = form.querySelector('[name="_method"]')?.value.toLowerCase() === 'delete';
        const action = trigger?.dataset.confirm || form.dataset.confirm;
        if (!destructive && !action) return;
        if (submitted.has(form)) { event.preventDefault(); event.stopImmediatePropagation(); return; }
        if (approved.has(form)) {
            approved.delete(form);
            if (!form.hasAttribute('data-financial-form')) {
                submitted.add(form);
                form.setAttribute('aria-busy', 'true');
                queueMicrotask(() => form.querySelectorAll('button').forEach(button => { button.disabled = true; }));
            }
            return;
        }
        event.preventDefault(); event.stopImmediatePropagation();
        confirmAction({
            action: action || 'Elimina',
            name: trigger?.dataset.confirmName || form.dataset.confirmName || form.closest('article, section')?.querySelector('h2, h3, strong')?.textContent.trim() || 'elemento selezionato',
            description: form.dataset.confirmDescription || 'Controlla i dati prima di confermare. Le registrazioni economiche conservano il proprio storico.',
            onConfirm: () => { approved.add(form); form.requestSubmit(trigger); approved.delete(form); },
        });
    }, true);
    dialog.querySelector('[data-confirm-action]').addEventListener('click', async () => {
        if (busy || !operation) return;
        busy = true;
        dialog.setAttribute('aria-busy', 'true');
        const buttons = [...dialog.querySelectorAll('button')];
        buttons.forEach(button => { button.disabled = true; });
        try {
            await operation();
            dialog.removeAttribute('aria-busy');
            closeDialog(dialog);
        } catch (error) {
            dialog.querySelector('[data-modal-error]').textContent = error instanceof TypeError ? 'Connessione interrotta. Verifica i dati prima di riprovare.' : error.message || 'Operazione non riuscita. Riprova.';
        } finally {
            busy = false;
            dialog.removeAttribute('aria-busy');
            buttons.forEach(button => { button.disabled = false; });
        }
    });
    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        document.querySelectorAll('form[aria-busy="true"]').forEach(form => {
            submitted.delete(form); form.removeAttribute('aria-busy');
            form.querySelectorAll('button').forEach(button => { button.disabled = false; });
        });
    });
}
