export function trackingMessage(data, now = Date.now()) {
  if (!data) return 'Caricamento tracking…';
  const age = data.location ? Math.max(0, Math.floor((now - Date.parse(data.location.recorded_at)) / 1000)) : null;
  if (data.location && age >= 90) return `Ultima posizione ricevuta ${Math.max(1, Math.floor(age / 60))} minuti fa`;
  return ({
    completed: 'Tracking terminato · ' + data.status_label,
    unassigned: 'Rider non ancora assegnato',
    waiting: 'Il Rider non ha ancora iniziato la consegna.',
    carrier: 'Spedizione affidata al vettore. Tracking GPS del Rider terminato.',
    ready: 'Il Rider non ha ancora attivato la condivisione GPS.',
    locating: 'In attesa della posizione del Rider. Il dispositivo potrebbe essere offline.',
    stale: 'Connessione Rider temporaneamente assente.',
    live: 'Posizione aggiornata · ' + (age < 10 ? 'adesso' : `${age} secondi fa`),
  })[data.state] || 'Tracking non disponibile';
}

export function observeTracking({ request, path, orderId, onData, onError, minIntervalMs = 0 }) {
  let stopped = false, busy = false, repeat = false, closed = false, timer, lastRefresh = 0;
  async function refresh() {
    if (stopped || document.hidden || closed) return;
    if (busy) { repeat = true; return; }
    const remaining = minIntervalMs - (Date.now() - lastRefresh);
    if (remaining > 0) { clearTimeout(timer); timer = setTimeout(refresh, remaining); return; }
    busy = true;
    lastRefresh = Date.now();
    try {
      const data = await request(path);
      if (stopped) return;
      closed = data.state === 'completed';
      onData(data);
      onError(null);
    } catch (error) {
      if (!stopped) {
        if ([401, 403, 404, 419].includes(error.status)) { closed = true; onData(null); }
        onError(error);
      }
    } finally {
      busy = false;
      clearTimeout(timer);
      if (!stopped && !closed) {
        if (repeat) { repeat = false; timer = setTimeout(refresh, 500); }
        else timer = setTimeout(refresh, window.eaRealtimeConnected ? 60000 : 30000);
      }
    }
  }
  const change = event => {
    if (!orderId || !event.detail?.order_id || Number(event.detail.order_id) === Number(orderId)) refresh();
  };
  const visible = () => { if (!document.hidden) refresh(); };
  window.addEventListener('ea:rider-location', change);
  window.addEventListener('ea:workspace-updated', change);
  window.addEventListener('online', visible);
  document.addEventListener('visibilitychange', visible);
  refresh();
  return () => {
    stopped = true; clearTimeout(timer);
    window.removeEventListener('ea:rider-location', change);
    window.removeEventListener('ea:workspace-updated', change);
    window.removeEventListener('online', visible);
    document.removeEventListener('visibilitychange', visible);
  };
}

export function shouldSendPosition(previous, next, now = Date.now()) {
  if (!next || next.accuracy > 500 || now - next.timestamp > 120000) return false;
  if (!previous) return true;
  const elapsed = now - previous.sentAt;
  const latitude = (next.latitude + previous.latitude) * Math.PI / 360;
  const meters = Math.hypot((next.latitude - previous.latitude) * 111320, (next.longitude - previous.longitude) * 111320 * Math.cos(latitude));
  return elapsed >= 10000 && (meters >= 15 || elapsed >= 30000);
}
