// Percorso animato del dettaglio ordine. La posizione arriva sempre dal server (stato registrato):
// qui si anima soltanto il passaggio dalla posizione precedente a quella nuova, quando la pagina si aggiorna.

export function journeyPosition(root = document) {
    const journey = root.querySelector('[data-journey]');
    const position = journey ? Number(journey.dataset.journeyPosition) : NaN;
    return Number.isFinite(position) ? position : null;
}

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const confettiColors = ['#e6a23d', '#3fbf8f', '#f5f2ec', '#ffcc7a', '#6fa8dc'];
const celebratedKey = 'ea:delivered-celebrated';
const celebratedIds = () => { try { return JSON.parse(localStorage.getItem(celebratedKey) || '[]'); } catch { return []; } };

// Tracking pubblico: alla consegna, coriandoli dalla bandierina una volta sola per spedizione e per dispositivo.
export function celebrateJourney(root = document) {
    const journey = root.querySelector('[data-journey][data-journey-celebrate]');
    const burst = journey?.querySelector('.journey-confetti');
    const id = journey?.dataset.journeyCelebrate;
    if (!burst || journey.dataset.journeyState !== 'arrived' || reducedMotion() || document.hidden || celebratedIds().includes(id)) return false;
    journey.classList.add('is-celebrating');
    burst.replaceChildren(...Array.from({ length: 22 }, (_, index) => {
        const piece = document.createElement('span');
        const angle = -Math.PI + (index / 21) * Math.PI * 0.85;
        const reach = 46 + ((index * 37) % 5) * 12;
        piece.style.setProperty('--dx', `${Math.round(Math.cos(angle) * reach)}px`);
        piece.style.setProperty('--dy', `${Math.round(Math.sin(angle) * reach * 0.9) - 10}px`);
        piece.style.setProperty('--spin', `${((index * 53) % 360) + 180}deg`);
        piece.style.setProperty('--delay', `${(index % 4) * 40}ms`);
        piece.style.background = confettiColors[index % confettiColors.length];
        return piece;
    }));
    window.setTimeout(() => {
        burst.replaceChildren();
        journey.classList.remove('is-celebrating');
        try { localStorage.setItem(celebratedKey, JSON.stringify([...celebratedIds().filter(seen => seen !== id), id].slice(-200))); } catch { /* Senza memoria locale la festa si ripete. */ }
    }, 1900);
    return true;
}

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
        celebrateJourney(root);
    };
    vehicle?.addEventListener('transitionend', event => { if (event.propertyName === 'translate') finish(); });
    window.setTimeout(finish, duration * 1000 + 250);
    return true;
}
