/**
 * Immediate feedback for full page navigations and classic form posts in the staff workspace.
 * Fetch-based actions keep their own busy states; this module only handles requests that leave the page.
 */
const bar = document.createElement('div');
bar.className = 'page-progress';
bar.setAttribute('role', 'progressbar');
bar.setAttribute('aria-label', 'Caricamento della pagina');
bar.hidden = true;
document.body.append(bar);

let timer;
let fallback;
function start() {
    clearTimeout(timer);
    clearTimeout(fallback);
    // Downloads and responses that keep the current page must not leave the bar running.
    fallback = setTimeout(stop, 15000);
    // A short delay avoids flashing the bar for instant navigations.
    timer = setTimeout(() => { bar.hidden = false; bar.classList.add('is-active'); document.querySelector('main')?.setAttribute('aria-busy', 'true'); }, 150);
}
function stop() {
    clearTimeout(timer);
    clearTimeout(fallback);
    bar.hidden = true;
    bar.classList.remove('is-active');
    document.querySelector('main')?.removeAttribute('aria-busy');
}

document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[href]');
    if (!link || link.target === '_blank' || link.hasAttribute('download') || link.dataset.noProgress !== undefined) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || (url.pathname === location.pathname && url.search === location.search && url.hash)) return;
    start();
});

const submitted = new WeakSet();
document.addEventListener('submit', event => {
    const form = event.target;
    if (event.defaultPrevented || form.target === '_blank') return;
    const writes = (form.getAttribute('method') || 'get').toLowerCase() !== 'get';
    if (writes && submitted.has(form)) { event.preventDefault(); return; }
    if (writes) {
        submitted.add(form);
        form.setAttribute('aria-busy', 'true');
    }
    start();
});

window.addEventListener('pageshow', () => {
    stop();
    document.querySelectorAll('form[aria-busy="true"]').forEach(form => { submitted.delete(form); form.removeAttribute('aria-busy'); });
});
window.addEventListener('pagehide', stop);
