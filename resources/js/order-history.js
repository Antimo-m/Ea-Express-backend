let pending;

function syncSelectedDate(date) {
    const field = document.querySelector('.order-archive input[name="day"]');
    if (!field || field.value === date) return;
    field.value = date || '';
    field.dispatchEvent(new Event('change', { bubbles: true }));
}

function selectDay(day, url, push = true) {
    for (const other of document.querySelectorAll('[data-order-history-day]')) {
        if (other !== day) other.open = false;
    }
    syncSelectedDate(day.dataset.orderHistoryDay);
    if (push && url.href !== location.href) history.pushState({}, '', url);
}

async function loadHistory(url, day = null, { push = true } = {}) {
    pending?.abort();
    const controller = new AbortController();
    pending = controller;
    const current = day?.querySelector('[data-history-day-content]') || document.querySelector('[data-live-region="orders"]');
    if (!current) return;
    current.setAttribute('aria-busy', 'true');
    current.querySelector('[data-history-error]')?.remove();
    try {
        const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest', ...(day ? { 'X-Order-History': 'day' } : {}) } });
        if (!response.ok || response.redirected) throw new Error('Non è stato possibile caricare la giornata. Riprova.');
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const replacement = page.querySelector(day ? '[data-history-day-content]' : '[data-live-region="orders"]');
        if (!replacement || controller.signal.aborted || !current.isConnected) return;
        if (day && replacement.dataset.historyDate !== day.dataset.orderHistoryDay) throw new Error('La giornata non è più disponibile con questi filtri.');
        current.replaceWith(replacement);
        if (day) {
            day.dataset.historyUrl = url.href;
            selectDay(day, url, push);
        }
        else {
            syncSelectedDate(replacement.querySelector('[data-order-history-day][open]')?.dataset.orderHistoryDay);
            if (push && url.href !== location.href) history.pushState({}, '', url);
        }
        document.dispatchEvent(new Event('ea:controls-updated'));
    } catch (error) {
        if (error.name === 'AbortError') return;
        const feedback = document.createElement('p'); feedback.dataset.historyError = ''; feedback.className = 'alert alert-warning'; feedback.setAttribute('role', 'alert');
        feedback.append(document.createTextNode(error.message + ' '));
        const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'btn btn-outline-secondary'; retry.textContent = 'Riprova';
        retry.addEventListener('click', () => loadHistory(url, day, { push })); feedback.append(retry); current.prepend(feedback);
    } finally {
        current.removeAttribute('aria-busy');
        if (pending === controller) pending = null;
    }
}

document.addEventListener('toggle', event => {
    const day = event.target;
    if (!day.matches?.('[data-order-history-day]') || !day.open) return;
    const url = new URL(day.dataset.historyUrl, location.href);
    const userToggle = day.dataset.historyUserToggle === 'true';
    delete day.dataset.historyUserToggle;
    const content = day.querySelector('[data-history-day-content]');
    if (content?.dataset.historyLoaded === 'true') {
        selectDay(day, url, userToggle);
        return;
    }
    loadHistory(url, day);
}, true);

document.addEventListener('click', event => {
    const summary = event.target.closest('.order-archive .archive-day-heading');
    if (summary) {
        summary.closest('[data-order-history-day]').dataset.historyUserToggle = 'true';
        return;
    }
    const link = event.target.closest('.order-archive [data-history-load], .order-archive .workspace-pagination a');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    loadHistory(new URL(link.href), link.closest('[data-order-history-day]'));
});

window.addEventListener('popstate', () => {
    if (document.querySelector('.order-archive')) loadHistory(new URL(location.href), null, { push: false });
});
