import { staffRequest } from './live-workspace';
import { shouldSendPosition } from './tracking-state';

const root = document.querySelector('[data-rider-gps]');
if (root) {
  const feedback = root.querySelector('[data-gps-feedback]');
  const badge = root.querySelector('[data-gps-badge]');
  const stopButton = root.querySelector('[data-gps-stop]');
  let session = null, watcher = null, latest = null, previous = null, sending = false, starting = false, backoffUntil = 0, generation = 0;
  const storageKey = `ea:gps:${root.dataset.riderGps}`;
  const setState = active => {
    root.dataset.gpsState = active ? 'active' : 'disabled';
    badge.setAttribute('aria-label', active ? 'GPS attivo' : 'GPS disattivato');
  };
  const say = (message, active = false) => { feedback.textContent = message; badge.title = message; setState(active); };
  function clearWatch() {
    if (watcher !== null) navigator.geolocation?.clearWatch(watcher);
    watcher = null; latest = null; previous = null; setState(false);
  }
  function forget() {
    generation++; clearWatch(); session = null;
    try { sessionStorage.removeItem(storageKey); } catch { /* La sessione può restare in memoria. */ }
    stopButton.hidden = true;
  }
  async function stop() {
    const old = session;
    forget(); say('Condivisione GPS disattivata.');
    if (old) {
      try { await staffRequest(`/orders/${old.order_id}/location/session`, { method: 'DELETE', data: { session: old.session } }); }
      catch { say('GPS disattivato sul dispositivo. Il server non è raggiungibile: l’ultima posizione scadrà automaticamente.'); }
      window.dispatchEvent(new CustomEvent('ea:rider-location', { detail: { order_id: old.order_id } }));
    }
  }
  async function send() {
    if (!session || document.hidden || sending || Date.now() < backoffUntil || !navigator.onLine || !shouldSendPosition(previous, latest)) return;
    const point = latest, active = session, currentGeneration = generation;
    sending = true;
    try {
      await staffRequest(`/orders/${active.order_id}/location`, { method: 'PUT', data: { session: active.session, latitude: point.latitude, longitude: point.longitude, accuracy: point.accuracy, recorded_at: new Date(point.timestamp).toISOString() } });
      if (generation !== currentGeneration) return;
      previous = { ...point, sentAt: Date.now() }; backoffUntil = 0;
      say(`GPS condiviso · precisione ${Math.round(point.accuracy)} m · ${new Date().toLocaleTimeString('it-IT')}`, true);
      window.dispatchEvent(new CustomEvent('ea:rider-location', { detail: { order_id: active.order_id } }));
    } catch (error) {
      if (generation !== currentGeneration) return;
      if ([401, 403, 404, 409, 419].includes(error.status)) { forget(); say('Tracking terminato o assegnazione cambiata. Riapri la consegna per continuare.'); }
      else { backoffUntil = Date.now() + 30000; say('Invio GPS sospeso. Riprovo tra 30 secondi con la posizione più recente.'); }
    } finally { sending = false; }
  }
  function watch() {
    clearWatch();
    if (!session || document.hidden) return;
    if (!navigator.geolocation || !window.isSecureContext) { say('Il GPS richiede HTTPS e un browser con geolocalizzazione.'); return; }
    watcher = navigator.geolocation.watchPosition(position => {
      latest = { latitude: position.coords.latitude, longitude: position.coords.longitude, accuracy: position.coords.accuracy, timestamp: position.timestamp };
      if (latest.accuracy > 500) say('Segnale GPS debole. Attendo una posizione più precisa.');
      else send();
    }, error => {
      if (error.code === 1) { stop().then(() => say('Permesso GPS negato. Puoi abilitarlo nelle impostazioni del browser.')); }
      else say('Segnale GPS non disponibile. L’ultima posizione resta indicata con il suo orario.');
    }, { enableHighAccuracy: true, maximumAge: 10000, timeout: 20000 });
    say('In attesa della posizione GPS. Mantieni questa pagina visibile durante la consegna.');
  }
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-gps-start]');
    if (!button || starting) return;
    starting = true; button.disabled = true;
    try {
      if (!navigator.geolocation || !window.isSecureContext) { say('Il GPS richiede HTTPS e un browser con geolocalizzazione.'); return; }
      if (session) await stop();
      session = await staffRequest(button.dataset.gpsStart, { method: 'POST' });
      try { sessionStorage.setItem(storageKey, JSON.stringify(session)); } catch { /* Prosegue nella pagina corrente. */ }
      stopButton.hidden = false; watch();
    } catch (error) { say(error.message); }
    finally { button.disabled = false; starting = false; }
  });
  stopButton.addEventListener('click', stop);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { clearWatch(); if (session) say('GPS in pausa: torna alla pagina per riprendere.'); }
    else if (session) watch();
  });
  window.addEventListener('offline', () => { if (session) say('Sei offline. Invio dell’ultima posizione al ritorno della connessione.'); });
  window.addEventListener('online', send);
  window.addEventListener('pagehide', clearWatch);
  window.addEventListener('pageshow', event => { if (event.persisted && session) watch(); });
  window.addEventListener('ea:workspace-updated', async () => {
    if (!session) return;
    const active = session;
    try {
      const state = await staffRequest(`/orders/${active.order_id}/location`);
      if (session === active && !state.eligible) { forget(); say('Tracking terminato per questa spedizione.'); }
    } catch (error) { if (session === active && [401,403,404,419].includes(error.status)) forget(); }
  });
  setInterval(() => { if (previous && Date.now() - previous.sentAt >= 90000) setState(false); send(); }, 10000);
  try { session = JSON.parse(sessionStorage.getItem(storageKey)); } catch { session = null; }
  if (session?.session && session?.order_id) { stopButton.hidden = false; watch(); }
}
