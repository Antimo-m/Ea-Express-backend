export function bindFinancialForms(root = document) {
    for (const form of root.querySelectorAll('[data-financial-form]')) {
        if (form.dataset.financialBound) continue;
        form.dataset.financialBound = 'true';
        let button = form.querySelector('button[type="submit"], button:not([type])');
        const feedback = document.createElement('p');
        feedback.setAttribute('role', 'status');
        feedback.className = 'form-feedback small mt-2';
        form.append(feedback);
        let busy = false;
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy) return;
            busy = true;
            button = event.submitter || button;
            const originalContent = Array.from(button.childNodes, node => node.cloneNode(true));
            const accessibleLabel = button.getAttribute('aria-label');
            const data = new FormData(form);
            if (event.submitter?.name) data.append(event.submitter.name, event.submitter.value);
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.setAttribute('aria-label', 'Salvataggio in corso');
            form.setAttribute('aria-busy', 'true');
            feedback.textContent = '';
            feedback.setAttribute('role', 'status');
            try {
                const response = await fetch(form.getAttribute('action'), { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, body: data });
                const result = response.headers.get('content-type')?.includes('application/json') ? await response.json() : {};
                if (!response.ok || response.redirected) {
                    const retry = Number(response.headers.get('Retry-After')) || 60;
                    const message = response.status === 429 ? `Troppe richieste. Attendi ${retry} secondi prima di riprovare.`
                        : response.status === 419 || response.status === 401 || response.redirected ? 'Sessione scaduta. Ricarica la pagina e accedi nuovamente.'
                        : response.status === 422 ? Object.values(result.errors || {}).flat().join(' ')
                        : response.status === 409 ? result.message
                        : 'Salvataggio non confermato. Controlla il bilancio prima di riprovare.';
                    throw new Error(message);
                }
                feedback.textContent = result.message || 'Operazione registrata.';
                sessionStorage.setItem('ea:financial-feedback', feedback.textContent);
                form.closest('dialog')?.close();
                let redirect = new URL(location.href);
                try {
                    const destination = new URL(result.redirect || location.href, location.href);
                    if (destination.origin === redirect.origin && ['http:', 'https:'].includes(destination.protocol)) redirect = destination;
                } catch {
                    // A malformed destination falls back to the current page after a successful save.
                }
                location.assign(redirect.href);
            } catch (error) {
                feedback.textContent = error instanceof TypeError ? 'Connessione interrotta. Controlla il bilancio: il salvataggio potrebbe essere già riuscito.' : error.message;
                feedback.setAttribute('role', 'alert');
                busy = false;
                button.disabled = false;
                button.replaceChildren(...originalContent);
                button.removeAttribute('aria-busy');
                if (accessibleLabel !== null) button.setAttribute('aria-label', accessibleLabel);
                else button.removeAttribute('aria-label');
                form.removeAttribute('aria-busy');
            }
        });
    }
    const saved = sessionStorage.getItem('ea:financial-feedback');
    if (saved && root.querySelector('[data-financial-form]')) {
        sessionStorage.removeItem('ea:financial-feedback');
        const notice = document.createElement('p'); notice.className = 'alert alert-success'; notice.setAttribute('role', 'status'); notice.textContent = saved;
        (root.querySelector('main') || root).prepend(notice);
    }
}
bindFinancialForms();
