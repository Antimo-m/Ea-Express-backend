import { attachBookingRules } from './booking-rules';
for (const form of document.querySelectorAll('[data-booking-rules]')) {
    attachBookingRules(form, JSON.parse(form.dataset.bookingRules), null, async () => {
        const response = await fetch('/booking-rules',{headers:{Accept:'application/json'}});
        if (!response.ok) throw new Error('Ora server non disponibile');
        return response.json();
    });
}
