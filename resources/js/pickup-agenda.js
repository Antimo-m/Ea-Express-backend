import { createRefreshQueue } from './refresh-queue';

const cache = new Map();
const days = new Map();
const accountRequests = new Map();
const restoredToggles = new WeakSet();
let navigation;
let generation = 0;

function root() { return document.querySelector('[data-pickup-agenda]'); }
function dateOf(group) { return group.closest('[data-pickup-accounts]')?.dataset.pickupDate; }
function identity(group) { return `${group.dataset.pickupKind}:${group.dataset.pickupAccount}`; }
function states(date) {
    if (!days.has(date)) days.set(date, new Map());
    if (days.size > 7) days.delete(days.keys().next().value);
    return days.get(date);
}
function keyOf(url) {
    const value = new URL(url, location.href);
    value.hash = ''; value.searchParams.sort();
    return value.href;
}
function remember(group) {
    const date = dateOf(group);
    if (!date) return;
    const previous = states(date).get(identity(group)) || {};
    const content = group.querySelector('[data-pickup-orders]');
    const checks = { ...(previous.checks || {}) };
    if (content?.dataset.pickupOrdersPage) {
        checks[keyOf(group.dataset.pickupCurrentUrl || group.dataset.pickupOrdersUrl)] = Object.fromEntries([...content.querySelectorAll('input[name="ids[]"]')].map(input => [input.value, input.checked]));
    }
    states(date).set(identity(group), { ...previous, open: group.open, url: group.dataset.pickupCurrentUrl || previous.url || group.dataset.pickupOrdersUrl, checks });
}
function rememberAll() { root()?.querySelectorAll('[data-pickup-account]').forEach(remember); }
function abortAccounts() { for (const controller of accountRequests.values()) controller.abort(); accountRequests.clear(); }
function errorNotice(container, message, retry) {
    container.querySelector('[data-pickup-error]')?.remove();
    const notice = document.createElement('div'); notice.dataset.pickupError = ''; notice.className = 'alert alert-warning'; notice.setAttribute('role', 'alert'); notice.append(document.createTextNode(`${message} `));
    const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline-secondary'; button.textContent = 'Riprova'; button.addEventListener('click', retry); notice.append(button); container.prepend(notice);
}
async function fetchPage(url, { signal, accounts = false } = {}) {
    const response = await fetch(url, { signal, cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest', ...(accounts ? { 'X-Pickup-Agenda': 'accounts' } : {}) } });
    if (!response.ok || response.redirected) {
        const error = new Error([401, 419].includes(response.status) ? 'Sessione scaduta. Accedi nuovamente.' : 'Dati dell’agenda non disponibili.'); error.status = response.status; throw error;
    }
    return new DOMParser().parseFromString(await response.text(), 'text/html');
}
function saveCache(key, content) {
    cache.delete(key); cache.set(key, content.outerHTML);
    if (cache.size > 20) cache.delete(cache.keys().next().value);
}
function applyOrders(group, content, url) {
    const date = dateOf(group);
    if (content.dataset.pickupAccountSlug !== group.dataset.pickupAccount || content.dataset.pickupDate !== date || content.dataset.pickupKind !== group.dataset.pickupKind) throw new Error('I ritiri ricevuti non corrispondono all’account selezionato.');
    const selections = states(date).get(identity(group))?.checks?.[keyOf(url)] || {};
    for (const input of content.querySelectorAll('input[name="ids[]"]')) if (Object.hasOwn(selections, input.value)) input.checked = selections[input.value];
    group.querySelector('[data-pickup-orders]').replaceWith(content);
    group.dataset.pickupCurrentUrl = url.href;
    group.querySelector('[data-pickup-account-orders-count]').textContent = content.dataset.pickupOrdersTotal;
    group.querySelector('[data-pickup-account-parcel-count]').textContent = content.dataset.pickupOrdersParcels;
    remember(group);
}
async function loadOrders(group, target, { force = false, normalized = false } = {}) {
    if (!group.isConnected || !group.open) return;
    remember(group);
    const url = new URL(target, location.href);
    if (!url.searchParams.has('page')) url.searchParams.set('page', '1');
    if (!url.searchParams.has('per_page')) url.searchParams.set('per_page', '20');
    const key = keyOf(url), epoch = generation;
    const controller = new AbortController();
    const requestKey = `${dateOf(group)}:${identity(group)}`;
    accountRequests.get(requestKey)?.abort(); accountRequests.set(requestKey, controller);
    const current = group.querySelector('[data-pickup-orders]');
    current.setAttribute('aria-busy', 'true'); current.querySelector('[data-pickup-error]')?.remove();
    try {
        let content;
        if (!force && cache.has(key)) content = new DOMParser().parseFromString(cache.get(key), 'text/html').querySelector('[data-pickup-orders]');
        else content = (await fetchPage(url, { signal: controller.signal })).querySelector('[data-pickup-orders]');
        if (!content) throw new Error('Non è stato possibile leggere i ritiri dell’account.');
        if (controller.signal.aborted || epoch !== generation || !group.isConnected) return;
        const page = Number(content.dataset.pickupOrdersPage), last = Math.max(1, Number(content.dataset.pickupOrdersLastPage));
        if (page > last && !normalized) { url.searchParams.set('page', last); return loadOrders(group, url, { force: true, normalized: true }); }
        saveCache(key, content); applyOrders(group, content, url);
    } catch (error) {
        if (error.name === 'AbortError' || !group.isConnected) return;
        if (error.status === 404) { states(dateOf(group)).delete(identity(group)); group.remove(); refresh.refresh(); }
        else errorNotice(current, error.message, () => loadOrders(group, url, { force: true }));
    } finally {
        if (accountRequests.get(requestKey) === controller) {
            current.removeAttribute('aria-busy');
            accountRequests.delete(requestKey);
        }
    }
}
function anchor() {
    const node = [...(root()?.querySelectorAll('[data-pickup-account]') || [])].find(node => { const rect = node.getBoundingClientRect(); return rect.bottom > 0 && rect.top < innerHeight; });
    return { x: scrollX, y: scrollY, key: node ? identity(node) : null, top: node?.getBoundingClientRect().top };
}
function focusedContext() {
    const focused = document.activeElement;
    const group = focused?.closest('[data-pickup-account]');
    return { date: focused?.closest('[data-pickup-date]')?.dataset.pickupDate, account: group ? identity(group) : null, summary: focused?.matches('.pickup-account-heading'), checkbox: focused?.matches('input[name="ids[]"]') ? focused.value : null };
}
function restoreFocus(saved) {
    if (saved.date) root()?.querySelector(`[data-pickup-date="${saved.date}"]`)?.focus({ preventScroll: true });
    const group = [...(root()?.querySelectorAll('[data-pickup-account]') || [])].find(group => identity(group) === saved.account);
    if (saved.summary) group?.querySelector('summary')?.focus({ preventScroll: true });
    if (saved.checkbox) group?.querySelector(`input[name="ids[]"][value="${saved.checkbox}"]`)?.focus({ preventScroll: true });
}
function restoreScroll(saved) {
    const node = [...(root()?.querySelectorAll('[data-pickup-account]') || [])].find(node => identity(node) === saved.key);
    const y = node ? scrollY + node.getBoundingClientRect().top - saved.top : saved.y;
    window.scrollTo({ left: saved.x, top: Math.max(0, y), behavior: 'instant' });
}
async function restoreGroups(previous = new Map()) {
    const calls = [];
    for (const group of root()?.querySelectorAll('[data-pickup-account]') || []) {
        const saved = states(dateOf(group)).get(identity(group));
        if (!saved?.open) continue;
        const old = previous.get(identity(group));
        if (old) group.querySelector('[data-pickup-orders]').replaceWith(old.cloneNode(true));
        restoredToggles.add(group); group.open = true;
        calls.push(loadOrders(group, saved.url));
    }
    await Promise.allSettled(calls);
}
async function navigateAgenda(target, { push = true, preserve = false } = {}) {
    rememberAll(); navigation?.abort(); abortAccounts(); generation++;
    const controller = new AbortController(); navigation = controller;
    const url = new URL(target, location.href), current = root();
    if (!current) return;
    current.setAttribute('aria-busy', 'true');
    try {
        const page = await fetchPage(url, { signal: controller.signal });
        const next = page.querySelector('[data-pickup-agenda]');
        if (!next || controller.signal.aborted || !current.isConnected) return;
        const savedScroll = anchor(), savedFocus = focusedContext(); current.replaceWith(next); document.title = page.title || document.title;
        if (push && url.href !== location.href) history.pushState({}, '', url);
        await restoreGroups();
        if (controller.signal.aborted || !next.isConnected) return;
        if (preserve) { restoreFocus(savedFocus); restoreScroll(savedScroll); }
        else if (url.hash) document.getElementById(url.hash.slice(1))?.scrollIntoView({ block: 'center', behavior: 'instant' });
        else next.scrollIntoView({ block: 'start', behavior: 'instant' });
        document.dispatchEvent(new Event('ea:controls-updated'));
    } catch (error) {
        if (error.name !== 'AbortError' && current.isConnected) errorNotice(current, error.message, () => navigateAgenda(url, { push, preserve }));
    } finally { current.removeAttribute('aria-busy'); if (navigation === controller) navigation = null; }
}
const refresh = createRefreshQueue(async () => {
    const current = root(); if (!current) return;
    cache.clear(); generation++; abortAccounts();
    if (!current.querySelector('[data-pickup-accounts]')) return navigateAgenda(new URL(location.href), { push: false, preserve: true });
    const date = current.querySelector('[data-pickup-accounts]').dataset.pickupDate, epoch = generation;
    try {
        const url = new URL(location.href);
        let page = await fetchPage(url, { accounts: true });
        let next = page.querySelector('[data-pickup-accounts]');
        if (!next || epoch !== generation || date !== root()?.querySelector('[data-pickup-accounts]')?.dataset.pickupDate) return;
        if (Number(next.dataset.pickupPage) > Number(next.dataset.pickupLastPage)) {
            url.searchParams.set('page', Math.max(1, Number(next.dataset.pickupLastPage)));
            page = await fetchPage(url, { accounts: true }); next = page.querySelector('[data-pickup-accounts]');
        }
        if (!next || epoch !== generation) return;
        rememberAll(); const savedScroll = anchor(), savedFocus = focusedContext();
        const previous = new Map([...current.querySelectorAll('[data-pickup-account]')].map(group => [identity(group), group.querySelector('[data-pickup-orders]')]));
        current.querySelector('[data-pickup-accounts]').replaceWith(next);
        const available = new Set([...next.querySelectorAll('[data-pickup-account]')].map(identity));
        for (const key of previous.keys()) if (!available.has(key)) states(date).delete(key);
        if (url.href !== location.href) history.replaceState(history.state, '', url);
        await restoreGroups(previous);
        if (epoch !== generation || !current.isConnected) return;
        restoreFocus(savedFocus); restoreScroll(savedScroll); requestAnimationFrame(() => { if (epoch === generation) restoreScroll(savedScroll); });
    } catch (error) { if (current.isConnected) errorNotice(current, error.message, () => refresh.refresh()); }
});

document.addEventListener('toggle', event => {
    const group = event.target;
    if (!group.matches?.('[data-pickup-account]') || !group.isConnected) return;
    if (restoredToggles.has(group)) { restoredToggles.delete(group); return; }
    const saved = states(dateOf(group)).get(identity(group));
    remember(group);
    if (group.open) loadOrders(group, saved?.url || group.dataset.pickupOrdersUrl);
}, true);
document.addEventListener('click', event => {
    const refreshButton = event.target.closest('[data-pickup-agenda] [data-pickup-refresh]');
    if (refreshButton) { event.preventDefault(); refresh.refresh(); return; }
    const link = event.target.closest('[data-pickup-agenda] a');
    if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const group = link.closest('[data-pickup-account]');
    if (group && (link.hasAttribute('data-pickup-load') || link.closest('.pickup-orders-pagination'))) {
        event.preventDefault(); group.open = true; loadOrders(group, new URL(link.href)); return;
    }
    if (link.matches('[data-pickup-date], [data-pickup-groups-first]') || link.closest('.pickup-calendar-navigation, .pickup-accounts-pagination, .page-back')) { event.preventDefault(); navigateAgenda(new URL(link.href)); }
});
document.addEventListener('keydown', event => {
    const day = event.target.closest('[data-pickup-agenda] [data-pickup-date]');
    const delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[event.key];
    if (!day || !delta) return;
    event.preventDefault(); const date = new Date(`${day.dataset.pickupDate}T12:00:00Z`); date.setUTCDate(date.getUTCDate() + delta);
    const nextDate = date.toISOString().slice(0, 10), target = document.querySelector(`[data-pickup-date="${nextDate}"]`);
    if (target) target.focus();
});
window.addEventListener('ea:workspace-updated', event => { if (root() && ['order', 'orders', 'order-action', 'workspace-mutation', 'sync'].includes(event.detail?.kind)) refresh.refresh(); });
window.addEventListener('pageshow', event => { if (event.persisted && root()) refresh.refresh(); });
window.addEventListener('popstate', () => { if (root()) navigateAgenda(new URL(location.href), { push: false, preserve: true }); });
