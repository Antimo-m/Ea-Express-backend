const TILE = 256;
function project(latitude, longitude, zoom) {
  const scale = TILE * 2 ** zoom;
  const sine = Math.sin(Math.max(-85.0511, Math.min(85.0511, latitude)) * Math.PI / 180);
  return { x: (longitude + 180) / 360 * scale, y: (0.5 - Math.log((1 + sine) / (1 - sine)) / (4 * Math.PI)) * scale };
}
export function createOperationalMap(container) {
  if (!container) return { update() {}, focusRider() {}, dispose() {} };
  let markers = [], configuration = {}, zoom = 11, center = null, userMoved = false, drag = null, disposed = false;
  const tiles = document.createElement('div'); tiles.className = 'map-tiles';
  const pins = document.createElement('div'); pins.className = 'map-pins';
  const controls = document.createElement('div'); controls.className = 'map-controls';
  for (const [label, text, action] of [['Ingrandisci mappa', '+', () => { zoom = Math.min(18, zoom + 1); userMoved = true; draw(); }], ['Riduci mappa', '−', () => { zoom = Math.max(2, zoom - 1); userMoved = true; draw(); }], ['Mostra tutti i Rider', '⌖', () => { userMoved = false; fit(); draw(); }]]) {
    const button = document.createElement('button'); button.type = 'button'; button.className = 'icon-button action-open'; button.textContent = text; button.setAttribute('aria-label', label); button.addEventListener('click', action); controls.append(button);
  }
  const attribution = document.createElement('a'); attribution.className = 'map-attribution'; attribution.href = 'https://www.openstreetmap.org/copyright'; attribution.target = '_blank'; attribution.rel = 'noopener noreferrer';
  const feedback = document.createElement('span'); feedback.className = 'map-feedback'; feedback.hidden = true; feedback.textContent = 'Sfondo cartografico non disponibile. Le posizioni GPS restano visibili.';
  container.append(tiles, pins, controls, attribution, feedback);
  function fit() {
    if (!markers.length) { center = null; return; }
    const lats = markers.map(item => item.location.latitude), lngs = markers.map(item => item.location.longitude);
    center = { latitude: (Math.min(...lats) + Math.max(...lats)) / 2, longitude: (Math.min(...lngs) + Math.max(...lngs)) / 2 };
    zoom = 15;
    while (zoom > 2) {
      const positions = markers.map(item => project(item.location.latitude, item.location.longitude, zoom));
      if (Math.max(...positions.map(p => p.x)) - Math.min(...positions.map(p => p.x)) < container.clientWidth - 100 && Math.max(...positions.map(p => p.y)) - Math.min(...positions.map(p => p.y)) < container.clientHeight - 200) break;
      zoom--;
    }
  }
  function draw() {
    if (disposed) return;
    tiles.replaceChildren(); pins.replaceChildren();
    container.hidden = !markers.length;
    if (!center || !markers.length) return;
    const origin = project(center.latitude, center.longitude, zoom), width = container.clientWidth, height = container.clientHeight;
    const left = origin.x - width / 2, top = origin.y - height / 2, count = 2 ** zoom;
    for (let x = Math.floor(left / TILE); x <= Math.floor((left + width) / TILE); x++) for (let y = Math.floor(top / TILE); y <= Math.floor((top + height) / TILE); y++) {
      if (y < 0 || y >= count) continue;
      const img = document.createElement('img'); img.alt = ''; img.draggable = false; img.width = TILE; img.height = TILE;
      img.style.left = `${x * TILE - left}px`; img.style.top = `${y * TILE - top}px`;
      img.addEventListener('error', () => { feedback.hidden = false; }, { once: true });
      img.src = configuration.tiles.replace('{z}', zoom).replace('{x}', ((x % count) + count) % count).replace('{y}', y); tiles.append(img);
    }
    for (const marker of markers) {
      const position = project(marker.location.latitude, marker.location.longitude, zoom);
      const pin = document.createElement('a'); pin.className = marker.kind === 'sample' ? 'map-history-point' : 'map-rider-pin'; pin.href = marker.order_url;
      pin.style.left = `${position.x - left}px`; pin.style.top = `${position.y - top}px`;
      const name = document.createElement('strong'); name.className = 'map-name'; name.textContent = marker.rider_name;
      const context = document.createElement('small'); context.className = 'map-context'; context.textContent = marker.reference;
      const time = document.createElement('small'); time.textContent = `GPS ${new Date(marker.location.recorded_at).toLocaleTimeString('it-IT')}`;
      const zone = document.createElement('small'); zone.className = 'map-context map-zone'; zone.textContent = marker.zone || '';
      if (marker.kind !== 'sample') { pin.append(name, context); if (marker.zone) pin.append(zone); pin.append(time); }
      const label = `${marker.rider_name} · ${marker.reference} · ${marker.zone || ''} · GPS ${new Date(marker.location.received_at || marker.location.recorded_at).toLocaleTimeString('it-IT')}`;
      pin.title = label; pin.setAttribute('aria-label', label); pins.append(pin);
    }
  }
  container.addEventListener('pointerdown', event => {
    if (!center || event.target.closest('a,button')) return;
    drag = { x: event.clientX, y: event.clientY, origin: project(center.latitude, center.longitude, zoom) }; container.setPointerCapture(event.pointerId);
  });
  container.addEventListener('pointermove', event => {
    if (!drag) return;
    const scale = TILE * 2 ** zoom, x = drag.origin.x - (event.clientX - drag.x), y = drag.origin.y - (event.clientY - drag.y);
    center = { longitude: x / scale * 360 - 180, latitude: Math.atan(Math.sinh(Math.PI * (1 - 2 * y / scale))) * 180 / Math.PI }; userMoved = true; draw();
  });
  container.addEventListener('pointerup', () => { drag = null; }); container.addEventListener('pointercancel', () => { drag = null; });
  const observer = new ResizeObserver(() => { if (!userMoved) fit(); draw(); }); observer.observe(container);
  return {
    update(items, config) { markers = items.filter(item => item.location && Number.isFinite(item.location.latitude) && Number.isFinite(item.location.longitude)); configuration = config; attribution.textContent = config.attribution; container.hidden = !markers.length; if (!userMoved || !center) fit(); draw(); },
    focusRider(riderId) { const point = markers.find(item => item.rider_id === Number(riderId)); if (!point) return; center = point.location; zoom = 15; userMoved = true; draw(); container.scrollIntoView({block:'center',behavior:'smooth'}); },
    dispose() { disposed = true; observer.disconnect(); },
  };
}
