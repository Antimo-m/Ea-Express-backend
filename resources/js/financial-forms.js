export function bindFinancialForms(root = document) {
    for (const form of root.querySelectorAll('[data-financial-form]')) {
        if (form.dataset.financialBound) continue;
        form.dataset.financialBound = 'true';
        const button = form.querySelector('button[type="submit"], button:not([type])');
        const feedback = document.createElement('p');
        feedback.setAttribute('role', 'status');
        feedback.className = 'small mt-2';
        form.append(feedback);
        let busy = false;
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy) return;
            busy = true;
            const label = button.textContent;
            const data = new FormData(form);
            button.disabled = true;
            button.textContent = 'Salvataggio…';
            form.setAttribute('aria-busy', 'true');
            feedback.textContent = '';
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
                location.assign(result.redirect);
            } catch (error) {
                feedback.textContent = error instanceof TypeError ? 'Connessione interrotta. Controlla il bilancio: il salvataggio potrebbe essere già riuscito.' : error.message;
                feedback.setAttribute('role', 'alert');
                busy = false;
                button.disabled = false;
                button.textContent = label;
                form.removeAttribute('aria-busy');
            }
        });
    }
    const saved = sessionStorage.getItem('ea:financial-feedback');
    if (saved && root.querySelector('[data-financial-form]')) {
        sessionStorage.removeItem('ea:financial-feedback');
        const notice = document.createElement('p'); notice.className = 'alert alert-success'; notice.setAttribute('role', 'status'); notice.textContent = saved;
        root.querySelector('main').prepend(notice);
    }
}
bindFinancialForms();
