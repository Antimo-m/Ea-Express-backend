let sequence = 0;
const controls = 'input:not([type="hidden"]), select, textarea';
const describedBy = (field, id, add) => {
    const ids = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
    add ? ids.add(id) : ids.delete(id);
    if (ids.size) field.setAttribute('aria-describedby', [...ids].join(' '));
    else field.removeAttribute('aria-describedby');
};

export function clearFieldError(field) {
    const id = field.dataset.inlineErrorId;
    if (id) {
        document.getElementById(id)?.remove();
        describedBy(field, id, false);
    }
    delete field.dataset.inlineErrorId;
    field.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
    document.dispatchEvent(new Event('ea:controls-updated'));
}

function fieldError(field, message) {
    clearFieldError(field);
    if (!field.id) field.id = `ea-validation-field-${++sequence}`;
    const id = `${field.id}-validation-error`;
    const error = document.createElement('small');
    error.id = id;
    error.className = 'field-error invalid-feedback d-block';
    error.dataset.inlineValidation = '';
    error.textContent = message;
    error.setAttribute('role', 'alert');
    const group = field.closest('.field, .filter-control, .filter-field');
    if (group) group.append(error);
    else (field.nextElementSibling?.classList.contains('ea-picker-trigger') ? field.nextElementSibling : field).after(error);
    field.dataset.inlineErrorId = id;
    field.classList.add('is-invalid');
    field.setAttribute('aria-invalid', 'true');
    describedBy(field, id, true);
    document.dispatchEvent(new Event('ea:controls-updated'));
}

export function focusInvalidField(form) {
    for (const field of form.querySelectorAll('[aria-invalid="true"]')) {
        if (field.disabled || field.type === 'hidden') continue;
        const target = field.classList.contains('ea-picker-source') ? field.nextElementSibling : field;
        if (!target?.getClientRects().length) continue;
        target.focus({ preventScroll: true });
        target.scrollIntoView({ block: 'center', behavior: 'instant' });
        return;
    }
}

export function showFieldErrors(form, errors, { focus = true } = {}) {
    form.querySelectorAll('[data-inline-error-id]').forEach(clearFieldError);
    let matched = false;
    for (const [name, messages] of Object.entries(errors || {})) {
        const bracketName = name.replace(/\.([^.]*)/g, '[$1]');
        const field = [...form.elements].find(control => control.name === name || control.name === bracketName || control.name === `${bracketName}[]`);
        if (!field || !field.matches(controls)) continue;
        fieldError(field, Array.isArray(messages) ? messages.join(' ') : String(messages));
        matched = true;
    }
    if (focus) {
        focusInvalidField(form);
        requestAnimationFrame(() => focusInvalidField(form));
    }
    return matched;
}

function nativeMessage(field) {
    const validity = field.validity;
    if (validity.valueMissing) return 'Questo campo è obbligatorio.';
    if (validity.typeMismatch && field.type === 'email') return 'Inserisci un indirizzo email valido.';
    if (validity.patternMismatch && /postal|cap/i.test(field.name)) return 'Il CAP deve contenere 5 cifre.';
    if (validity.patternMismatch && ['price', 'amount', 'parcel_value', 'received_amount', 'ea_amount', 'carrier_cost'].includes(field.name)) return 'Inserisci un importo valido con al massimo due decimali.';
    if (validity.badInput) return 'Inserisci un valore numerico valido.';
    if (validity.rangeUnderflow) return `Il valore minimo è ${field.min}.`;
    if (validity.rangeOverflow) return `Il valore massimo è ${field.max}.`;
    if (validity.tooLong) return `Inserisci al massimo ${field.maxLength} caratteri.`;
    if (validity.tooShort) return `Inserisci almeno ${field.minLength} caratteri.`;
    return field.validationMessage || 'Controlla il valore inserito.';
}

export function validateForm(form) {
    let valid = true;
    for (const field of form.querySelectorAll(controls)) {
        if (!field.willValidate) continue;
        if (!field.validity.valid) { fieldError(field, nativeMessage(field)); valid = false; }
        else clearFieldError(field);
    }
    if (!valid) focusInvalidField(form);
    return valid;
}

function markRequiredFields() {
    for (const field of document.querySelectorAll(controls)) {
        const required = field.required && !field.disabled;
        if (required) field.setAttribute('aria-required', 'true');
        else field.removeAttribute('aria-required');
        for (const label of field.labels || []) {
            const marker = label.querySelector('[data-required-marker]');
            if (required && !marker && !label.textContent.includes('*')) {
                const star = document.createElement('span');
                star.className = 'required-marker'; star.dataset.requiredMarker = '';
                star.setAttribute('aria-hidden', 'true'); star.textContent = ' *'; label.append(star);
            } else if (!required) marker?.remove();
        }
    }
}

document.addEventListener('invalid', event => {
    const field = event.target;
    if (!field.matches(controls) || !field.form) return;
    event.preventDefault();
    fieldError(field, nativeMessage(field));
    queueMicrotask(() => focusInvalidField(field.form));
}, true);
for (const type of ['input', 'change']) document.addEventListener(type, event => {
    const field = event.target;
    if (!field.matches(controls) || !field.dataset.inlineErrorId) return;
    if (field.validity.valid) clearFieldError(field);
    else fieldError(field, nativeMessage(field));
});
document.addEventListener('reset', event => queueMicrotask(() => {
    event.target.querySelectorAll('[data-inline-error-id]').forEach(clearFieldError);
}));
markRequiredFields();
let scheduled = false;
new MutationObserver(records => {
    if (scheduled || !records.some(record => record.type === 'attributes' || record.target.matches?.('label') || [...record.addedNodes].some(node => node.nodeType === 1 && !node.matches('[data-required-marker], [data-inline-validation]') && (node.matches(controls) || node.querySelector(controls))))) return;
    scheduled = true;
    queueMicrotask(() => { scheduled = false; markRequiredFields(); });
}).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['required', 'disabled'] });
document.addEventListener('ea:workspace-rendered', markRequiredFields);
requestAnimationFrame(() => {
    for (const form of document.querySelectorAll('form:has([aria-invalid="true"])')) focusInvalidField(form);
});
