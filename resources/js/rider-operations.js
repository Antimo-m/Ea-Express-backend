import { staffRequest } from './live-workspace';
import { createRefreshQueue } from './refresh-queue';

for (const panel of document.querySelectorAll('[data-rider-operations]')) {
    let openLate = [], ordersScroll = 0, detailSignature;
    const params = new URL(location.href).searchParams;
    const periodForm = document.querySelector('[data-operations-period]');
    let latest = JSON.parse(panel.querySelector('[data-zone-initial]').textContent), view = params.get('view') || 'zone', selected = params.get('selected') || undefined, scope = params.get('scope') || 'programmed', page = Number(params.get('detail_page') || 1), generation = 0, controller;
    const list = panel.querySelector('[data-operations-list]'), detail = panel.querySelector('[data-operations-detail]');
    const search = panel.querySelector('[data-operations-search]'), filter = panel.querySelector('[data-operations-filter]'), sort = panel.querySelector('[data-operations-sort]');
    search.value = params.get('q') || ''; filter.value = params.get('filter') || 'all'; sort.value = params.get('sort') || 'name';
    function syncState() {
        const url = new URL(location.href);
        for (const [name, value] of Object.entries({view, q:search.value, filter:filter.value, sort:sort.value, selected:selected || '', scope, detail_page:page > 1 ? String(page) : ''})) {
            if (value) url.searchParams.set(name,value); else url.searchParams.delete(name);
            const input = periodForm?.elements.namedItem(name); if (input) input.value = value;
        }
        history.replaceState(null,'',url);
        for(const link of detail.querySelectorAll('a[href]')) { const target=new URL(link.href,location.origin); if(/^\/orders\/[0-9a-f-]{36}$/.test(target.pathname)) { target.searchParams.set('return_to',url.pathname+url.search); link.href=target; } }
        const zoneLink = detail.querySelector('[data-zone-orders]');
        if (zoneLink) { const target = new URL(zoneLink.href); for (const [name, value] of url.searchParams) target.searchParams.set(name, value); target.searchParams.set('scope', scope); zoneLink.href = target; }
        for (const button of panel.querySelectorAll('[data-operations-view]')) button.setAttribute('aria-pressed',String(button.dataset.operationsView === view));
    }
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
            for (const link of body.querySelectorAll('a[href]')) { const target=new URL(link.href,location.origin); if(/^\/orders\/[0-9a-f-]{36}$/.test(target.pathname)) { target.searchParams.set('return_to',location.pathname+location.search); link.href=target; } }
            for (const row of body.querySelectorAll('[data-operation-order]')) if (openLate.includes(row.dataset.operationOrder)) row.querySelector('details')?.setAttribute('open', '');
            body.scrollTop = ordersScroll; feedback();
        } catch (error) { if (error.name !== 'AbortError') feedback(error.message); }
        finally { if (ticket === generation) body.removeAttribute('aria-busy'); }
    }
    function detailUrl(group) {
        const url = new URL(panel.dataset.detailUrl, location.origin);
        for (const [name, value] of new URL(location.href).searchParams) if (!['page', 'zone', 'rider', 'unassigned'].includes(name)) url.searchParams.set(name, value);
        url.searchParams.set('scope', scope);
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
        if (group.url) { const link = node('a', 'Dettaglio giornata', 'btn btn-outline-secondary btn-sm'); const url = new URL(group.url,location.origin); for (const [name,value] of new URL(location.href).searchParams) if (name !== 'detail_page') url.searchParams.set(name,value); link.href = url; heading.append(link); }
        detail.append(heading);
        const metrics = node('dl', '', 'explorer-detail-metrics');
        for (const [key, label] of [['total', 'Programmate'], ['delivered', 'Consegnate'], ['undelivered', 'Non consegnate'], ['overdue', 'In ritardo']]) { const item = node('div'); item.append(node('dt', label), node('dd', group[key])); metrics.append(item); }
        detail.append(metrics);
        const scopes = node('nav','','operations-perspectives'); scopes.setAttribute('aria-label','Elenco spedizioni');
        for (const [value,label] of [['programmed','Programmate'],['late',`Ritardi e arretrati (${group.overdue})`]]) { const button = node('button',label); button.type='button'; button.dataset.operationsScope=value; button.setAttribute('aria-pressed',String(scope===value)); scopes.append(button); } detail.append(scopes);
        const progress = node('progress'); progress.max = Math.max(1, group.total); progress.value = group.delivered; progress.setAttribute('aria-label', 'Spedizioni consegnate'); detail.append(progress);
        const assignments = node('div', '', 'explorer-assignments');
        assignments.append(node('span', view === 'zone' ? 'Rider assegnati' : 'Zone della giornata', 'data-label'));
        for (const item of view === 'zone' ? group.riders : group.zones) assignments.append(node('span', view === 'zone' ? item.name : `${item.name} · ${item.count}`, 'assignment-name'));
        if (view === 'zone' && !group.riders.length) assignments.append(node('span', 'Da assegnare'));
        detail.append(assignments);
        controller?.abort(); generation++;
        if (view === 'zone') {
            const link = node('a', 'Vai agli ordini', 'btn btn-primary');
            const url = detailUrl(group); url.pathname = new URL(panel.dataset.zoneOrdersUrl, location.origin).pathname;
            url.searchParams.delete('page'); link.href = url; link.dataset.zoneOrders = ''; detail.append(link);
        } else {
            const body = node('div'); body.dataset.explorerOrders = ''; detail.append(body); loadOrders(detailUrl(group));
        }
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
        syncState();
        list.scrollTop = oldScroll;
        if (focusKey) [...list.querySelectorAll('button')].find(button => button.dataset.operationsKey === focusKey)?.focus({ preventScroll: true });
        panel.querySelector('[data-operations-count]').textContent = `${visible.length} ${view === 'zone' ? 'zone' : 'Rider'} · ${latest.date}${latest.to && latest.to !== latest.date ? ' – '+latest.to : ''}`;
        for (const [key, value] of Object.entries(latest.summary)) { const item = panel.querySelector(`[data-kpi="${key}"]`); if (item) item.textContent = value; }
        const missing = panel.querySelector('[data-deadline-warning]'); if(missing) { missing.hidden=!latest.summary.missing_deadlines; panel.querySelector('[data-missing-deadlines]').textContent=latest.summary.missing_deadlines || 0; }
        const warning = panel.querySelector('[data-history-warning]'); if (warning) warning.hidden = !latest.summary.history_unknown;
        const reference = panel.querySelector('[data-operations-reference]'); if(reference && latest.reference_at) reference.textContent = new Date(latest.reference_at).toLocaleString('it-IT',{timeZone:'Europe/Rome'});
        const history = panel.querySelector('[data-operations-history]');
        history.hidden = !latest.schedule_history.length;
        const historyItems = history.querySelector('[data-operations-history-items]'); historyItems.replaceChildren();
        for (const change of latest.schedule_history) {
            const item = node('p', '', 'small'), link = node('a', change.reference || 'Ordine');
            if (change.url) link.href = change.url;
            item.append(link, document.createTextNode(` · ${change.before.pickup_date} → ${change.after.pickup_date} · ${change.operator || 'Operatore'} · ${change.reason || ''} · ${new Date(change.at).toLocaleString('it-IT', { timeZone: 'Europe/Rome' })}`)); historyItems.append(item);
        }
        panel.querySelector('[data-zone-updated]').textContent = `Aggiornato ${new Date(latest.generated_at).toLocaleTimeString('it-IT')}`;
        const signature = `${view}:${selected}:${page}:${scope}:${filter.value}`;
        if (refreshDetail || signature !== detailSignature) { detailSignature = signature; renderDetail(visible.find(group => group.key === selected)); }
    }
    panel.addEventListener('click', event => {
        const scopeButton = event.target.closest('[data-operations-scope]'); if(scopeButton) { scope=scopeButton.dataset.operationsScope; page=1; render(); }
        const perspective = event.target.closest('[data-operations-view]');
        if (perspective) { view = perspective.dataset.operationsView; selected = undefined; page = 1; for (const button of panel.querySelectorAll('[data-operations-view]')) button.setAttribute('aria-pressed', String(button === perspective)); render(); }
        const choice = event.target.closest('[data-operations-key]');
        if (choice) { selected = choice.dataset.operationsKey; page = 1; render(); }
        const pagination = event.target.closest('[data-explorer-orders] .workspace-pagination a');
        if (pagination) { event.preventDefault(); page = Number(new URL(pagination.href).searchParams.get('page') || 1); syncState(); loadOrders(pagination.href); }
    });
    list.addEventListener('keydown', event => {
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        const buttons = [...list.querySelectorAll('button')], index = buttons.indexOf(document.activeElement);
        if (index < 0) return;
        event.preventDefault(); const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : Math.max(0, Math.min(buttons.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1))); buttons[next]?.focus();
    });
    search.addEventListener('input', () => render()); filter.addEventListener('change', () => { if(filter.value==='late')scope='late'; render(); }); sort.addEventListener('change', () => render());
    const queue = createRefreshQueue(async () => { try { const feed = panel.dataset.feed; const result = await staffRequest(feed); if (feed !== panel.dataset.feed) return; latest = result; render({ refreshDetail: true }); feedback(); } catch { feedback('Aggiornamento non disponibile. Sono mostrati gli ultimi dati ricevuti.'); } });
    async function changePeriod(url) {
        for (const name of ['view','q','filter','sort','selected','scope']) { const value=new URL(location.href).searchParams.get(name); if(value) url.searchParams.set(name,value); }
        url.searchParams.delete('page'); url.searchParams.delete('detail_page');
        history.replaceState(null,'',url); page=1; controller?.abort(); generation++;
        for (const key of ['feed','detailUrl']) { const target=new URL(panel.dataset[key],location.origin); target.search=''; for(const name of ['date','period']) if(url.searchParams.has(name)) target.searchParams.set(name,url.searchParams.get(name)); panel.dataset[key]=target.href; }
        const mode=url.searchParams.get('period') || (url.searchParams.has('date')?'custom':'today');
        const input=periodForm?.querySelector('[data-period-mode]'); if(input) input.value=mode; const range=periodForm?.querySelector('[data-filter-range]'); if(range && mode!=='custom')range.dataset.periodPreset=mode;
        for(const link of periodForm?.querySelectorAll('.period-options a') || []) { const active=new URL(link.href).searchParams.get('period')===mode; link.classList.toggle('active',active); if(active)link.setAttribute('aria-current','page'); else link.removeAttribute('aria-current'); }
        const custom=periodForm?.querySelector('[data-custom-period]'); if(custom) { custom.classList.toggle('active',mode==='custom'); custom.setAttribute('aria-expanded',String(mode==='custom')); }
        const dates=periodForm?.querySelector('[data-custom-dates]'); if(dates) dates.hidden=mode!=='custom';
        const date=periodForm?.elements.namedItem('date'); if(date && url.searchParams.has('date')) date.value=url.searchParams.get('date');
        await queue.refresh();
        if(date && mode!=='custom')date.value=latest.date;
        const caption=periodForm?.querySelector('.filter-period-caption'); if(caption) caption.textContent=`${latest.date} – ${latest.to || latest.date}`;
    }
    periodForm?.addEventListener('click',event=> { const link=event.target.closest('.period-options a,.filter-reset'); if(!link) return; event.preventDefault(); changePeriod(new URL(link.href)); });
    periodForm?.addEventListener('submit',event=> { event.preventDefault(); const url=new URL(location.href); url.search=''; for(const [name,value] of new FormData(periodForm)) if(value)url.searchParams.set(name,value); if(url.searchParams.get('period')!=='custom')url.searchParams.delete('date'); changePeriod(url); });
    window.addEventListener('popstate',()=>location.reload());
    const refresh = () => { if (!document.hidden) queue.refresh(); };
    window.addEventListener('ea:workspace-updated', refresh); window.addEventListener('ea:workspace-polled', refresh); window.addEventListener('pageshow', refresh);
    document.addEventListener('visibilitychange', refresh);
    const timer = setInterval(refresh, 30000);
    window.addEventListener('pagehide', event => { if(event.persisted)return; clearInterval(timer); queue.dispose(); controller?.abort(); }, { once: true });
    render();
}
