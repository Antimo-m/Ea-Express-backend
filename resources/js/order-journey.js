// Percorso animato del dettaglio ordine. La posizione arriva sempre dal server (stato registrato):
// qui si anima soltanto il passaggio dalla posizione precedente a quella nuova, quando la pagina si aggiorna.

export function journeyPosition(root = document) {
    const journey = root.querySelector('[data-journey]');
    const position = journey ? Number(journey.dataset.journeyPosition) : NaN;
    return Number.isFinite(position) ? position : null;
}

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

export function animateJourney(previous, root = document) {
    const journey = root.querySelector('[data-journey]');
    const target = journeyPosition(root);
    if (!journey || previous === null || target === null || previous === target || reducedMotion() || document.hidden) return false;

    const duration = Math.min(2.6, 1 + Math.abs(target - previous) * 0.35);
    const vehicle = journey.querySelector('.journey-vehicle');
    const stops = [...journey.querySelectorAll('.journey-stop')];
    const forward = target > previous;

    journey.classList.add('is-preparing');
    journey.style.setProperty('--journey-at', String(previous));
    journey.style.setProperty('--journey-duration', `${duration}s`);
    // Le tappe raggiunte durante il tragitto si accendono quando il camion ci passa sopra.
    for (const [index, stop] of stops.entries()) {
        stop.style.transitionDelay = '';
        if (forward && index > previous && index <= target) {
            stop.classList.add('is-pending');
            stop.style.transitionDelay = `${((index - previous) / (target - previous)) * duration * 0.92}s`;
        }
    }
    void journey.offsetWidth;
    journey.classList.remove('is-preparing');
    journey.classList.add('is-driving');
    requestAnimationFrame(() => {
        journey.style.setProperty('--journey-at', String(target));
        for (const stop of stops) stop.classList.remove('is-pending');
    });

    let finished = false;
    const finish = () => {
        if (finished) return;
        finished = true;
        journey.classList.remove('is-driving');
        for (const stop of stops) stop.style.transitionDelay = '';
        journey.classList.add('has-arrived');
        window.setTimeout(() => journey.classList.remove('has-arrived'), 1000);
    };
    vehicle?.addEventListener('transitionend', event => { if (event.propertyName === 'translate') finish(); });
    window.setTimeout(finish, duration * 1000 + 250);
    return true;
}
