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
    if (event.target.closest('.history-print-choice')) { event.stopPropagation(); return; }
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


/**
 * Selezione delle etichette nello storico: caselle indipendenti, un secondo clic toglie la spunta.
 * - una giornata spuntata include tutte le sue etichette, con eventuali esclusioni di singoli ordini;
 * - gli ordini spuntati fuori da una giornata selezionata si aggiungono alla selezione;
 * lo stato resta valido aprendo altre giornate o pagine, senza duplicati e senza ricaricare la pagina.
 */
const labels = { days: new Map(), orders: new Map() };
const dayOf = control => control.closest('[data-order-history-day]')?.dataset.orderHistoryDay || control.closest('[data-history-date]')?.dataset.historyDate || '';
function selectedLabels() {
    let count = labels.orders.size;
    for (const day of labels.days.values()) count += Math.max(0, day.count - day.excluded.size);
    return count;
}
function renderLabelSelection() {
    for (const control of document.querySelectorAll('[data-history-label-day]')) control.checked = labels.days.has(control.dataset.historyLabelDay);
    for (const control of document.querySelectorAll('[data-history-label-single]')) {
        const slug = control.dataset.historyLabelSingle, day = labels.days.get(dayOf(control));
        control.checked = !control.disabled && (day ? !day.excluded.has(slug) : labels.orders.has(slug));
        control.closest('.history-order')?.classList.toggle('is-label-selected', control.checked);
    }
    const toolbar = document.querySelector('[data-history-print-toolbar]');
    if (!toolbar) return;
    const count = selectedLabels();
    const days = labels.days.size;
    toolbar.querySelector('[data-history-print-status]').textContent = count
        ? `${count} ${count === 1 ? 'etichetta selezionata' : 'etichette selezionate'}${days ? ` · ${days} ${days === 1 ? 'giornata' : 'giornate'} intere` : ''}`
        : 'Nessuna etichetta selezionata';
    toolbar.querySelector('[data-history-print-confirm]').setAttribute('aria-disabled', String(count === 0));
    toolbar.querySelector('[data-history-print-clear]').disabled = count === 0 && days === 0;
}
function resetLabelSelection() {
    labels.days.clear(); labels.orders.clear();
    renderLabelSelection();
}
document.addEventListener('ea:controls-updated', renderLabelSelection);
document.addEventListener('click', event => {
    const archive = event.target.closest('.order-archive');
    if (!archive) return;
    const toolbar = archive.querySelector('[data-history-print-toolbar]');
    if (!toolbar) return;
    const start = event.target.closest('[data-history-print-start]');
    if (start) {
        event.preventDefault();
        if (start.getAttribute('aria-disabled') === 'true') {
            archive.querySelector('[data-label-status]').textContent = 'Al momento non ci sono ordini da stampare';
            return;
        }
        resetLabelSelection(); archive.dataset.historyPrinting = 'true';
        start.setAttribute('aria-expanded', 'true');
        toolbar.hidden = false;
        toolbar.querySelector('[data-history-print-cancel]').focus();
    }
    if (event.target.closest('[data-history-print-clear]')) {
        resetLabelSelection();
    }
    if (event.target.closest('[data-history-print-cancel]')) {
        resetLabelSelection(); delete archive.dataset.historyPrinting;
        toolbar.hidden = true;
        const button = archive.querySelector('[data-history-print-start]');
        button?.setAttribute('aria-expanded', 'false'); button?.focus();
    }
    if (event.target.closest('[data-history-print-confirm]')) {
        const count = selectedLabels();
        if (!count) {
            toolbar.querySelector('[data-history-print-status]').textContent = 'Seleziona almeno un’etichetta da stampare.';
            return;
        }
        const url = new URL(toolbar.dataset.labelUrl, location.origin);
        if (labels.days.size) {
            const current = new URL(location.href);
            for (const key of ['q','zone','status','customer_id','rider_id','shipping_type','urgency','sender_type','from','to']) if (current.searchParams.has(key)) url.searchParams.set(key, current.searchParams.get(key));
            url.searchParams.set('scope', 'history-selection');
            for (const [day, selection] of labels.days) { url.searchParams.append('days[]', day); selection.excluded.forEach(slug => url.searchParams.append('exclude[]', slug)); }
        } else if (labels.orders.size > 100) {
            toolbar.querySelector('[data-history-print-status]').textContent = 'Puoi stampare fino a 100 etichette scelte una per una: per quantità maggiori seleziona le giornate intere.';
            return;
        }
        labels.orders.forEach((day, slug) => url.searchParams.append('ids[]', slug));
        window.open(url, '_blank', 'noopener');
    }
});
document.addEventListener('change', event => {
    const control = event.target.closest('[data-history-label-day], [data-history-label-single]');
    if (!control || !document.querySelector('[data-history-print-toolbar]')) return;
    if (control.dataset.historyLabelDay) {
        const day = control.dataset.historyLabelDay;
        if (control.checked) {
            labels.days.set(day, { count: Number(control.dataset.labelCount || 0), excluded: new Set() });
            for (const [slug, orderDay] of labels.orders) if (orderDay === day) labels.orders.delete(slug);
        } else labels.days.delete(day);
    } else {
        const slug = control.dataset.historyLabelSingle, day = dayOf(control), selectedDay = labels.days.get(day);
        if (selectedDay) {
            if (control.checked) selectedDay.excluded.delete(slug); else selectedDay.excluded.add(slug);
        } else if (control.checked) labels.orders.set(slug, day);
        else labels.orders.delete(slug);
    }
    renderLabelSelection();
});
