const timers = new WeakMap();

function dismissAfterDelay(toast) {
    clearTimeout(timers.get(toast));
    toast.hidden = false;
    timers.set(toast, setTimeout(() => {
        toast.remove();
        timers.delete(toast);
    }, 5000));
}

const selector = '[data-toast], .toast';
document.querySelectorAll(selector).forEach(dismissAfterDelay);
new MutationObserver(records => {
    for (const record of records) {
        for (const node of record.addedNodes) {
            if (node.nodeType !== Node.ELEMENT_NODE) continue;
            if (node.matches(selector)) dismissAfterDelay(node);
            node.querySelectorAll(selector).forEach(dismissAfterDelay);
        }
    }
}).observe(document.body, { childList: true, subtree: true });
