/**
 * Evidenzia un badge di stato soltanto quando il suo stato è appena cambiato con un aggiornamento reale.
 * L'effetto dura una volta sola (riempimento e alone) e poi il badge torna fermo e leggibile.
 */
export function markStatusChange(badge) {
    if (!badge) return;
    badge.classList.remove('is-status-changed');
    void badge.offsetWidth;
    badge.classList.add('is-status-changed');
    const finish = event => { if (event.target !== badge || event.animationName !== 'status-glow') return; badge.classList.remove('is-status-changed'); badge.removeEventListener('animationend', finish); };
    badge.addEventListener('animationend', finish);
}

/** Firma dello stato mostrato dal badge: testo e tono, per distinguere un vero cambio da un nuovo rendering. */
export function statusSignature(badge) {
    return badge ? `${badge.className.replace(/\bis-status-changed\b/g, '').trim()}|${badge.textContent.trim()}` : null;
}
