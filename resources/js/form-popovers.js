let active;
let sequence = 0;
const selector = 'select:not([multiple]), input[type="date"], input[type="time"], input[type="datetime-local"], input[type="month"], input[list]';
const iso = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const romeToday = () => new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Rome' }).format(new Date());
const makeButton = (label, action, selected = false) => {
    const button = document.createElement('button');
    button.type = 'button'; button.textContent = label;
    button.className = selected ? 'is-selected' : '';
    button.addEventListener('click', action);
    return button;
};

export function attachFormPopovers(root = document) {
    const bindings = new Map();
    const bind = control => {
        if (control.dataset.popoverReady) return;
        control.dataset.popoverReady = 'true';
        if (control.tagName === 'SELECT' && /customer|rider|user|recipient|assignee/.test(control.name)) control.dataset.searchable = 'true';
        const trigger = document.createElement('button');
        trigger.type = 'button'; trigger.className = 'ea-picker-trigger';
        trigger.id = `ea-picker-${++sequence}`;
        const popupId = `${trigger.id}-popup`;
        const text = document.createElement('span');
        const icon = document.createElement('i'); icon.setAttribute('aria-hidden', 'true');
        icon.className = `bi bi-${control.tagName === 'SELECT' || control.hasAttribute('list') ? 'chevron-down' : control.type === 'time' ? 'clock' : 'calendar3'}`;
        trigger.append(text, icon);
        control.classList.add('ea-picker-source');
        control.after(trigger);
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-controls', popupId);
        const label = () => {
            const copy = control.labels?.[0]?.cloneNode(true);
            copy?.querySelectorAll('input, select, textarea, button, datalist').forEach(node => node.remove());
            return copy?.textContent.trim() || control.getAttribute('aria-label') || control.name || 'Scegli un valore';
        };
        const sync = () => {
            if (control.tagName === 'SELECT') text.textContent = control.selectedOptions[0]?.textContent || 'Seleziona…';
            else if (control.type === 'date' && control.value) text.textContent = new Date(`${control.value}T12:00:00`).toLocaleDateString('it-IT');
            else if (control.type === 'month' && control.value) text.textContent = new Date(`${control.value}-01T12:00:00`).toLocaleDateString('it-IT', { month: 'long', year: 'numeric' });
            else if (control.type === 'datetime-local' && control.value) text.textContent = new Date(control.value).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' });
            else text.textContent = control.value || (control.type === 'time' ? 'Scegli orario…' : control.hasAttribute('list') ? 'Scegli o scrivi…' : 'Scegli data…');
            if (control.closest('[data-filter-toolbar]')) text.textContent = `${control.dataset.filterLabel || label()}: ${text.textContent}`;
            trigger.disabled = control.disabled || control.readOnly || control.getAttribute('aria-busy') === 'true';
            trigger.setAttribute('aria-label', `${label()}${control.required ? ' (obbligatorio)' : ''}: ${text.textContent}`);
            trigger.setAttribute('aria-invalid', String(control.getAttribute('aria-invalid') === 'true'));
            for (const attribute of ['aria-describedby', 'aria-busy']) {
                if (control.hasAttribute(attribute)) trigger.setAttribute(attribute, control.getAttribute(attribute));
                else trigger.removeAttribute(attribute);
            }
            if (trigger.disabled && active?.control === control) active.close(false);
        };
        const open = () => {
            sync();
            if (trigger.disabled || control.readOnly) return;
            if (active?.control === control) { active.close(); return; }
            active?.close(false);
            const panel = document.createElement('div');
            panel.id = popupId; panel.className = 'form-popover ea-picker-popup';
            panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', label());
            panel.setAttribute('popover', 'manual');
            const host = control.closest('dialog') || document.body;
            host.append(panel);
            const isMobile = () => window.matchMedia('(max-width: 575px)').matches;
            const position = () => {
                panel.setAttribute('aria-modal', String(isMobile()));
                if (isMobile()) { panel.style.removeProperty('width'); panel.style.removeProperty('left'); panel.style.removeProperty('top'); return; }
                const rect = trigger.getBoundingClientRect();
                const width = Math.min(control.tagName === 'SELECT' || control.hasAttribute('list') ? Math.max(rect.width, 280) : 340, innerWidth - 24);
                panel.style.width = `${width}px`;
                panel.style.left = `${Math.max(12, Math.min(rect.left, innerWidth - width - 12))}px`;
                const height = panel.offsetHeight;
                panel.style.top = `${Math.max(12, rect.bottom + height + 8 <= innerHeight ? rect.bottom + 8 : rect.top - height - 8)}px`;
            };
            const close = (restore = true) => {
                if (active?.panel !== panel) return;
                active = undefined;
                panel.remove(); trigger.setAttribute('aria-expanded', 'false');
                document.removeEventListener('pointerdown', outside, true);
                document.removeEventListener('keydown', keys, true);
                window.removeEventListener('resize', position);
                window.removeEventListener('scroll', position, true);
                if (restore && trigger.isConnected) trigger.focus();
            };
            const outside = event => { if (!panel.contains(event.target) && !trigger.contains(event.target)) close(false); };
            const commit = value => {
                const setter = Object.getOwnPropertyDescriptor(control.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype, 'value').set;
                setter.call(control, value);
                control.dispatchEvent(new Event('input', { bubbles: true }));
                control.dispatchEvent(new Event('change', { bubbles: true }));
                sync(); close();
                requestAnimationFrame(() => { if (trigger.isConnected) sync(); });
            };
            const keys = event => {
                if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); close(); return; }
                if (event.key === 'Tab') {
                    if (!isMobile()) { close(); return; }
                    const items = [...panel.querySelectorAll('button:not(:disabled), input:not(:disabled), [tabindex="0"]')];
                    if ((event.shiftKey && document.activeElement === items[0]) || (!event.shiftKey && document.activeElement === items.at(-1))) {
                        event.preventDefault(); event.stopImmediatePropagation(); (event.shiftKey ? items.at(-1) : items[0])?.focus();
                    }
                    return;
                }
                if (!panel.contains(document.activeElement)) return;
                if (document.activeElement.tagName === 'BUTTON' && ['Enter', ' '].includes(event.key)) {
                    event.preventDefault(); document.activeElement.click(); return;
                }
                if (document.activeElement.matches('[data-calendar-day]')) return;
                const options = [...panel.querySelectorAll('[role="option"]:not(:disabled)')];
                if (options.length && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                    if (document.activeElement.tagName === 'INPUT' && ['Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    const index = options.indexOf(document.activeElement);
                    options[event.key === 'Home' ? 0 : event.key === 'End' ? options.length - 1 : Math.max(0, Math.min(options.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1)))]?.focus();
                }
            };
            active = { control, panel, close };
            const heading = document.createElement('header'); heading.className = 'picker-heading';
            const title = document.createElement('strong'); title.textContent = label();
            const dismiss = makeButton('×', () => close()); dismiss.setAttribute('aria-label', 'Chiudi selezione');
            heading.append(title, dismiss); panel.append(heading);
            const content = document.createElement('div'); content.className = 'picker-content'; panel.append(content);
            if (control.tagName === 'SELECT' || control.hasAttribute('list')) {
                active.refresh = renderSelect(control, content, commit, position);
            } else if (control.type === 'month') {
                renderMonth(control, content, commit, position);
            } else if (control.type === 'time') {
                renderTime(control, content, commit);
            } else if (control.type === 'datetime-local') {
                renderDateTime(control, content, commit, position);
            } else {
                renderCalendar(control, content, commit, position);
            }
            if (!control.required) {
                const clear = makeButton('Azzera selezione', () => commit('')); clear.className = 'picker-clear'; panel.append(clear);
            }
            trigger.setAttribute('aria-expanded', 'true');
            panel.showPopover?.(); position();
            const initialFocus = content.querySelector('input') || content.querySelector('.is-selected:not(:disabled)') || content.querySelector('[data-today]:not(:disabled)') || content.querySelector('button:not(:disabled)');
            initialFocus?.focus();
            document.addEventListener('pointerdown', outside, true); document.addEventListener('keydown', keys, true);
            window.addEventListener('resize', position); window.addEventListener('scroll', position, true);
        };
        const keyOpen = event => {
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) { event.preventDefault(); open(); }
        };
        const invalid = event => { event.preventDefault(); sync(); trigger.focus(); trigger.setAttribute('aria-invalid', 'true'); };
        const labelClick = event => {
            if (event.target.closest('label')?.control === control) {
                event.preventDefault();
                if (!trigger.contains(event.target)) trigger.focus();
            }
        };
        const reset = () => queueMicrotask(sync);
        trigger.addEventListener('click', open); trigger.addEventListener('keydown', keyOpen);
        control.addEventListener('input', sync); control.addEventListener('change', sync); control.addEventListener('invalid', invalid);
        control.form?.addEventListener('reset', reset); root.addEventListener('click', labelClick);
        sync();
        bindings.set(control, { sync, cleanup: () => {
            if (active?.control === control) active.close(false);
            trigger.remove(); control.classList.remove('ea-picker-source'); delete control.dataset.popoverReady;
            control.removeEventListener('input', sync); control.removeEventListener('change', sync); control.removeEventListener('invalid', invalid);
            control.form?.removeEventListener('reset', reset); root.removeEventListener('click', labelClick);
        } });
    };
    const scan = () => {
        for (const [control, binding] of bindings) {
            if (!root.contains(control)) { binding.cleanup(); bindings.delete(control); }
            else binding.sync();
        }
        root.querySelectorAll(selector).forEach(bind);
    };
    scan();
    root.addEventListener('ea:controls-updated', scan);
    const observer = new MutationObserver(records => {
        if (!records.some(record => !record.target.closest?.('.ea-picker-trigger, .ea-picker-popup') && (record.type === 'childList' || record.target.matches?.(selector) || record.target.closest?.('select, datalist')))) return;
        scan();
        const control = active?.control;
        if (control && records.some(record => record.target === control || control.contains(record.target) || control.list?.contains(record.target))) active.refresh?.();
    });
    observer.observe(root, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'readonly', 'required', 'value', 'selected', 'label', 'min', 'max', 'step', 'aria-invalid', 'aria-describedby', 'aria-busy'] });
    return () => { observer.disconnect(); root.removeEventListener('ea:controls-updated', scan); bindings.forEach(binding => binding.cleanup()); bindings.clear(); };
}

