export function createConfirmButton(label, onConfirm) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'icon-button action-confirm';
    button.title = label;
    button.dataset.tooltip = label;
    button.setAttribute('aria-label', label);
    const icon = document.createElement('i');
    icon.className = 'bi bi-check-lg';
    icon.setAttribute('aria-hidden', 'true');
    button.append(icon);
    button.addEventListener('click', onConfirm);
    return button;
}
