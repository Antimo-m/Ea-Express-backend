/**
 * Pagina operativa Rider: periodi rapidi, mese, anno e intervallo libero aggiornano filtri e risultati senza
 * ricaricare la pagina. Gli eventi sono delegati alla pagina perché filtri e risultati vengono sostituiti.
 */
for (const page of document.querySelectorAll('[data-rider-day]')) {
    const results = page.querySelector('[data-rider-day-results]');
    const filters = page.querySelector('[data-rider-day-filters]');
    const feedback = page.querySelector('[data-rider-day-error]');
    let controller, generation = 0;
    const updateBounds = form => { if (form?.elements.from && form.elements.to) form.elements.to.min = form.elements.from.value || ''; };
    page.querySelectorAll('[data-rider-day-period]').forEach(updateBounds);
    page.addEventListener('input', event => updateBounds(event.target.closest('[data-rider-day-period]')));

    async function load(url) {
        controller?.abort();
        controller = new AbortController();
        const ticket = ++generation;
        const scroll = { left: window.scrollX, top: window.scrollY, behavior: 'instant' };
        results.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok || response.redirected) throw new Error('Periodo non disponibile. Verifica le date e riprova.');
            const fresh = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('[data-rider-day]');
            if (!fresh?.querySelector('[data-rider-day-results]')) throw new Error('Aggiornamento non disponibile. Riprova.');
            if (ticket !== generation) return;
            results.replaceChildren(...fresh.querySelector('[data-rider-day-results]').childNodes);
            if (filters) filters.replaceChildren(...fresh.querySelector('[data-rider-day-filters]').childNodes);
            page.querySelector('[data-rider-day-caption]').textContent = fresh.querySelector('[data-rider-day-caption]').textContent;
            page.querySelectorAll('[data-rider-day-period]').forEach(updateBounds);
            history.replaceState(null, '', url);
            feedback.hidden = true;
            document.dispatchEvent(new Event('ea:controls-updated'));
            window.dispatchEvent(new Event('ea:workspace-rendered'));
            window.scrollTo(scroll);
            requestAnimationFrame(() => window.scrollTo(scroll));
        } catch (error) {
            if (error.name !== 'AbortError') { feedback.hidden = false; feedback.textContent = error.message; }
        } finally {
            if (ticket === generation) results.removeAttribute('aria-busy');
        }
    }
    page.addEventListener('submit', event => {
        const form = event.target.closest('[data-rider-day-period]');
        if (!form) return;
        event.preventDefault();
        if (!form.reportValidity()) return;
        const url = new URL(form.action);
        for (const [name, value] of new FormData(form)) if (value) url.searchParams.set(name, value);
        load(url);
    });
    page.addEventListener('click', event => {
        const link = event.target.closest('[data-rider-day-reset], [data-rider-period-link], .rider-day-tabs a, .workspace-pagination a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        load(new URL(link.href));
    });
    window.addEventListener('popstate', () => load(new URL(location.href)));
    window.addEventListener('pagehide', () => controller?.abort());
}
