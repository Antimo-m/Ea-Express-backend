for (const widget of document.querySelectorAll('[data-account-multiselect]')) {
    const source = widget.querySelector('[data-account-source]');
    const controls = widget.querySelector('[data-account-controls]');
    const menu = widget.querySelector('[data-account-menu]');
    const search = widget.querySelector('[data-account-search]');
    const choices = [...widget.querySelectorAll('[data-account-choice]')];
    const selected = widget.querySelector('[data-account-selected]');
    const clear = widget.querySelector('[data-account-clear]');
    const count = widget.querySelector('[data-account-count]');
    const status = widget.querySelector('[data-account-status]');

    function sync() {
        const selectedOptions = [...source.selectedOptions];
        const values = new Set(selectedOptions.map(option => option.value));
        choices.forEach(choice => { choice.checked = values.has(choice.value); });
        count.textContent = selectedOptions.length ? `${selectedOptions.length} account selezionati` : 'Tutti gli account';
        clear.disabled = selectedOptions.length === 0;
        status.textContent = count.textContent;
        selected.replaceChildren(...selectedOptions.map(option => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'active-filter-chip';
            button.textContent = `${option.dataset.accountName} ×`;
            button.setAttribute('aria-label', `Rimuovi account ${option.dataset.accountName}`);
            button.addEventListener('click', () => {
                option.selected = false;
                source.dispatchEvent(new Event('change', { bubbles: true }));
                menu.querySelector('summary').focus();
            });
            return button;
        }));
    }

    source.addEventListener('change', sync);
    choices.forEach(choice => choice.addEventListener('change', () => {
        const option = [...source.options].find(item => item.value === choice.value);
        option.selected = choice.checked;
        source.dispatchEvent(new Event('change', { bubbles: true }));
    }));
    clear.addEventListener('click', () => {
        [...source.options].forEach(option => { option.selected = false; });
        source.dispatchEvent(new Event('change', { bubbles: true }));
        search.focus();
    });
    search.addEventListener('input', () => {
        const term = search.value.trim().toLocaleLowerCase('it');
        let visible = 0;
        choices.forEach(choice => {
            const label = choice.closest('label');
            label.hidden = !label.textContent.toLocaleLowerCase('it').includes(term);
            if (!label.hidden) visible++;
        });
        widget.querySelector('[data-account-empty]').hidden = visible > 0;
    });
    search.addEventListener('keydown', event => {
        if (event.key === 'Enter') event.preventDefault();
    });
    menu.addEventListener('toggle', () => {
        if (menu.open) search.focus();
    });
    menu.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menu.open) {
            event.preventDefault();
            event.stopPropagation();
            menu.open = false;
            menu.querySelector('summary').focus();
        }
    });
    document.addEventListener('pointerdown', event => {
        if (menu.open && !widget.contains(event.target)) menu.open = false;
    });
    source.hidden = true;
    controls.hidden = false;
    widget.querySelector('label[for]').addEventListener('click', event => {
        event.preventDefault();
        menu.open = true;
        search.focus();
    });
    sync();
}

for (const dialog of document.querySelectorAll('[data-finance-wizard]')) {
    const steps = [...dialog.querySelectorAll('[data-finance-step]')];
    function showStep(name) {
        if (dialog.querySelector('form[aria-busy="true"]')) return;
        const step = steps.find(panel => panel.dataset.financeStep === name);
        if (!step) return;
        steps.forEach(panel => { panel.hidden = panel !== step; });
        const description = dialog.querySelector('.modal-heading p');
        if (description) description.hidden = name !== 'choose';
        document.dispatchEvent(new Event('ea:controls-updated'));
        const focus = [...step.querySelectorAll('button,input:not([type="hidden"]),.ea-picker-trigger')].find(node => node.getClientRects().length && !node.disabled);
        focus?.focus();
    }
    dialog.addEventListener('click', event => {
        const control = event.target.closest('[data-finance-go]');
        if (control) showStep(control.dataset.financeGo);
    });
    dialog.addEventListener('close', () => showStep('choose'));

}

document.addEventListener('click', event => {
    if (event.target.closest('[data-dialog-open="balance-add"]')) document.querySelector('[data-finance-step="choose"] button')?.focus();
    const opener = event.target.closest('.row-actions-menu [data-dialog-open]');
    if (opener) {
        const menu = opener.closest('details');
        const dialog = document.getElementById(opener.dataset.dialogOpen);
        menu.open = false;
        if (dialog) dialog.returnFocus = menu.querySelector('summary');
    }
    for (const menu of document.querySelectorAll('.row-actions-menu[open]')) {
        if (!menu.contains(event.target)) menu.open = false;
    }
});
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape' || document.querySelector('dialog[open]')) return;
    const menu = document.activeElement?.closest('.row-actions-menu[open]');
    if (menu) { menu.open = false; menu.querySelector('summary').focus(); }
});
