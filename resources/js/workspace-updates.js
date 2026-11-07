import { cancelToast, expireToast } from './toast-feedback';

let refresh = Promise.resolve();

export function workspaceFeedback(message, { error = false, retry } = {}) {
    let notice = document.querySelector('[data-workspace-feedback]');
    if (!notice) {
        notice = document.createElement('div');
        notice.dataset.workspaceFeedback = '';
        (document.querySelector('main') || document.body).prepend(notice);
    }
    notice.className = `workspace-feedback alert ${error ? 'alert-warning' : 'alert-success'}`;
    cancelToast(notice);
    notice.hidden = false;
    notice.setAttribute('role', error ? 'alert' : 'status');
    notice.replaceChildren(document.createTextNode(message));
    if (retry) {
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'btn btn-outline-secondary btn-sm';
        button.textContent = 'Aggiorna i dati';
        button.addEventListener('click', async () => {
            button.disabled = true;
            try { await retry(); notice.remove(); }
            catch { button.disabled = false; }
        });
        notice.append(button);
    } else {
        const close = document.createElement('button');
        close.type = 'button'; close.className = 'btn-close';
        close.setAttribute('aria-label', 'Chiudi messaggio');
        close.addEventListener('click', () => { cancelToast(notice); notice.remove(); });
        notice.append(close);
        expireToast(notice);
    }
}

const rowSelector = '[data-receipt],[data-reversal],[data-operational-order],[data-directory-select],[data-directory-panel],[data-workspace-row]';
function identity(node) {
    return node.id ? `id:${node.id}` : [...node.attributes].filter(attribute => attribute.name.startsWith('data-') && attribute.value).map(attribute => `${attribute.name}:${attribute.value}`).join('|');
}

function context() {
    return {
        x: window.scrollX, y: window.scrollY,
        anchors: [...document.querySelectorAll(rowSelector)].map(node => ({ key: identity(node), top: node.getBoundingClientRect().top, bottom: node.getBoundingClientRect().bottom })).filter(row => row.bottom > 0 && row.top < innerHeight),
        checks: [...document.querySelectorAll('main input[type="checkbox"]')].map(input => ({ id: input.id, name: input.name, value: input.value, checked: input.checked })),
        details: [...document.querySelectorAll('main details[open]')].map(node => ({ id: node.id, historyUrl: node.dataset.historyUrl })).filter(node => node.id || node.historyUrl),
        scrolls: [...document.querySelectorAll('[data-scroll-key]')].map(node => ({ key: node.dataset.scrollKey, top: node.scrollTop })),
        searches: [...document.querySelectorAll('[data-directory-search]')].map(node => ({ value: node.value })),
        directory: document.querySelector('[data-directory-select][aria-current]')?.dataset.directorySelect,
    };
}

function restore(saved) {
    for (const check of saved.checks) {
        const input = [...document.querySelectorAll('main input[type="checkbox"]')].find(node => check.id ? node.id === check.id : node.name === check.name && node.value === check.value);
        if (input) input.checked = check.checked;
    }
    for (const open of saved.details) {
        const details = open.id ? document.getElementById(open.id) : [...document.querySelectorAll('details[data-history-url]')].find(node => node.dataset.historyUrl === open.historyUrl);
        if (details?.tagName === 'DETAILS') details.open = true;
    }
    for (const scroll of saved.scrolls) { const node = [...document.querySelectorAll('[data-scroll-key]')].find(item => item.dataset.scrollKey === scroll.key); if (node) node.scrollTop = scroll.top; }
    document.querySelectorAll('[data-directory-search]').forEach((input, index) => {
        if (saved.searches[index]) { input.value = saved.searches[index].value; input.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    const selected = [...document.querySelectorAll('[data-directory-select]')].find(link => link.dataset.directorySelect === saved.directory);
    if (selected && !selected.hidden && !selected.hasAttribute('aria-current')) selected.click();
    const rows = new Map([...document.querySelectorAll(rowSelector)].map(node => [identity(node), node]));
    const anchor = saved.anchors.find(row => rows.has(row.key));
    const top = anchor ? saved.y + rows.get(anchor.key).getBoundingClientRect().top - anchor.top : saved.y;
    window.scrollTo({ left: saved.x, top: Math.max(0, top), behavior: 'instant' });
}

export function applyWorkspaceSnapshot(page, { form } = {}) {
    const saved = context();
    const token = page.querySelector('meta[name="csrf-token"]')?.content;
    if (token) document.querySelector('meta[name="csrf-token"]').content = token;
    for (const selector of ['.app-header .account-name', '.app-header .account-button .avatar']) {
        const current = document.querySelector(selector), next = page.querySelector(selector);
        if (current && next) current.replaceChildren(...next.childNodes);
    }
    const order = document.querySelector('[data-live-order]');
    const nextOrder = page.querySelector('[data-live-order]');
    if (order && nextOrder?.dataset.liveOrder === order.dataset.liveOrder) {
        if (Number(nextOrder.dataset.orderVersion) < Number(order.dataset.orderVersion)) return;
        order.dataset.orderVersion = nextOrder.dataset.orderVersion;
        for (const [index, selector] of ['.order-operational-summary', '.order-overview', '.order-information', '.order-focus-summary', '.shipment-progress', '.order-event-history', '.rider-assignment-card', '.order-workflow'].entries()) {
            order.querySelector(selector)?.setAttribute('data-workspace-region', `order-${index}`);
            nextOrder.querySelector(selector)?.setAttribute('data-workspace-region', `order-${index}`);
        }
    }
    let updated = 0;
    const regions = [...document.querySelectorAll('main [data-workspace-region]')].filter(region => !region.parentElement.closest('[data-workspace-region]'));
    for (const region of regions) {
        const next = [...page.querySelectorAll('[data-workspace-region]')].find(node => node.dataset.workspaceRegion === region.dataset.workspaceRegion);
        if (!next) continue;
        const editing = region.querySelector('dialog[open], form[aria-busy="true"]');
        if (editing && !region.contains(form)) continue;
        if (region.contains(document.activeElement) && document.activeElement.matches('input,textarea,select,.ea-picker-trigger') && !region.contains(form)) continue;
        region.replaceWith(next);
        updated++;
    }
    const toast = page.querySelector('[data-toast]');
    document.dispatchEvent(new CustomEvent('ea:workspace-rendered', { detail: { updated } }));
    document.dispatchEvent(new Event('ea:controls-updated'));
    restore(saved);
    requestAnimationFrame(() => restore(saved));
    if (toast) workspaceFeedback(toast.textContent.trim());
    return updated;
}

export function refreshWorkspace(options = {}) {
    const load = async () => {
        const url = new URL(location.href);
        let page;
        for (let attempt = 0; attempt < 2; attempt++) {
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok || response.redirected) throw new Error('Dati aggiornati non disponibili.');
            page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const overflows = [...page.querySelectorAll('[data-pagination-page]')].filter(node => Number(node.dataset.paginationPage) > Number(node.dataset.paginationLast));
            if (!overflows.length || attempt) break;
            for (const pager of overflows) url.searchParams.set(pager.dataset.paginationName, pager.dataset.paginationLast);
        }
        if (!page.querySelector('main')) throw new Error('Dati aggiornati non disponibili.');
        applyWorkspaceSnapshot(page, options);
        if (url.href !== location.href) history.replaceState(history.state, '', url);
    };
    refresh = refresh.catch(() => {}).then(load);
    return refresh;
}
