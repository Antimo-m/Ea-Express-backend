for (const directory of document.querySelectorAll('[data-directory]')) {
    const links = [...directory.querySelectorAll('[data-directory-select]')];
    const panels = [...directory.querySelectorAll('[data-directory-panel]')];
    const search = directory.querySelector('[data-directory-search]');
    const empty = directory.querySelector('[data-directory-empty]');
    const noSelection = directory.querySelector('[data-directory-no-selection]');
    const mobile = () => window.matchMedia('(max-width: 767px)').matches;
    function select(link, { navigate = false } = {}) {
        noSelection.hidden = Boolean(link);
        if (!link) { panels.forEach(panel => { panel.hidden = true; }); return; }
        const panel = panels.find(item => item.dataset.directoryPanel === link.dataset.directorySelect);
        if (!panel) return;
        links.forEach(item => item === link ? item.setAttribute('aria-current', 'true') : item.removeAttribute('aria-current'));
        panels.forEach(item => { item.hidden = item !== panel; });
        if (navigate) {
            const url = new URL(location.href); url.hash = panel.id; history.replaceState(null, '', url);
            if (mobile()) { panel.scrollIntoView({ behavior: 'instant', block: 'start' }); panel.querySelector('[data-directory-heading]')?.focus({ preventScroll: true }); }
        }
    }
    directory.addEventListener('click', event => {
        const link = event.target.closest('[data-directory-select]');
        if (link && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey && event.button === 0) {
            event.preventDefault(); select(link, { navigate: true });
        }
        if (event.target.closest('[data-directory-return]')) {
            const selected = links.find(item => item.hasAttribute('aria-current') && !item.hidden);
            selected?.scrollIntoView({ behavior: 'instant', block: 'center' }); selected?.focus({ preventScroll: true });
        }
    });
    directory.querySelector('[data-directory-list]').addEventListener('keydown', event => {
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) || event.altKey || event.ctrlKey || event.metaKey) return;
        const visible = links.filter(link => !link.hidden), index = visible.indexOf(document.activeElement);
        if (index < 0) return;
        event.preventDefault();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? visible.length - 1 : Math.max(0, Math.min(visible.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1)));
        visible[next].focus(); select(visible[next]);
    });
    search.addEventListener('input', () => {
        const term = search.value.trim().toLocaleLowerCase('it');
        links.forEach(link => { link.hidden = !link.dataset.directoryName.toLocaleLowerCase('it').includes(term); });
        const visible = links.filter(link => !link.hidden);
        empty.hidden = visible.length > 0;
        select(visible.find(link => link.hasAttribute('aria-current')) || visible[0]);
    });
    const fromHash = panels.find(panel => '#'+panel.id === location.hash);
    select(links.find(link => link.dataset.directorySelect === fromHash?.dataset.directoryPanel) || links[0]);
}

const inbox = document.querySelector('[data-inbox]');
if (inbox) {
    const search = inbox.querySelector('[data-inbox-search]');
    let filter = 'all';
    function applyFilters() {
        const term = search.value.trim().toLocaleLowerCase('it');
        let visible = 0;
        for (const link of inbox.querySelectorAll('[data-conversation-link]')) {
            link.hidden = !link.dataset.inboxName.toLocaleLowerCase('it').includes(term) || (filter === 'unread' && Number(link.dataset.inboxUnread) === 0);
            if (!link.hidden) visible++;
        }
        inbox.querySelector('[data-inbox-empty]').hidden = visible > 0 || !inbox.querySelector('[data-conversation-link]');
    }
    search.addEventListener('input', applyFilters);
    inbox.addEventListener('click', event => {
        const button = event.target.closest('[data-inbox-filter]');
        if (button) {
            filter = button.dataset.inboxFilter;
            inbox.querySelectorAll('[data-inbox-filter]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
            applyFilters();
        }
        if (event.target.closest('[data-inbox-return]')) inbox.querySelector('#inbox-heading').focus();
    });
    window.addEventListener('ea:order-list-rendered', event => { if (event.detail.region === 'conversations') applyFilters(); });
}
