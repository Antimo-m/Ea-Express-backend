import { attachBookingRules } from './booking-rules';
for (const form of document.querySelectorAll('[data-booking-rules]')) {
    if (form.dataset.contentCategories) {
        const categories = JSON.parse(form.dataset.contentCategories);
        const content = form.elements.content_description;
        const updateContent = () => {
            form.elements.category.value = Object.entries(categories).find(([key, label]) => key !== 'custom' && label === content.value)?.[0] || 'custom';
        };
        content.addEventListener('input', updateContent);
        content.addEventListener('change', updateContent);
        updateContent();
    }
    attachBookingRules(form, JSON.parse(form.dataset.bookingRules), null, async () => {
        const response = await fetch('/booking-rules',{headers:{Accept:'application/json', 'X-Requested-With':'XMLHttpRequest'}});
        if (!response.ok) throw new Error('Ora server non disponibile');
        return response.json();
    });
}
