let detailId = 0;
export function enhanceDataTables(root = document) {
    for (const table of root.querySelectorAll('main table')) {
        table.classList.add('data-table-adaptive');
        const headings = [...table.querySelectorAll('thead th')].map(cell => cell.textContent.trim());
        for (const row of table.querySelectorAll('tbody tr')) {
            if (row.dataset.adaptiveReady) continue;
            row.dataset.adaptiveReady = 'true';
            const cells = [...row.children];
            let column = 0;
            for (const cell of cells) {
                if (headings[column] && cell.colSpan === 1) cell.dataset.label = headings[column];
                column += cell.colSpan || 1;
            }
            if (cells.length < 3 || cells.some(cell => cell.colSpan > 1)) continue;
            const secondary = cells.slice(1,-1);
            secondary.forEach(cell => { cell.classList.add('row-secondary'); cell.id ||= `row-detail-${++detailId}`; });
            const action = document.createElement('td'); action.className = 'row-detail-action'; action.colSpan = headings.length;
            const button = document.createElement('button'); button.type = 'button'; button.className = 'row-detail-toggle';
            button.textContent = 'Mostra dettagli'; button.setAttribute('aria-expanded','false'); button.setAttribute('aria-controls', secondary.map(cell => cell.id).join(' '));
            button.addEventListener('click', () => { const expanded = row.classList.toggle('is-expanded'); button.setAttribute('aria-expanded', String(expanded)); button.textContent = expanded ? 'Nascondi dettagli' : 'Mostra dettagli'; });
            action.append(button); row.append(action);
        }
    }
}
enhanceDataTables();
const main = document.querySelector('main');
if (main) new MutationObserver(() => enhanceDataTables()).observe(main,{childList:true,subtree:true});
