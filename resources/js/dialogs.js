export function openDialog(dialog) {
    if (!dialog || dialog.open) return;
    dialog.returnFocus = document.activeElement;
    dialog.showModal();
    dialog.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus();
}
export function closeDialog(dialog) {
    if (dialog.getAttribute('aria-busy') === 'true' || dialog.querySelector('form[aria-busy="true"]')) return;
    dialog.close();
    if (dialog.returnFocus?.isConnected) dialog.returnFocus.focus();
    else document.querySelector('[data-rate-create]')?.focus();
}
document.addEventListener('click', event => {
    const opener = event.target.closest('[data-dialog-open]');
    if (opener) openDialog(document.getElementById(opener.dataset.dialogOpen));
    const button = event.target.closest('[data-dialog-close]');
    if (button) closeDialog(button.closest('dialog'));
});
document.addEventListener('cancel', event => {
    if (!event.target.matches('dialog')) return;
    event.preventDefault();
    closeDialog(event.target);
}, true);
openDialog(document.querySelector('[data-dialog-initial="true"]'));

document.addEventListener('keydown', event => {
    if (event.key !== 'Tab') return;
    const dialog = [...document.querySelectorAll('dialog[open]')].at(-1);
    if (!dialog) return;
    const controls = [...dialog.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href], [tabindex="0"]')].filter(node => node.getClientRects().length);
    const first = controls[0], last = controls.at(-1);
    if (!first) {event.preventDefault(); dialog.focus(); return;}
    if (!dialog.contains(document.activeElement) || (event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
        event.preventDefault(); (event.shiftKey ? last : first).focus();
    }
});
