const timers = new WeakMap();

export function cancelToast(toast) {
    clearTimeout(timers.get(toast));
    timers.delete(toast);
}

export function expireToast(toast, { remove = true } = {}) {
    cancelToast(toast);
    toast.hidden = false;
    timers.set(toast, setTimeout(() => {
        if (remove) toast.remove();
        else { toast.textContent = ''; toast.hidden = true; }
        timers.delete(toast);
    }, 5000));
}

export function transientFeedback(node, message) {
    if (!node) return;
    node.textContent = message;
    node.setAttribute('role', 'status');
    expireToast(node, { remove: false });
}

const selector = '[data-toast], .toast';
document.querySelectorAll(selector).forEach(toast => expireToast(toast));
document.addEventListener('click', event => {
    const toast = event.target.closest('[data-toast-dismiss]')?.closest(selector);
    if (!toast) return;
    cancelToast(toast);
    toast.remove();
});
new MutationObserver(records => {
    for (const record of records) {
        for (const node of record.addedNodes) {
            if (node.nodeType !== Node.ELEMENT_NODE) continue;
            if (node.matches(selector)) expireToast(node);
            node.querySelectorAll(selector).forEach(toast => expireToast(toast));
        }
        for (const node of record.removedNodes) {
            if (node.nodeType !== Node.ELEMENT_NODE || node.isConnected) continue;
            cancelToast(node);
            node.querySelectorAll(selector).forEach(cancelToast);
        }
    }
}).observe(document.body, { childList: true, subtree: true });
