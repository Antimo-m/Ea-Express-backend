const parts = instant => Object.fromEntries(new Intl.DateTimeFormat('en-CA', {timeZone:'Europe/Rome', year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date(instant)).map(part => [part.type,part.value]));
export function bookingLimits(serverTime, data, original = null) {
    const current = parts(serverTime);
    const minimum = parts(Math.ceil(serverTime / 60000) * 60000);
    const today = `${current.year}-${current.month}-${current.day}`;
    const minimumDate = `${minimum.year}-${minimum.month}-${minimum.day}`;
    const unchanged = original && ['pickup_date','pickup_from','pickup_to'].every(key => data[key] === original[key]);
    const minTime = !unchanged && data.pickup_date === minimumDate ? `${minimum.hour}:${minimum.minute}` : '';
    const unavailable = !unchanged && data.pickup_date && data.pickup_date < minimumDate;
    return {date: unchanged ? original.pickup_date : today, time:minTime, unavailable, unchanged};
}
export function attachBookingRules(form, payload, original = null, reload) {
    if (!form) return () => {};
    let clock = {value:Date.parse(payload.server_now), captured:performance.now()};
    let stopped = false;
    const date = form.elements.namedItem('pickup_date');
    const from = form.elements.namedItem('pickup_from');
    const to = form.elements.namedItem('pickup_to');
    const hint = document.createElement('small'); hint.className = 'booking-hint'; hint.setAttribute('role','status');
    from.closest('.field').append(hint);
    const update = () => {
        const data = {pickup_date:date.value,pickup_from:from.value,pickup_to:to.value};
        const limits = bookingLimits(clock.value + performance.now() - clock.captured, data, original);
        date.min = limits.date; from.min = limits.time;
        const end = from.value ? Number(from.value.slice(0,2)) * 60 + Number(from.value.slice(3)) + 1 : 0;
        to.min = end < 1440 ? `${String(Math.floor(end / 60)).padStart(2,'0')}:${String(end % 60).padStart(2,'0')}` : '';
        from.setCustomValidity(limits.unavailable || (from.value && limits.time && from.value < limits.time) ? 'Questo orario è trascorso. Scegli un nuovo orario di ritiro.' : '');
        to.setCustomValidity(from.value && to.value && to.value <= from.value ? 'La fine del ritiro deve seguire l’orario iniziale.' : '');
        hint.textContent = limits.unavailable ? 'Per oggi non restano orari disponibili.' : limits.time ? `Oggi dalle ${limits.time} · ora italiana` : 'Orari nell’ora italiana';
    };
    const sync = async () => {
        update();
        if (reload) {
            try {const next = await reload(); if (!stopped) {clock = {value:Date.parse(next.server_now),captured:performance.now()}; update();}} catch { /* Backend validation remains authoritative when offline. */ }
        }
    };
    const visibility = () => {if (!document.hidden) sync();};
    const submit = event => {update(); if (!form.reportValidity()) {event.preventDefault(); event.stopImmediatePropagation();}};
    form.addEventListener('input',update); form.addEventListener('change',update); form.addEventListener('submit',submit,true);
    window.addEventListener('focus',sync); document.addEventListener('visibilitychange',visibility);
    const interval = setInterval(update,10000); update();
    return () => {stopped=true; clearInterval(interval); hint.remove(); form.removeEventListener('input',update); form.removeEventListener('change',update); form.removeEventListener('submit',submit,true); window.removeEventListener('focus',sync); document.removeEventListener('visibilitychange',visibility);};
}
