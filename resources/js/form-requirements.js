function updateRequirements(form) {
    for (const field of form.querySelectorAll('[data-required-when]')) {
        const source = form.elements.namedItem(field.dataset.requiredWhen);
        if (!source) continue;
        const value = source.value || '';
        const required = field.hasAttribute('data-required-unless-value')
            ? value !== field.dataset.requiredUnlessValue
            : field.hasAttribute('data-required-value') ? value === field.dataset.requiredValue : value !== '';
        if (field.required !== required) field.required = required;
    }
}

const refresh = () => document.querySelectorAll('form:has([data-required-when])').forEach(updateRequirements);
for (const type of ['input', 'change']) document.addEventListener(type, event => {
    if (event.target.form) updateRequirements(event.target.form);
});
document.addEventListener('reset', event => queueMicrotask(() => updateRequirements(event.target)));
document.addEventListener('ea:workspace-rendered', refresh);
window.addEventListener('pageshow', refresh);
refresh();
