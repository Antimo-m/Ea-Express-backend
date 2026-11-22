/**
 * Gli aggiornamenti in tempo reale sostituiscono intere regioni della pagina con l'HTML appena ricevuto. Se la regione
 * mostra uno stato vuoto illustrato e il nuovo contenuto è lo stesso stato vuoto, sostituirla (o anche solo staccarne e
 * riattaccarne la scena) farebbe ripartire l'animazione da capo, come un refresh. In quel caso la regione resta intatta.
 *
 * @param {Element} current regione attualmente nella pagina
 * @param {Element} next stessa regione nel contenuto appena ricevuto
 * @returns {boolean} true quando entrambe mostrano lo stesso stato vuoto e lo stesso testo
 */
export function isUnchangedEmptyState(current, next) {
    const scene = current.querySelector('.empty-scene');
    const nextScene = next.querySelector('.empty-scene');
    if (!scene || !nextScene) return false;
    const text = node => node.textContent.replace(/\s+/g, ' ').trim();
    return text(scene) === text(nextScene) && text(current) === text(next);
}