function renderSelect(control, content, commit, reposition) {
    const freeText = control.hasAttribute('list');
    const options = () => [...(freeText ? control.list?.options || [] : control.options)];
    const list = document.createElement('div'); list.className = 'picker-options';
    list.setAttribute('role', 'listbox'); list.setAttribute('aria-label', 'Valori disponibili');
    let search;
    const draw = (query = search?.value || '') => {
        list.setAttribute('aria-busy', String(control.getAttribute('aria-busy') === 'true'));
        if (control.getAttribute('aria-busy') === 'true') { list.replaceChildren(); const loading = document.createElement('p'); loading.setAttribute('role', 'status'); loading.textContent = 'Caricamento…'; list.append(loading); reposition(); return; }
        if (!search && (options().length > 7 || freeText || control.hasAttribute('data-searchable'))) addSearch();
        const focusedValue = list.contains(document.activeElement) ? document.activeElement.value : undefined;
        list.replaceChildren();
        for (const option of options().filter(option => (option.label || option.value).toLocaleLowerCase('it').includes(query.toLocaleLowerCase('it')))) {
            const item = makeButton(option.label || option.value || 'Nessuna selezione', () => commit(option.value), option.value === control.value);
            item.value = option.value;
            item.disabled = option.disabled || option.parentElement?.disabled;
            item.setAttribute('role', 'option'); item.setAttribute('aria-selected', String(option.value === control.value));
            list.append(item);
        }
        if (!list.children.length) { const empty = document.createElement('p'); empty.setAttribute('role', 'status'); empty.textContent = 'Nessun risultato disponibile.'; list.append(empty); }
        if (focusedValue !== undefined) {
            const option = [...list.querySelectorAll('button:not(:disabled)')].find(item => item.value === focusedValue);
            (option || search || list.querySelector('button:not(:disabled)') || content.parentElement.querySelector('button'))?.focus();
        }
        reposition();
    };
    function addSearch() {
        search = document.createElement('input'); search.type = 'text'; search.placeholder = freeText ? 'Scegli o scrivi liberamente…' : 'Cerca…';
        search.setAttribute('aria-label', freeText ? 'Scrivi un valore o cerca un suggerimento' : 'Cerca nelle opzioni'); search.autocomplete = 'off';
        if (freeText) { search.value = control.value; if (control.maxLength >= 0) search.maxLength = control.maxLength; }
        search.addEventListener('input', () => draw(search.value));
        search.addEventListener('keydown', event => {
            if (event.key === 'Enter') { event.preventDefault(); if (freeText) commit(search.value); else list.querySelector('[role="option"]:not(:disabled)')?.click(); }
        });
        content.prepend(search);
        if (freeText) content.insertBefore(makeButton('Conferma testo', () => commit(search.value)), list);
    }
    content.append(list); draw();
    return () => draw();
}

