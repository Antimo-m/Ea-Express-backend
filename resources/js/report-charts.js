for (const chart of document.querySelectorAll('[data-trend]')) {
    const points = [...chart.querySelectorAll('[data-chart-point]')];
    const svg = chart.querySelector('svg');
    const ticks = [...chart.querySelectorAll('[data-chart-tick]')];
    const layoutTicks = () => {
        const width = svg.getBoundingClientRect().width;
        const count = Math.max(2, Math.min(points.length, Math.floor(width / 84)));
        const visible = new Set(Array.from({length: count}, (_, index) => Math.round(index * (points.length - 1) / (count - 1))));
        ticks.forEach((tick, index) => { tick.style.display = visible.has(index) ? '' : 'none'; });
        svg.querySelectorAll('.chart-axis').forEach(axis => { axis.style.fontSize = `${Math.max(18, 12 * 920 / Math.max(1, width))}px`; });
    };
    new ResizeObserver(layoutTicks).observe(svg);
    layoutTicks();
    const range = chart.querySelector('[data-chart-range]');
    const output = chart.querySelector('[data-chart-output]');
    const money = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });
    function select(index, focus = false) {
        const point = points[Math.max(0, Math.min(points.length - 1, index))];
        if (!point) return;
        points.forEach(node => { node.classList.toggle('is-selected', node === point); node.setAttribute('tabindex', node === point ? '0' : '-1'); });
        range.value = point.dataset.chartPoint;
        range.setAttribute('aria-valuetext', point.dataset.label);
        const data = point.dataset;
        output.textContent = output.dataset.kind === 'cash'
            ? `${data.label} · Entrate +${money.format(Number(data.incoming) / 100)} · Uscite −${money.format(Number(data.outgoing) / 100)}`
            : `${data.label} · ${data.orders} richieste`;
        if (focus) point.focus();
    }
    points.forEach((point, index) => {
        point.addEventListener('pointerenter', () => select(index));
        point.addEventListener('click', () => select(index));
        point.addEventListener('focus', () => select(index));
        point.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            select(event.key === 'Home' ? 0 : event.key === 'End' ? points.length - 1 : index + (event.key === 'ArrowRight' ? 1 : -1), true);
        });
    });
    range.addEventListener('input', () => select(Number(range.value)));
    chart.querySelectorAll('[data-chart-series]').forEach(button => {
        button.addEventListener('click', () => {
            const visible = button.getAttribute('aria-pressed') !== 'true';
            button.setAttribute('aria-pressed', String(visible));
            chart.querySelectorAll(`[data-series="${button.dataset.chartSeries}"]`).forEach(node => { node.style.opacity = visible ? '1' : '0.12'; });
        });
    });
    select(0);
}
