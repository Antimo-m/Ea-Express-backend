/**
 * Contatori animati: quando un aggiornamento cambia un numero con [data-count-to], il valore scorre da quello
 * mostrato prima al nuovo dato del server. Al primo caricamento il numero è già quello reale: nessuna animazione inventata.
 * Con «riduci movimento» il numero compare direttamente.
 */
const previous = new Map();
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

function keyOf(node) {
    const scope = node.closest('[data-rider-day], [data-live-region], main');
    return `${scope?.dataset.riderDay ?? scope?.dataset.liveRegion ?? 'main'}:${[...document.querySelectorAll('[data-count-to]')].indexOf(node)}:${node.closest('[class]')?.className ?? ''}`;
}

function animate(node) {
    if (node.dataset.countAnimated) return;
    node.dataset.countAnimated = 'true';
    const target = Number(node.dataset.countTo);
    if (!Number.isFinite(target)) return;
    const key = keyOf(node);
    const start = previous.has(key) ? previous.get(key) : target;
    previous.set(key, target);
    if (reduced.matches || start === target) { node.textContent = String(target); return; }
    const duration = 700, began = performance.now();
    const step = now => {
        const progress = Math.min(1, (now - began) / duration);
        node.textContent = String(Math.round(start + (target - start) * (1 - (1 - progress) ** 3)));
        if (progress < 1) requestAnimationFrame(step); else node.textContent = String(target);
    };
    requestAnimationFrame(step);
}

export function animateCounters(root = document) {
    root.querySelectorAll('[data-count-to]').forEach(animate);
}

animateCounters();
window.addEventListener('ea:workspace-rendered', () => animateCounters());
document.addEventListener('ea:controls-updated', () => animateCounters());
