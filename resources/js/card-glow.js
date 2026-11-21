/**
 * Riflesso delle card: con un puntatore preciso (mouse, trackpad) la luce segue il cursore sulla card.
 * Un solo ascoltatore per tutta la pagina e un aggiornamento per fotogramma; nessun effetto su touch
 * o con «riduci movimento».
 */
export const glowCards = [
    '.metric-card', '.operational-order', '.history-order', '.pickup-order-card', '.dashboard-order-preview', '.rider-stop-card',
    '.rider-now-card', '.rider-kpis > div', '.operations-kpis > div', '.balance-key', '.money-row', '.cash-movement', '.control-panel',
    '.day-progress-facts > div', '.rider-receipt', '.rider-wallet-line', '.directory-profile', '.profile-section', '.surface.section-panel',
].join(',');

const fine = window.matchMedia('(hover: hover) and (pointer: fine)');
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
let frame = 0, pending;

document.addEventListener('pointermove', event => {
    if (!fine.matches || reduced.matches) return;
    pending = event;
    if (frame) return;
    frame = requestAnimationFrame(() => {
        frame = 0;
        const card = pending.target instanceof Element ? pending.target.closest(glowCards) : null;
        if (!card) return;
        const box = card.getBoundingClientRect();
        card.style.setProperty('--glow-x', `${pending.clientX - box.left}px`);
        card.style.setProperty('--glow-y', `${pending.clientY - box.top}px`);
    });
}, { passive: true });
