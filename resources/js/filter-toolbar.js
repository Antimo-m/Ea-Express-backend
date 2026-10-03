const mobile = window.matchMedia('(max-width: 575px)');

for (const form of document.querySelectorAll('[data-filter-toolbar]')) {
    form.dataset.filterEnhanced = 'true';
    const primary = form.querySelector('[data-filter-primary]');
    const secondary = form.querySelector('[data-filter-secondary]');
    const secondaryFields = form.querySelector('[data-filter-secondary-fields]');
    const trigger = form.querySelector('[data-filter-more]');
    const dialog = form.querySelector('[data-filter-dialog]');
    const fields = dialog.querySelector('[data-filter-dialog-fields]');
    const moved = [];
    let snapshot = [];
    let ranges = [];
    const controls = () => [...form.querySelectorAll('input[name],select[name]')];
    const defaultValue = control => control.dataset.filterDefault ?? '';
    const isActive = control => control.value !== defaultValue(control) && Boolean(control.value) && !control.disabled && !control.closest('[data-custom-dates]')?.hidden;
    const applied = new URLSearchParams(location.search);
    const update = () => {
        const sources = mobile.matches ? [primary,secondaryFields] : [secondaryFields];
        const active = sources.filter(Boolean).flatMap(source => [...source.querySelectorAll('input[name],select[name]')]).filter(isActive);
        const count = new Set(active.map(control=>['from','to'].includes(control.name) ? 'range' : control.name)).size;
        form.querySelector('[data-filter-more-label]').textContent = mobile.matches ? 'Filtri' : 'Altri filtri';
        const badge = form.querySelector('[data-filter-count]');
        badge.textContent = `· ${count}`; badge.hidden = !count;
        trigger.hidden = !(secondary || (mobile.matches && form.classList.contains('filter-toolbar--data') && primary.querySelector('input,select')) || (mobile.matches && form.classList.contains('filter-toolbar--analysis') && primary.querySelector('input,select')));
    };
    const restoreNodes = () => {
        for (const [node, marker] of moved.splice(0)) { marker.replaceWith(node); }
    };
    const close = (discard = true) => {
        if (!dialog.open) return;
        if (discard) for (const [control, value] of snapshot) { control.value = value; control.dispatchEvent(new Event('change', {bubbles:true})); }
        if (discard) for (const [dates, hidden] of ranges) { dates.hidden = hidden; const button = dates.closest('[data-filter-range]').querySelector('[data-custom-period]'); button.setAttribute('aria-expanded',String(!hidden)); button.classList.toggle('active',!hidden); }
        dialog.close(); restoreNodes(); trigger.setAttribute('aria-expanded', 'false'); update(); trigger.focus();
    };
    const move = container => {
        for (const node of [...container.children]) {
            if (node.matches('input[type="hidden"]')) continue;
            const marker = document.createComment('filter-home'); node.before(marker); moved.push([node,marker]); fields.append(node);
        }
    };
    trigger.addEventListener('click', () => {
        if (dialog.open) { close(); return; }
        snapshot = controls().map(control => [control,control.value]);
        ranges = [...form.querySelectorAll('[data-custom-dates]')].map(dates=>[dates,dates.hidden]);
        if (mobile.matches) move(primary);
        if (secondaryFields) move(secondaryFields);
        dialog.querySelector('[data-filter-dialog-title]').textContent = mobile.matches ? 'Filtri' : 'Altri filtri';
        trigger.setAttribute('aria-expanded', 'true');
        if (mobile.matches) dialog.showModal();
        else {
            dialog.show(); const rect = trigger.getBoundingClientRect();
            dialog.style.left = `${Math.max(12,Math.min(rect.left,innerWidth-380))}px`;
            dialog.style.top = `${Math.max(12,Math.min(rect.bottom+8,innerHeight-dialog.offsetHeight-12))}px`;
        }
        fields.querySelector('.ea-picker-trigger,input:not(.ea-picker-source),select:not(.ea-picker-source)')?.focus();
    });
    dialog.querySelector('[data-filter-close]').addEventListener('click', () => close());
    document.addEventListener('cancel', event => { if (event.target === dialog) { event.preventDefault(); event.stopImmediatePropagation(); close(); } }, true);
    dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); close(); }
        if (event.key === 'Tab') {
            const items = [...dialog.querySelectorAll('button:not(:disabled),a[href],input:not(.ea-picker-source):not([type="hidden"])')].filter(node=>node.getClientRects().length);
            if (event.shiftKey && document.activeElement === items[0]) { event.preventDefault(); items.at(-1)?.focus(); }
            else if (!event.shiftKey && document.activeElement === items.at(-1)) { event.preventDefault(); items[0]?.focus(); }
        }
    });
    document.addEventListener('pointerdown', event => {
        if (dialog.open && !mobile.matches && !dialog.contains(event.target) && !trigger.contains(event.target) && !event.target.closest('.ea-picker-popup')) close();
    });
    let viewportWidth = innerWidth;
    window.addEventListener('resize', () => {
        if (innerWidth !== viewportWidth) { close(); viewportWidth = innerWidth; update(); }
    });
    if (secondary) secondary.hidden = true;
    for (const range of form.querySelectorAll('[data-filter-range]')) {
        const button = range.querySelector('[data-custom-period]');
        const dates = range.querySelector('[data-custom-dates]');
        const initialMode = range.querySelector('[data-period-mode]')?.value;
        const sync = () => {
            const active = !dates.hidden;
            button.classList.toggle('active',active); button.setAttribute('aria-expanded',String(active));
            // Presets submit their current range, while keeping the custom editors out of view.
            for (const context of range.querySelectorAll('[data-period-year]')) context.disabled = active;
            const mode = range.querySelector('[data-period-mode]'); if (mode) mode.value = active ? 'custom' : initialMode;
        };
        button.addEventListener('click', () => { dates.hidden = !dates.hidden; sync(); }); sync();
    }
    form.addEventListener('submit', () => {
        if (dialog.open) close(false);
        // GET submissions start from page one, preserving all filter controls including hidden context.
    });
    const chips = form.querySelector('[data-filter-chips]');
    const labels = {q:'Ricerca',zone:'Zona',from:'Dal',to:'Al',direction:'Direzione',id:'Sospeso'};
    let rangeChip = false;
    for (const control of controls()) {
        if (!applied.has(control.name) || !isActive(control) || ['year','period','month','date','view'].includes(control.name)) continue;
        const interval = ['from','to'].includes(control.name);
        if (interval && rangeChip) continue;
        if (interval) rangeChip = true;
        const label = interval ? 'Periodo' : control.dataset.filterLabel || labels[control.name] || control.labels?.[0]?.textContent.trim() || control.name;
        const value = interval ? ['from','to'].map(name=>form.querySelector(`[name="${name}"]`)?.value || '…').join(' – ') : control.tagName === 'SELECT' ? control.selectedOptions[0]?.textContent.trim() : control.value;
        const link = document.createElement('a'); link.className = 'active-filter-chip';
        const url = new URL(location.href); url.searchParams.delete(control.name);
        if (interval) {
            url.searchParams.delete('from'); url.searchParams.delete('to');
            if (url.searchParams.get('period') === 'custom') url.searchParams.delete('period');
            url.searchParams.delete('year'); url.searchParams.delete('month');
        }
        for (const name of [...url.searchParams.keys()]) if (name === 'page' || name.endsWith('_page')) url.searchParams.delete(name);
        link.href = url.href; link.textContent = `${label}: ${value} ×`; link.setAttribute('aria-label',`Rimuovi filtro ${label}: ${value}`); chips.append(link);
    }
    chips.hidden = !chips.children.length;
    update();
    // Search and primary controls submit together through the visible Apply control or Enter.
}