function renderTime(control, content, commit, initial = control.value) {
    const fields = document.createElement('div'); fields.className = 'time-popover-fields';
    const parts = (initial || '09:00').split(':');
    const inputs = ['Ore', 'Minuti'].map((name, index) => {
        const label = document.createElement('label'); label.textContent = name;
        const input = document.createElement('input'); input.type = 'number'; input.inputMode = 'numeric';
        input.min = '0'; input.max = index ? '59' : '23'; input.step = '1'; input.required = true; input.value = String(Number(parts[index]));
        label.append(input); fields.append(label); return input;
    });
    const candidate = control.cloneNode(); candidate.removeAttribute('id'); candidate.removeAttribute('name');
    const feedback = document.createElement('p'); feedback.className = 'picker-feedback'; feedback.setAttribute('role', 'status');
    const confirm = makeButton('✓ Conferma orario', () => { validate(); if (!confirm.disabled) commit(candidate.value); });
    const validate = () => {
        candidate.min = control.min; candidate.max = control.max; candidate.step = control.step;
        candidate.value = inputs.map(input => input.value.padStart(2, '0')).join(':');
        confirm.disabled = inputs.some(input => !input.validity.valid || !input.value) || !candidate.value || !candidate.validity.valid;
        feedback.textContent = confirm.disabled ? `Scegli un orario valido${control.min ? ` da ${control.min}` : ''}${control.max ? ` fino a ${control.max}` : ''}, rispettando l’intervallo previsto.` : '';
    };
    inputs.forEach(input => { input.addEventListener('input', validate); input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); validate(); if (!confirm.disabled) commit(candidate.value); } }); });
    content.append(fields, feedback, confirm); validate();
    return { inputs, value: () => inputs.map(input => input.value.padStart(2, '0')).join(':'), valid: () => { validate(); return !confirm.disabled; } };
}

