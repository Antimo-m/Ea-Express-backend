import { openDialog, closeDialog } from './dialogs';
const dialog = document.querySelector('#action-confirmation');
let target;
let submitter;
if (dialog) {
    document.addEventListener('submit', event => {
        const form = event.target;
        if (form.dataset.confirmed === 'true') {delete form.dataset.confirmed; return;}
        if (form.querySelector('[name="_method"]')?.value.toLowerCase() !== 'delete') return;
        event.preventDefault(); event.stopImmediatePropagation();
        target = form; submitter = event.submitter;
        dialog.querySelector('[data-confirm-summary]').textContent = form.closest('article, section')?.querySelector('h2, h3, strong')?.textContent || 'Conferma l’operazione selezionata.';
        openDialog(dialog);
    }, true);
    dialog.querySelector('[data-confirm-action]').addEventListener('click', () => {
        closeDialog(dialog); target.dataset.confirmed = 'true';
        target.requestSubmit(submitter);
    });
}
