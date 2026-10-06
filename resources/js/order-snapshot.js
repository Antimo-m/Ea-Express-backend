import { bindFinancialForms } from './financial-forms';

export function applyOrderSnapshot(html, { force = false, scroll } = {}) {
    const section = document.querySelector('[data-live-order]');
    const fresh = new DOMParser().parseFromString(html, 'text/html');
    const replacement = fresh.querySelector('[data-live-order]');
    if (!section || replacement?.dataset.liveOrder !== section.dataset.liveOrder) throw new Error('Aggiornamento dell’ordine non disponibile. Riprova.');
    if (Number(replacement.dataset.orderVersion) < Number(section.dataset.orderVersion)) return;
    section.dataset.orderVersion = replacement.dataset.orderVersion;
    const x = scroll?.x ?? window.scrollX, y = scroll?.y ?? window.scrollY;
    for (const selector of ['.order-operational-summary', '.order-overview', '.order-information', '.order-focus-summary', '.order-tracking-summary', '.order-timeline', '.rider-assignment-card', '.order-workflow']) {
        const current = section.querySelector(selector), next = replacement.querySelector(selector);
        if (!current || (!force && current.contains(document.activeElement))) continue;
        if (next) current.replaceWith(next);
        else current.remove();
    }
    const pointVersion = section.querySelector('[data-map-points] [name="version"]');
    const freshPointVersion = replacement.querySelector('[data-map-points] [name="version"]');
    if (pointVersion && freshPointVersion) pointVersion.value = freshPointVersion.value;
    for (const selector of ['.map-point-editor', '.gps-notice']) {
        const current = section.querySelector(selector), next = replacement.querySelector(selector);
        if (current && !next) current.remove();
        else if (!current && next) section.querySelector('[data-tracking-panel]')?.append(next);
    }
    bindFinancialForms(section);
    document.dispatchEvent(new Event('ea:controls-updated'));
    window.scrollTo({ left: x, top: y, behavior: 'instant' });
    requestAnimationFrame(() => window.scrollTo({ left: x, top: y, behavior: 'instant' }));
}
