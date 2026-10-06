import { staffRequest } from './live-workspace';
import { createRefreshQueue } from './refresh-queue';

for (const panel of document.querySelectorAll('[data-rider-operations]')) {
    let openLate = [], ordersScroll = 0, detailSignature;
    let latest = JSON.parse(panel.querySelector('[data-zone-initial]').textContent), view = 'zone', selected, page = 1, generation = 0, controller;
    const list = panel.querySelector('[data-operations-list]'), detail = panel.querySelector('[data-operations-detail]');
    const search = panel.querySelector('[data-operations-search]'), filter = panel.querySelector('[data-operations-filter]'), sort = panel.querySelector('[data-operations-sort]');
    const money = cents => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(cents / 100);
    const node = (tag, value = '', className = '') => { const element = document.createElement(tag); element.textContent = value; element.className = className; return element; };
    const groups = () => (view === 'zone' ? latest.zones : latest.riders).filter(group => group.name.toLocaleLowerCase('it').includes(search.value.toLocaleLowerCase('it')) && (filter.value === 'all' || (filter.value === 'late' ? group.overdue > 0 : group.undelivered > 0))).sort((a, b) => sort.value === 'volume' ? b.total - a.total || a.name.localeCompare(b.name, 'it') : sort.value === 'late' ? b.overdue - a.overdue || a.name.localeCompare(b.name, 'it') : a.name.localeCompare(b.name, 'it'));
    function feedback(message = '') { const box = panel.querySelector('[data-zone-error]'); box.hidden = !message; box.textContent = message; }
    async function loadOrders(url) {
        controller?.abort(); controller = new AbortController(); const ticket = ++generation;
        const body = detail.querySelector('[data-explorer-orders]'); if (!body) return;
        body.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' } });
            if (!response.ok || response.redirected) throw new Error('Ordini non disponibili. Riprova.');
            const html = await response.text(); if (ticket !== generation) return;
            body.replaceChildren(...new DOMParser().parseFromString(html, 'text/html').body.childNodes);
            for (const row of body.querySelectorAll('[data-operation-order]')) if (openLate.includes(row.dataset.operationOrder)) row.querySelector('details')?.setAttribute('open', '');
            body.scrollTop = ordersScroll; feedback();
        } catch (error) { if (error.name !== 'AbortError') feedback(error.message); }
        finally { if (ticket === generation) body.removeAttribute('aria-busy'); }
    }
    function detailUrl(group) {
        const url = new URL(panel.dataset.detailUrl, location.origin);
        if (view === 'zone') url.searchParams.set('zone', group.key);
        else if (group.slug) url.searchParams.set('rider', group.slug);
        else url.searchParams.set('unassigned', '1');
        url.searchParams.set('page', page); return url;
    }
    function renderDetail(group) {
        const focused = document.activeElement;
        const focusHref = detail.contains(focused) ? focused.href : null;
        openLate = [...detail.querySelectorAll('[data-operation-order]:has(details[open])')].map(row => row.dataset.operationOrder);
        ordersScroll = detail.querySelector('[data-explorer-orders]')?.scrollTop || 0;
        detail.replaceChildren();
        if (!group) { detail.append(node('h2', 'Nessun risultato'), node('p', 'Modifica la ricerca o seleziona un’altra giornata.')); return; }
        const heading = node('header', '', 'explorer-detail-heading');
        const title = node('div'); title.append(node('span', view === 'zone' ? 'ZONA SELEZIONATA' : 'RIDER SELEZIONATO', 'eyebrow'), node('h2', group.name)); heading.append(title);
        if (group.url) { const link = node('a', 'Dettaglio giornata', 'btn btn-outline-secondary btn-sm'); link.href = group.url; heading.append(link); }
        detail.append(heading);
        const metrics = node('dl', '', 'explorer-detail-metrics');
        for (const [key, label] of [['total', 'Programmate'], ['delivered', 'Consegnate'], ['undelivered', 'Non consegnate'], ['overdue', 'In ritardo']]) { const item = node('div'); item.append(node('dt', label), node('dd', group[key])); metrics.append(item); }
        detail.append(metrics);
        const progress = node('progress'); progress.max = Math.max(1, group.total); progress.value = group.delivered; progress.setAttribute('aria-label', 'Spedizioni consegnate'); detail.append(progress);
        const assignments = node('div', '', 'explorer-assignments');
        assignments.append(node('span', view === 'zone' ? 'Rider assegnati' : 'Zone della giornata', 'data-label'));
        for (const item of view === 'zone' ? group.riders : group.zones) assignments.append(node('span', view === 'zone' ? item.name : `${item.name} · ${item.count}`, 'assignment-name'));
        if (view === 'zone' && !group.riders.length) assignments.append(node('span', 'Da assegnare'));
        detail.append(assignments, node('p', `Tariffe ${money(group.expected_cents)} · Incassato netto ${money(group.cash_cents)} · Quota EA ${money(group.retained_cents)}`, 'explorer-economics'), node('p', 'Incassi, rettifiche e storni degli ordini programmati: movimenti registrati fino a oggi. Il valore merce è escluso.', 'data-caption'));
        const body = node('div'); body.dataset.explorerOrders = ''; detail.append(body); loadOrders(detailUrl(group));
        if (focusHref) [...detail.querySelectorAll('a')].find(link => link.href === focusHref)?.focus({ preventScroll: true });
    }
    function render({ refreshDetail = false } = {}) {
        const visible = groups(), oldScroll = list.scrollTop, focusKey = list.contains(document.activeElement) ? document.activeElement.dataset.operationsKey : null;
        if (!visible.some(group => group.key === selected)) { selected = visible[0]?.key; page = 1; }
        list.replaceChildren(); list.setAttribute('aria-label', view === 'zone' ? 'Zone della giornata' : 'Rider della giornata');
        for (const group of visible) {
            const button = node('button', '', 'explorer-item'); button.type = 'button'; button.dataset.operationsKey = group.key; button.setAttribute('aria-pressed', String(group.key === selected));
            const heading = node('span', '', 'explorer-item-heading'); heading.append(node('strong', group.name), node('span', group.total, 'explorer-volume')); button.append(heading);
            const status = node('span', '', 'explorer-item-status'); status.append(node('span', `${group.delivered} consegnate`, 'operations-completed'), node('span', `${group.undelivered} non consegnate`)); if (group.overdue) status.append(node('span', `${group.overdue} in ritardo`, 'operations-overdue')); button.append(status); list.append(button);
        }
        list.scrollTop = oldScroll;
        if (focusKey) [...list.querySelectorAll('button')].find(button => button.dataset.operationsKey === focusKey)?.focus({ preventScroll: true });
        panel.querySelector('[data-operations-count]').textContent = `${visible.length} ${view === 'zone' ? 'zone' : 'Rider'} · ${latest.date}`;
        for (const [key, value] of Object.entries(latest.summary)) { const item = panel.querySelector(`[data-kpi="${key}"]`); if (item) item.textContent = value; }
        const history = panel.querySelector('[data-operations-history]');
        history.hidden = !latest.schedule_history.length;
        const historyItems = history.querySelector('[data-operations-history-items]'); historyItems.replaceChildren();
        for (const change of latest.schedule_history) {
            const item = node('p', '', 'small'), link = node('a', change.reference || 'Ordine');
            if (change.url) link.href = change.url;
            item.append(link, document.createTextNode(` · ${change.before.pickup_date} → ${change.after.pickup_date} · ${change.operator || 'Operatore'} · ${change.reason || ''} · ${new Date(change.at).toLocaleString('it-IT', { timeZone: 'Europe/Rome' })}`)); historyItems.append(item);
        }
        panel.querySelector('[data-zone-updated]').textContent = `Aggiornato ${new Date(latest.generated_at).toLocaleTimeString('it-IT')}`;
        const signature = `${view}:${selected}:${page}`;
        if (refreshDetail || signature !== detailSignature) { detailSignature = signature; renderDetail(visible.find(group => group.key === selected)); }
    }
    panel.addEventListener('click', event => {
        const perspective = event.target.closest('[data-operations-view]');
        if (perspective) { view = perspective.dataset.operationsView; selected = undefined; page = 1; for (const button of panel.querySelectorAll('[data-operations-view]')) button.setAttribute('aria-pressed', String(button === perspective)); render(); }
        const choice = event.target.closest('[data-operations-key]');
        if (choice) { selected = choice.dataset.operationsKey; page = 1; render(); }
        const pagination = event.target.closest('[data-explorer-orders] .workspace-pagination a');
        if (pagination) { event.preventDefault(); page = Number(new URL(pagination.href).searchParams.get('page') || 1); loadOrders(pagination.href); }
    });
    list.addEventListener('keydown', event => {
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        const buttons = [...list.querySelectorAll('button')], index = buttons.indexOf(document.activeElement);
        if (index < 0) return;
        event.preventDefault(); const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : Math.max(0, Math.min(buttons.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1))); buttons[next]?.focus();
    });
    search.addEventListener('input', render); filter.addEventListener('change', render); sort.addEventListener('change', render);
    const queue = createRefreshQueue(async () => { try { const result = await staffRequest(panel.dataset.feed); if (result.date !== latest.date) return; latest = result; render({ refreshDetail: true }); feedback(); } catch { feedback('Aggiornamento non disponibile. Sono mostrati gli ultimi dati ricevuti.'); } });
    const refresh = () => { if (!document.hidden) queue.refresh(); };
    window.addEventListener('ea:workspace-updated', refresh); window.addEventListener('ea:workspace-polled', refresh); window.addEventListener('pageshow', refresh);
    document.addEventListener('visibilitychange', refresh);
    const timer = setInterval(refresh, 30000);
    window.addEventListener('pagehide', () => { clearInterval(timer); queue.dispose(); controller?.abort(); }, { once: true });
    render();
}
