import test from 'node:test';
import assert from 'node:assert/strict';
import { bookingLimits } from '../../resources/js/booking-rules.js';
const data = {pickup_date:'2026-09-20',pickup_from:'16:00',pickup_to:'17:00'};
test('same-day minimum follows Rome time and rounds forward to a selectable minute', () => {
    assert.equal(bookingLimits(Date.parse('2026-09-20T13:00:00Z'),data).time,'15:00');
    assert.equal(bookingLimits(Date.parse('2026-09-20T13:00:01Z'),data).time,'15:01');
});
test('future dates have no current-day minimum', () => {
    assert.equal(bookingLimits(Date.parse('2026-09-19T22:30:00Z'),{...data,pickup_date:'2026-09-21'}).time,'');
});
test('last partial minute of the day has no remaining selectable times', () => {
    assert.equal(bookingLimits(Date.parse('2026-09-20T21:59:01Z'),data).unavailable,true);
});
test('crossing midnight invalidates the previous date in Rome', () => {
    const limits=bookingLimits(Date.parse('2026-09-20T22:01:00Z'),data);
    assert.equal(limits.date,'2026-09-21');assert.equal(limits.unavailable,true);
});
test('historical unchanged schedules are preserved but changing either time revalidates them', () => {
    assert.equal(bookingLimits(Date.parse('2026-09-21T13:00:00Z'),data,data).unavailable,false);
    assert.equal(bookingLimits(Date.parse('2026-09-21T13:00:00Z'),{...data,pickup_to:'18:00'},data).unavailable,true);
});
test('the DST transition skips nonexistent local hours', () => {
    const limits=bookingLimits(Date.parse('2026-03-29T00:59:30Z'),{...data,pickup_date:'2026-03-29'});
    assert.equal(limits.time,'03:00');
});
