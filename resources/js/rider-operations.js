import { staffRequest } from './live-workspace';
import { observeTracking } from './tracking-state';
import { createOperationalMap } from './operational-map';

for (const panel of document.querySelectorAll('[data-rider-operations]')) {
  const map = createOperationalMap(panel.querySelector('[data-operational-map]'));
  let latest = JSON.parse(panel.querySelector('[data-zone-initial]').textContent);
  function text(tag, value, className) {
    const node = document.createElement(tag); node.textContent = value; if (className) node.className = className; return node;
  }
  function render(day) {
    if (!day) { map.update([], latest?.map || {tiles:'',attribution:''}); latest = null; panel.querySelector('[data-zone-grid]').replaceChildren(text('p', 'Sessione scaduta. Ricarica la pagina.')); return; }
    latest = day;
    for (const [key,value] of Object.entries(day.summary)) { const node = panel.querySelector(`[data-kpi="${key}"]`); if (node) node.textContent = value; }
    const unassigned = panel.querySelector('[data-unassigned-summary]'); unassigned.hidden = !day.summary.unassigned;
    panel.querySelector('[data-unassigned-count]').textContent = `${day.summary.unassigned} ordini`;
    const unassignedZones = panel.querySelector('[data-unassigned-zones]'); unassignedZones.replaceChildren();
    for (const zone of day.unassigned) { const link = text('a', `${zone.name} · ${zone.count}`, 'btn btn-outline-secondary btn-sm'); link.href = zone.url; unassignedZones.append(link); }
    const riders = panel.querySelector('[data-rider-grid]'); riders.replaceChildren();
    const money = cents => new Intl.NumberFormat('it-IT',{style:'currency',currency:'EUR'}).format(cents / 100);
    for (const rider of day.riders) {
      const card = text('article', '', 'surface rider-operational-card');
      const stale = rider.last_gps && (Date.now() - Date.parse(rider.last_gps) >= 90000 || Date.now() - Date.parse(rider.last_recorded_gps) >= 90000);
      card.dataset.riderState = stale && rider.state === 'live' ? 'stale' : rider.state;
      const header = document.createElement('header'); header.append(text('h2',rider.name,'h5'),text('span',stale && rider.state === 'live' ? 'Tracking perso' : rider.label,'rider-state')); card.append(header);
      const zones = text('div','','rider-zone-chips');
      for (const zone of rider.zones) zones.append(text('span',`${zone.name} · ${zone.count}`,'status-badge'));
      card.append(zones,text('p',`${rider.delivered_count} / ${rider.total_count} consegnate · ${rider.active_count} in corso`));
      const progress = document.createElement('progress'); progress.max = Math.max(1,rider.total_count); progress.value = rider.delivered_count; progress.setAttribute('aria-label','Consegne completate'); card.append(progress);
      const metrics = document.createElement('dl');
      for (const [label,value] of [['Incassato netto',rider.cash_cents],['Tariffe previste',rider.expected_cents]]) { const group = document.createElement('div'); group.append(text('dt',label),text('dd',money(value))); metrics.append(group); }
      card.append(metrics); if (rider.missing_prices) card.append(text('small',`${rider.missing_prices} tariffe ancora da definire.`));
      const footer = document.createElement('footer');
      const minutes = rider.last_gps ? Math.max(0,Math.floor((Date.now()-Date.parse(rider.last_gps))/60000)) : null;
      footer.append(text('small',minutes === null ? 'GPS non disponibile' : `Ultimo GPS: ${minutes < 1 ? 'ora' : `${minutes} min fa`}`));
      const actions = text('div','','row-actions');
      const detail = text('a','','icon-button action-open'); detail.href = rider.url; detail.title = `Attività di ${rider.name}`; detail.setAttribute('aria-label',detail.title); detail.append(text('i','','bi bi-person'));
      const locate = text('button','','icon-button action-open'); locate.type = 'button'; locate.dataset.mapRider = rider.id; locate.title = `Posizione di ${rider.name}`; locate.setAttribute('aria-label',locate.title); locate.disabled = rider.state !== 'live' || stale; locate.append(text('i','','bi bi-geo-alt'));
      actions.append(detail,locate); footer.append(actions); card.append(footer); riders.append(card);
    }
    if (!day.riders.length) riders.append(text('p','Nessun Rider con attività registrata nella giornata.'));
    const grid = panel.querySelector('[data-zone-grid]'); grid.replaceChildren();
    for (const zone of day.zones) {
      const section = text('section', '', 'surface rider-zone');
      const header = document.createElement('header'); header.append(text('h2', zone.name), text('span', `${zone.riders.length} Rider`, 'count-badge')); section.append(header);
      for (const rider of zone.riders) {
        const stale = rider.last_gps && (Date.now() - Date.parse(rider.last_gps) >= 90000 || (rider.last_recorded_gps && Date.now() - Date.parse(rider.last_recorded_gps) >= 90000));
        const row = text('article', '', 'rider-zone-person'); row.dataset.riderState = stale && rider.state === 'live' ? 'stale' : rider.state;
        const info = document.createElement('div');
        info.append(text('strong', rider.name), text('span', stale && rider.state === 'live' ? 'Offline · GPS non aggiornato' : rider.label, 'rider-state'), text('small', `${rider.active_count} in corso · ${rider.delivered_count} consegnate`), text('small', rider.last_gps ? `Ultimo GPS: ${new Date(rider.last_gps).toLocaleTimeString('it-IT')}` : 'GPS non disponibile'));
        row.append(info);
        if (rider.url) {
          const button = document.createElement('a'); button.href = rider.url; button.className = 'icon-button action-open'; button.setAttribute('aria-label', `Attività di ${rider.name}`); button.title = `Attività di ${rider.name}`;
          const icon = text('i', '', 'bi bi-person'); icon.setAttribute('aria-hidden', 'true'); button.append(icon); row.append(button);
        }
        section.append(row);
      }
      grid.append(section);
    }
    if (!day.zones.length) grid.append(text('div', 'Nessuna consegna nella giornata selezionata.', 'surface empty-state'));
    const fresh = day.locations.filter(item => Date.now() - Date.parse(item.location.received_at) < 90000 && Date.now() - Date.parse(item.location.recorded_at) < 90000);
    map.update(fresh, day.map);
    const empty = panel.querySelector('[data-map-empty]'); if (empty) empty.hidden = fresh.length > 0;
    panel.querySelector('[data-zone-updated]').textContent = `Dati ricevuti: ${new Date(day.generated_at).toLocaleTimeString('it-IT')}`;
  }
  document.addEventListener('click', event => {
    const toggle = event.target.closest('[data-operations-view]');
    if (toggle) { const byRider = toggle.dataset.operationsView === 'rider'; panel.querySelector('[data-rider-grid]').hidden = !byRider; panel.querySelector('[data-zone-grid]').hidden = byRider; for (const button of document.querySelectorAll('[data-operations-view]')) { const selected = button === toggle; button.setAttribute('aria-pressed',String(selected)); button.classList.toggle('active',selected); } }
    const locate = event.target.closest('[data-map-rider]'); if (locate && !locate.disabled) map.focusRider(locate.dataset.mapRider);
  });
  render(latest);
  let cleanup = () => {}, clock;
  if (panel.dataset.today === 'true') {
    cleanup = observeTracking({ request: staffRequest, path: panel.dataset.feed, minIntervalMs: 2000, onData: render, onError(error) { const box = panel.querySelector('[data-zone-error]'); box.hidden = !error; box.textContent = error ? 'Aggiornamento non disponibile. I dati mostrati risalgono all’ultima risposta ricevuta.' : ''; } });
    clock = setInterval(() => { if (!document.hidden && latest) render(latest); }, 15000);
  }
  window.addEventListener('pagehide', () => { cleanup(); clearInterval(clock); map.dispose(); }, { once: true });
}

for (const container of document.querySelectorAll('[data-rider-history-map]')) {
  const data = JSON.parse(container.parentElement.querySelector('[data-rider-history-points]').textContent);
  const map = createOperationalMap(container); map.update(data.points,data.map);
  window.addEventListener('pagehide', () => map.dispose(), {once:true});
}