function renderCalendar(control, content, commit, reposition) {
    let month = new Date(`${control.value || romeToday()}T12:00:00`); month.setDate(1);
    const enabled = value => {
        const candidate = control.cloneNode(); candidate.removeAttribute('id'); candidate.value = value;
        return candidate.validity.valid;
    };
    const draw = (focusDate, focusDirection) => {
        content.replaceChildren();
        const header = document.createElement('div'); header.className = 'calendar-heading';
        const previous = makeButton('‹', () => { month.setMonth(month.getMonth() - 1); draw(undefined, 'previous'); }); previous.setAttribute('aria-label', 'Mese precedente'); previous.dataset.direction = 'previous';
        const next = makeButton('›', () => { month.setMonth(month.getMonth() + 1); draw(undefined, 'next'); }); next.setAttribute('aria-label', 'Mese successivo'); next.dataset.direction = 'next';
        const title = document.createElement('strong'); title.textContent = month.toLocaleDateString('it-IT', { month: 'long', year: 'numeric' }); title.setAttribute('aria-live', 'polite');
        header.append(previous, title, next); content.append(header);
        const grid = document.createElement('div'); grid.className = 'calendar-grid'; grid.setAttribute('role', 'group'); grid.setAttribute('aria-label', title.textContent);
        ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'].forEach(day => { const name = document.createElement('span'); name.textContent = day; grid.append(name); });
        for (let i = 0; i < (month.getDay() + 6) % 7; i++) grid.append(document.createElement('span'));
        const last = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
        for (let day = 1; day <= last; day++) {
            const date = new Date(month.getFullYear(), month.getMonth(), day); const value = iso(date);
            const button = makeButton(String(day), () => commit(value), value === control.value);
            button.dataset.calendarDay = value; button.disabled = !enabled(value);
            button.setAttribute('aria-label', date.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
            button.setAttribute('aria-pressed', String(value === control.value));
            if (value === romeToday()) { button.dataset.today = ''; button.setAttribute('aria-current', 'date'); }
            button.addEventListener('keydown', event => {
                const delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[event.key];
                if (delta === undefined && !['Home', 'End', 'PageUp', 'PageDown'].includes(event.key)) return;
                event.preventDefault();
                const target = new Date(`${value}T12:00:00`);
                if (event.key === 'PageUp' || event.key === 'PageDown') { target.setDate(1); target.setMonth(target.getMonth() + (event.key === 'PageUp' ? -1 : 1)); }
                else target.setDate(target.getDate() + (delta ?? (event.key === 'Home' ? -((target.getDay() + 6) % 7) : 6 - ((target.getDay() + 6) % 7))));
                if (!enabled(iso(target))) return;
                month = new Date(target.getFullYear(), target.getMonth(), 1); draw(iso(target));
            });
            grid.append(button);
        }
        content.append(grid);
        const today = makeButton('Oggi', () => commit(romeToday())); today.disabled = !enabled(romeToday()); content.append(today);
        reposition();
        if (focusDate) content.querySelector(`[data-calendar-day="${focusDate}"]`)?.focus();
        else if (focusDirection) content.querySelector(`[data-direction="${focusDirection}"]`)?.focus();
    };
    draw();
}

function renderDateTime(control, content, commit, reposition) {
    const calendar = document.createElement('div'); const time = document.createElement('div');
    const dateControl = document.createElement('input'); dateControl.type = 'date'; dateControl.value = control.value.split('T')[0] || romeToday();
    dateControl.min = control.min.split('T')[0]; dateControl.max = control.max.split('T')[0];
    const timeControl = document.createElement('input'); timeControl.type = 'time'; timeControl.step = control.step || '60';
    const candidate = control.cloneNode(); candidate.removeAttribute('id'); candidate.removeAttribute('name');
    const feedback = document.createElement('p'); feedback.setAttribute('role', 'status'); feedback.className = 'picker-feedback';
    let clock;
    const confirm = makeButton('✓ Conferma data e ora', () => { validate(); if (!confirm.disabled) commit(candidate.value); });
    const validate = () => {
        candidate.value = `${dateControl.value}T${clock?.value() || '09:00'}`;
        confirm.disabled = !clock?.valid() || !candidate.validity.valid;
        feedback.textContent = confirm.disabled ? 'Scegli una data e un orario nell’intervallo consentito.' : '';
    };
    renderCalendar(dateControl, calendar, selectDate, reposition);
    function selectDate(value) { dateControl.value = value; renderCalendar(dateControl, calendar, selectDate, reposition); calendar.querySelector('.is-selected')?.focus(); validate(); }
    clock = renderTime(timeControl, time, () => { validate(); if (!confirm.disabled) commit(candidate.value); }, control.value.split('T')[1] || '09:00');
    time.querySelector('button').remove(); clock.inputs.forEach(input => input.addEventListener('input', validate));
    content.append(calendar, time, feedback, confirm); validate();
}

function renderMonth(control, content, commit, reposition) {
    let year = Number(control.value.split('-')[0]) || Number(romeToday().split('-')[0]);
    const draw = direction => {
        content.replaceChildren();
        const heading = document.createElement('div'); heading.className = 'calendar-heading';
        const previous = makeButton('‹', () => { year--; draw('previous'); }); previous.setAttribute('aria-label', 'Anno precedente'); previous.dataset.direction = 'previous';
        const next = makeButton('›', () => { year++; draw('next'); }); next.setAttribute('aria-label', 'Anno successivo'); next.dataset.direction = 'next';
        const title = document.createElement('strong'); title.textContent = String(year); title.setAttribute('aria-live', 'polite');
        heading.append(previous, title, next); content.append(heading);
        const months = document.createElement('div'); months.className = 'month-grid';
        for (let month = 0; month < 12; month++) {
            const value = `${year}-${String(month + 1).padStart(2, '0')}`;
            const candidate = control.cloneNode(); candidate.value = value;
            const item = makeButton(new Date(year, month, 1).toLocaleDateString('it-IT', { month: 'long' }), () => commit(value), value === control.value);
            item.disabled = !candidate.validity.valid; item.setAttribute('aria-pressed', String(value === control.value)); months.append(item);
        }
        content.append(months); reposition();
        if (direction) content.querySelector(`[data-direction="${direction}"]`)?.focus();
    };
    draw();
}
