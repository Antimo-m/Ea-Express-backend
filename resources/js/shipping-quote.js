export function attachShippingQuote(box) {
    const form = box.closest('form');
    const feedback = form.querySelector('[data-territory-feedback]');
    const names = ['delivery_city', 'delivery_postal_code', 'shipping_type', 'delivery_address', 'delivery_zone', 'delivery_province', 'delivery_region'];
    let timer, controller, sequence = 0;
    const render = (state, title, lines = []) => {
        box.dataset.state = state;
        box.setAttribute('aria-busy', String(state === 'loading'));
        const heading = document.createElement('strong'); heading.textContent = title;
        box.replaceChildren(heading);
        for (const line of lines.filter(Boolean)) {
            const paragraph = document.createElement('p'); paragraph.textContent = line; box.append(paragraph);
        }
    };
    const load = () => {
        clearTimeout(timer); controller?.abort(); const current = ++sequence;
        const city = form.elements.delivery_city.value.trim();
        const postal = form.elements.delivery_postal_code.value.trim();
        feedback.textContent = '';
        for (const name of ['delivery_city', 'delivery_postal_code']) form.elements[name].removeAttribute('aria-invalid');
        if (!city || (postal && !/^\d{5}$/.test(postal))) {
            render('empty', 'Completa la destinazione', [!city ? 'Inserisci il comune di consegna. Il CAP viene risolto automaticamente quando è univoco; altrimenti specifica un CAP di 5 cifre.' : 'Inserisci un CAP valido di 5 cifre.']);
            return;
        }
        render('loading', 'Ricalcolo tariffa…');
        timer = setTimeout(async () => {
            const requestController = new AbortController();
            controller = requestController;
            const timeout = setTimeout(() => requestController.abort(), 15000);
            try {
                const params = new URLSearchParams({city, postal_code: postal, shipping_type: form.elements.shipping_type.value, street: form.elements.delivery_address.value, zone: form.elements.delivery_zone.value, province: form.elements.delivery_province.value, region: form.elements.delivery_region.value});
                const response = await fetch(`/rates/quote?${params}`, {signal: requestController.signal, headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
                if (!response.ok) throw new Error('quote');
                const data = await response.json();
                if (current !== sequence) return;
                if (!postal && data.territory_valid && data.postal_code) form.elements.delivery_postal_code.value = data.postal_code;
                if (data.territory_valid === false) {
                    feedback.textContent = data.reason;
                    for (const name of ['delivery_city', 'delivery_postal_code']) form.elements[name].setAttribute('aria-invalid', 'true');
                }
                if (!data.available || !Number.isInteger(data.price_cents)) {
                    render('unavailable', 'Tariffa non disponibile', [data.reason || 'Nessuna tariffa compatibile nel listino.']);
                    return;
                }
                render('ready', new Intl.NumberFormat('it-IT', {style: 'currency', currency: 'EUR'}).format(data.price_cents / 100), [
                    [city, form.elements.delivery_province.value, `CAP ${data.postal_code || postal}`].filter(Boolean).join(' · '),
                    [data.area, data.zone].filter(Boolean).join(' · '),
                    data.shipping_type === 'external' ? 'Fuori regione · Rete EA-Express' : 'Regionale · Rete EA-Express',
                    data.delivery_time || (data.delivery_days_min ? `${data.delivery_days_min}–${data.delivery_days_max} giorni lavorativi dal ritiro` : 'Tempi da confermare'),
                    data.reason,
                ]);
            } catch {
                if (current === sequence) render('error', 'Non è stato possibile calcolare la tariffa', ['Verifica CAP e destinazione e riprova.']);
            } finally { clearTimeout(timeout); }
        }, 350);
    };
    for (const name of names) {
        form.elements[name].addEventListener('input', load);
        form.elements[name].addEventListener('change', load);
    }
    load();
    return () => { clearTimeout(timer); sequence++; controller?.abort(); for (const name of names) { form.elements[name].removeEventListener('input', load); form.elements[name].removeEventListener('change', load); } };
}
