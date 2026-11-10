const message = 'Al momento non ci sono ordini da stampare';
function notify(control) {
    let status = control.closest('form, header, article')?.querySelector('[data-label-status]');
    if (!status) {
        status = document.createElement('span');
        status.dataset.labelStatus = '';
        status.className = 'label-availability-message';
        status.setAttribute('role', 'status');
        control.parentElement.append(status);
    }
    status.textContent = message;
}
function sync(form) {
    const available = !!form.querySelector('input[name="ids[]"]:checked');
    form.querySelector('[data-label-availability]')?.setAttribute('aria-disabled', String(!available));
    return available;
}
document.addEventListener('click', event => {
    const control = event.target.closest('[data-label-availability]');
    if (!control) return;
    const form = control.closest('[data-label-selection]');
    if (form) sync(form);
    if (control.getAttribute('aria-disabled') === 'true') {
        event.preventDefault();
        event.stopImmediatePropagation();
        notify(control);
    }
}, true);
document.addEventListener('submit', event => {
    if (event.target.matches('[data-label-selection]') && !sync(event.target)) {
        event.preventDefault();
        notify(event.target.querySelector('[data-label-availability]'));
    }
}, true);
document.addEventListener('change', event => {
    const form = event.target.closest('[data-label-selection]');
    if (form) sync(form);
});
document.addEventListener('click', event => {
    const form = event.target.closest('[data-label-selection]');
    if (form && event.target.closest('[data-select-labels]')) queueMicrotask(() => sync(form));
});
