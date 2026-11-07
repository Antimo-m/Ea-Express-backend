for (const page of document.querySelectorAll('[data-rider-day]')) {
    const form = page.querySelector('[data-rider-day-period]');
    const results = page.querySelector('[data-rider-day-results]');
    const feedback = page.querySelector('[data-rider-day-error]');
    let controller, generation = 0;
    const updateBounds = () => { form.elements.to.min = form.elements.from.value || ''; };
    form.addEventListener('input', updateBounds);
    form.addEventListener('change', updateBounds);

    async function load(url) {
        controller?.abort();
        controller = new AbortController();
        const ticket = ++generation;
        const scroll = { left: window.scrollX, top: window.scrollY, behavior: 'instant' };
        results.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok || response.redirected) throw new Error('Periodo non disponibile. Verifica le date e riprova.');
            const document = new DOMParser().parseFromString(await response.text(), 'text/html');
            const fresh = document.querySelector('[data-rider-day]');
            if (!fresh?.querySelector('[data-rider-day-results]')) throw new Error('Aggiornamento non disponibile. Riprova.');
            if (ticket !== generation) return;
            results.replaceChildren(...fresh.querySelector('[data-rider-day-results]').childNodes);
            page.querySelector('[data-rider-day-caption]').textContent = fresh.querySelector('[data-rider-day-caption]').textContent;
            page.querySelector('.rider-day-context').replaceChildren(...fresh.querySelector('.rider-day-context').childNodes);
            const nextForm = fresh.querySelector('[data-rider-day-period]');
            for (const input of [...form.querySelectorAll('input')]) {
                const next = nextForm.elements.namedItem(input.name);
                if (next) input.value = next.value;
                else if (input.type === 'hidden') input.remove();
            }
            updateBounds();
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
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const url = new URL(form.action);
        for (const [name, value] of new FormData(form)) if (value) url.searchParams.set(name, value);
        load(url);
    });
    page.addEventListener('click', event => {
        const link = event.target.closest('[data-rider-day-reset], .rider-day-tabs a, .workspace-pagination a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
        event.preventDefault();
        load(new URL(link.href));
    });
    window.addEventListener('popstate', () => load(new URL(location.href)));
    window.addEventListener('pagehide', () => controller?.abort());
}
