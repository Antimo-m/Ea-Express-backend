import { validateForm } from './form-validation';

const minute = 60000;
const scheduleFields = ['pickup_date', 'pickup_from', 'pickup_to'];
const parts = instant => Object.fromEntries(new Intl.DateTimeFormat('en-CA', {timeZone:'Europe/Rome', year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date(instant)).map(part => [part.type,part.value]));
const dateOf = value => `${value.year}-${value.month}-${value.day}`;
const timeOf = value => `${value.hour}:${value.minute}`;
const timeMinutes = value => Number(value.slice(0, 2)) * 60 + Number(value.slice(3));
const formatMinutes = value => `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;

export function bookingLimits(serverTime, data, original = null, leadMinutes = 0) {
    const minimum = parts(Math.ceil((serverTime + leadMinutes * minute) / minute) * minute);
    const minimumDate = dateOf(minimum);
    const unchanged = leadMinutes === 0 && original && scheduleFields.every(key => data[key] === original[key]);
    const minTime = !unchanged && data.pickup_date === minimumDate ? `${minimum.hour}:${minimum.minute}` : '';
    const unavailable = !unchanged && Boolean(data.pickup_date) && (data.pickup_date < minimumDate || (data.pickup_date === minimumDate && minTime === '23:59'));
    return {date: unchanged ? original.pickup_date : minimumDate, time:minTime, unavailable, unchanged};
}

export function defaultPickupSchedule(serverTime, selectedDate = '', dateTouched = false, leadMinutes = 0) {
    let earliest = Math.ceil((serverTime + leadMinutes * minute) / minute) * minute;
    if (timeOf(parts(earliest)) === '23:59') earliest += minute;
    const minimum = parts(earliest);
    const date = dateTouched ? selectedDate : dateOf(minimum);
    const from = date === dateOf(minimum) ? timeOf(minimum) : '09:00';
    const to = formatMinutes(Math.min(1439, Math.max(12 * 60, timeMinutes(from) + 60)));
    return {pickup_date:date, pickup_from:from, pickup_to:to};
}
export function attachBookingRules(form, payload, original = null, reload) {
    if (!form) return () => {};
    let clock = {value:Date.parse(payload.server_now), captured:performance.now()};
    let stopped = false;
    const date = form.elements.namedItem('pickup_date');
    const from = form.elements.namedItem('pickup_from');
    const to = form.elements.namedItem('pickup_to');
    const leadMinutes = Number(payload.lead_minutes) || 0;
    let dateTouched = Boolean(original?.pickup_date || from.value);
    let fromTouched = Boolean(original?.pickup_from || from.value);
    let toTouched = Boolean(original?.pickup_to || from.value);
    const hint = document.createElement('small'); hint.className = 'booking-hint'; hint.setAttribute('role','status');
    from.closest('.field').append(hint);
    const update = () => {
        const serverTime = clock.value + performance.now() - clock.captured;
        const defaults = defaultPickupSchedule(serverTime, date.value, dateTouched, leadMinutes);
        if (!dateTouched) date.value = defaults.pickup_date;
        if (!fromTouched) from.value = defaults.pickup_from;
        const defaultEnd = defaults.pickup_to > from.value ? defaults.pickup_to : formatMinutes(Math.min(1439, timeMinutes(from.value) + 60));
        if (!toTouched) to.value = defaultEnd;
        const data = {pickup_date:date.value,pickup_from:from.value,pickup_to:to.value};
        const limits = bookingLimits(serverTime, data, original, leadMinutes);
        date.min = limits.date; from.min = limits.time;
        const end = from.value ? Number(from.value.slice(0,2)) * 60 + Number(from.value.slice(3)) + 1 : 0;
        to.min = end < 1440 ? `${String(Math.floor(end / 60)).padStart(2,'0')}:${String(end % 60).padStart(2,'0')}` : '';
        from.setCustomValidity(limits.unavailable || (from.value && limits.time && from.value < limits.time) ? 'Questo orario è trascorso. Scegli un nuovo orario di ritiro.' : '');
        to.setCustomValidity(from.value && to.value && to.value <= from.value ? 'La fine del ritiro deve seguire l’orario iniziale.' : '');
        hint.textContent = limits.unavailable ? 'Per oggi non restano orari disponibili.' : limits.time ? `Oggi dalle ${limits.time} · ora italiana` : 'Orari nell’ora italiana';
    };
    const change = event => {
        if (event.target === date) dateTouched = true;
        if (event.target === from) fromTouched = true;
        if (event.target === to) toTouched = true;
        update();
    };
    const sync = async () => {
        update();
        if (reload) {
            try {const next = await reload(); if (!stopped && Number.isFinite(Date.parse(next.server_now))) {clock = {value:Date.parse(next.server_now),captured:performance.now()}; update();}} catch { /* Backend validation remains authoritative when offline. */ }
        }
    };
    const visibility = () => {if (!document.hidden) sync();};
    const submit = event => {update(); if (!validateForm(form)) {event.preventDefault(); event.stopImmediatePropagation();}};
    form.addEventListener('input',change); form.addEventListener('change',change); form.addEventListener('submit',submit,true);
    window.addEventListener('focus',sync); document.addEventListener('visibilitychange',visibility);
    const interval = setInterval(update,1000); update(); sync();
    return () => {stopped=true; clearInterval(interval); hint.remove(); form.removeEventListener('input',change); form.removeEventListener('change',change); form.removeEventListener('submit',submit,true); window.removeEventListener('focus',sync); document.removeEventListener('visibilitychange',visibility);};
}
