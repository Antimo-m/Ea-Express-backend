import { createOperationalMap } from './operational-map';
import { staffRequest } from './live-workspace';
import { observeTracking, trackingMessage } from './tracking-state';

for (const panel of document.querySelectorAll('[data-tracking-panel]')) {
  const orderId = panel.dataset.trackingOrder;
  let latest = null;
  const map = createOperationalMap(panel.querySelector('[data-tracking-map]'));
  const placeholder = panel.querySelector('[data-tracking-placeholder]');
  const message = panel.querySelector('[data-tracking-message]');
  const label = panel.querySelector('[data-tracking-label]');
  const updated = panel.querySelector('[data-tracking-updated]');
  const errorBox = panel.querySelector('[data-tracking-error]');
  const form = panel.querySelector('[data-map-points]');
  function render(data) {
    latest = data;
    if (!data) { message.textContent = 'Tracking non disponibile'; map.update([], {tiles:'',attribution:''}); placeholder.hidden = false; return; }
    const orders = orderId ? [data] : data.data;
    const active = orders.filter(item => item.location);
    const freshPositions = orders.filter(item => item.state === 'live' && item.location && Date.now() - Date.parse(item.location.recorded_at) < 90000 && Date.now() - Date.parse(item.location.received_at) < 90000);
    map.update(freshPositions.map(item => ({ location: item.location, rider_name: item.rider?.name || 'Rider', reference: item.reference, order_url: `/orders/${item.order_id}` })), orders[0]?.map || {tiles:'',attribution:''});
    placeholder.hidden = freshPositions.length > 0;
    if (orderId) {
      message.textContent = trackingMessage(data);
      label.textContent = data.status_label;
      const fresh = data.location && Date.now() - Date.parse(data.location.recorded_at) < 90000;
      panel.dataset.trackingState = fresh ? 'live' : data.state;
      panel.querySelector('[data-tracking-rider]').textContent = data.rider?.name || 'Rider da assegnare';
      updated.textContent = data.location ? `GPS: ${data.location.latitude.toFixed(5)}, ${data.location.longitude.toFixed(5)} · ${new Date(data.location.recorded_at).toLocaleTimeString('it-IT')}` : trackingMessage(data);
      panel.querySelector('[data-pickup-point]').textContent = data.pickup.point ? 'Punto confermato' : 'Punto sulla mappa da confermare';
      panel.querySelector('[data-delivery-point]').textContent = data.delivery.point ? 'Punto confermato' : 'Punto sulla mappa da confermare';
      const startButton = panel.querySelector('[data-gps-start]');
      if (startButton) startButton.disabled = !data.eligible;
    } else {
      message.textContent = active.length ? `${active.length} consegne con posizione GPS` : 'Nessun Rider sta condividendo la posizione';
      label.textContent = `${orders.length} sessioni operative`;
      updated.textContent = 'Aggiornamento automatico';
      const roster = panel.querySelector('[data-rider-roster]');
      roster.replaceChildren();
      for (const order of orders) {
        const link = document.createElement('a'); link.href = `/orders/${order.order_id}`;
        const name = document.createElement('strong'); name.textContent = order.rider?.name || 'Rider';
        const state = document.createElement('span'); state.textContent = `${order.reference} · ${trackingMessage(order)}`;
        link.append(name, state); roster.append(link);
      }
    }
  }
  const cleanup = observeTracking({ request: staffRequest, path: panel.dataset.trackingUrl, orderId, minIntervalMs: orderId ? 0 : 2000, onData: render, onError(error) {
    errorBox.hidden = !error; errorBox.textContent = error?.message || '';
  } });
  const clock = setInterval(() => { if (latest) render(latest); }, 15000);
  window.addEventListener('pagehide', () => { cleanup(); clearInterval(clock); map.dispose(); }, { once: true });
  if (form) {
    form.querySelector('[data-use-rider-point]').addEventListener('click', () => {
      const feedback = form.querySelector('[data-point-feedback]');
      if (!latest?.location || Date.now() - Date.parse(latest.location.recorded_at) > 90000) { feedback.textContent = 'Serve una posizione GPS recente del Rider.'; return; }
      form.elements.latitude.value = latest.location.latitude; form.elements.longitude.value = latest.location.longitude;
      feedback.textContent = 'Verifica che il Rider si trovi all’indirizzo, poi conferma il punto.';
    });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const submit = form.querySelector('[type=submit]'), feedback = form.querySelector('[data-point-feedback]');
      if (submit.disabled) return;
      submit.disabled = true;
      try {
        const result = await staffRequest(form.action, { method: 'PATCH', data: Object.fromEntries(new FormData(form)) });
        form.elements.version.value = result.version;
        feedback.textContent = 'Punto salvato. Ricarica la pagina prima di altre modifiche all’ordine.';
        window.dispatchEvent(new CustomEvent('ea:rider-location', { detail: { order_id: Number(orderId) } }));
      } catch (error) { feedback.textContent = error.status === 409 ? 'L’ordine è cambiato. Ricarica la pagina prima di confermare.' : error.message; }
      finally { submit.disabled = false; }
    });
  }
}
