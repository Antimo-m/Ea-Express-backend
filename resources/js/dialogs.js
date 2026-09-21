export function openDialog(dialog) {
    dialog.returnFocus = document.activeElement;
    dialog.showModal();
    dialog.querySelector('input:not([disabled]), button[data-dialog-close]')?.focus();
}
export function closeDialog(dialog) {
    if (dialog.getAttribute('aria-busy') === 'true') return;
    dialog.close();
    if (dialog.returnFocus?.isConnected) dialog.returnFocus.focus();
    else document.querySelector('[data-rate-create]')?.focus();
}
document.addEventListener('click', event => {
    const button = event.target.closest('[data-dialog-close]');
    if (button) closeDialog(button.closest('dialog'));
});
for (const dialog of document.querySelectorAll('dialog')) {
    dialog.addEventListener('cancel', event => { event.preventDefault(); closeDialog(dialog); });
}

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
