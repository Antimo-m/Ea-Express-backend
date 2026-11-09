import { attachShippingQuote } from './shipping-quote';
import './form-validation';
import './form-requirements';
import './tooltips';
import '../css/functional-revision.css';
import '../css/rider-day.css';
import '../css/staff-auth-revision.css';
import '../css/order-workspace-revision.css';
import './order-history';
import '../css/pickup-agenda.css';
import './pickup-agenda';
import './order-actions';
import './operational-orders';
import './filter-toolbar';
import './report-charts';
import './data-tables';
import './confirmations';
import './booking-form';
import './rates';
import { attachFormPopovers } from './form-popovers';
attachFormPopovers();
import './package-fields';
import './financial-forms';
import './workspace-forms';
import './live-workspace';
import Offcanvas from 'bootstrap/js/dist/offcanvas';
import 'bootstrap/js/dist/dropdown';

const passwordToggles = new WeakSet();
function bindPasswordToggles() {
for (const button of document.querySelectorAll('[data-password-toggle]')) {
    if (passwordToggles.has(button)) continue;
    passwordToggles.add(button);
    button.hidden = false;
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
        button.setAttribute('aria-label', show ? 'Nascondi password' : 'Mostra password');
        button.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
}
}
bindPasswordToggles();
document.addEventListener('ea:workspace-rendered', bindPasswordToggles);

const desktopViewport = window.matchMedia('(min-width: 992px)');
desktopViewport.addEventListener('change', ({ matches }) => {
    if (matches) {
        const navigation = document.getElementById('app-navigation');
        if (navigation) Offcanvas.getInstance(navigation)?.hide();
    }
});

for (const radio of document.querySelectorAll('[name="sender_type"]')) {
    radio.addEventListener('change', () => {
        const privateSender = radio.value === 'private';
        for (const group of document.querySelectorAll('[data-business-fields]')) {
            group.hidden = privateSender;
            for (const input of group.querySelectorAll('input')) input.disabled = privateSender;
        }
        const label = document.querySelector('label[for="store_name"]');
        if (label) label.textContent = privateSender ? 'Nome e cognome del mittente' : 'Nome attività';
    });
}

function bindNotificationGroups() {
for (const group of document.querySelectorAll('[data-notification-group]')) {
    if (group.dataset.bound) continue;
    group.dataset.bound = 'true';
    const content = group.querySelector('[data-notification-history]');
    let loaded = false;
    async function loadPage(page = 1) {
        content.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(`${group.dataset.historyUrl}?page=${page}`, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error();
            const result = await response.json();
            const fragment = document.createDocumentFragment();
            for (const item of result.data) {
                const row = document.createElement('article'); row.className = `notification-event ${item.read_at ? '' : 'unread'}`;
                const info = document.createElement('div');
                const title = document.createElement('p'); title.textContent = `${item.read_at ? '' : 'Nuovo · '}${item.title}`;
                const time = document.createElement('time'); time.dateTime = item.created_at; time.textContent = new Intl.DateTimeFormat('it-IT', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Rome' }).format(new Date(item.created_at));
                info.append(title, time);
                const form = document.createElement('form'); form.method = 'post'; form.action = `${group.dataset.readBase}/${encodeURIComponent(item.id)}`;
                for (const [name, value] of Object.entries({ _method: 'patch', _token: document.querySelector('meta[name="csrf-token"]').content })) { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.append(input); }
                const button = document.createElement('button'); button.className = 'btn btn-outline-primary btn-sm'; button.textContent = item.is_message ? 'Apri messaggio' : 'Apri ordine'; form.append(button); row.append(info, form); fragment.append(row);
            }
            if (result.meta.current_page < result.meta.last_page) { const more = document.createElement('button'); more.className = 'btn btn-outline-secondary btn-sm my-2'; more.textContent = 'Aggiornamenti precedenti'; more.addEventListener('click', () => { more.remove(); loadPage(page + 1); }); fragment.append(more); }
            if (page === 1) content.replaceChildren();
            content.append(fragment); loaded = true;
        } catch {
            const retry = document.createElement('button'); retry.className = 'btn btn-outline-secondary btn-sm my-3'; retry.textContent = 'Aggiornamenti non disponibili. Riprova'; retry.addEventListener('click', () => { retry.remove(); loadPage(page); }); if (page === 1) content.replaceChildren(); content.append(retry);
        } finally { content.removeAttribute('aria-busy'); }
    }
    group.addEventListener('toggle', () => { if (group.open && !loaded) loadPage(); });
}

}
bindNotificationGroups();
window.addEventListener('ea:notification-groups-rendered', bindNotificationGroups);
document.addEventListener('ea:workspace-rendered', bindNotificationGroups);

import { createNotificationMonitor } from './notification-monitor';
if (document.body.dataset.notificationsUrl) {
    const button = document.querySelector('[data-sound-toggle]');
    const monitor = createNotificationMonitor({
        scope: document.body.dataset.notificationScope,
        load: async () => {
            const response = await fetch(document.body.dataset.notificationsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error();
            return response.json();
        },
        onChange({ enabled, unread, available }) {
            if (available) window.dispatchEvent(new Event('ea:workspace-polled'));
            button.setAttribute('aria-pressed', String(enabled));
            button.setAttribute('aria-label', enabled ? 'Disattiva suoni notifiche' : 'Attiva suoni notifiche');
            button.querySelector('span').textContent = enabled ? 'Suoni attivi' : 'Attiva suoni';
            button.querySelector('i').className = enabled ? 'bi bi-volume-up' : 'bi bi-volume-mute';
            if (unread !== undefined) {
                const dot = document.querySelector('[data-notification-dot]');
                if (dot) { dot.hidden = unread === 0; dot.textContent = unread > 99 ? '99+' : String(unread); dot.setAttribute('aria-label', `${unread} notifiche da leggere`); }
                const count = document.querySelector('[data-notification-count]');
                if (count) count.textContent = unread ? `${unread} aggiornamenti da leggere.` : 'Nessun nuovo aggiornamento.';
            }
        },
    });
    button.setAttribute('aria-pressed', String(monitor.enabled));
    button.addEventListener('click', () => monitor.toggle());
    window.addEventListener('ea:workspace-updated', () => monitor.refresh());
    window.addEventListener('pagehide', event => { if (!event.persisted) monitor.dispose(); });
}

for (const conversation of document.querySelectorAll('[data-conversation-list]')) { conversation.scrollTop = conversation.scrollHeight; }

document.addEventListener('click', event => {
    const button = event.target.closest('[data-select-labels]');
    if (!button) return;
    const boxes = [...button.closest('form').querySelectorAll('input[name="ids[]"]')];
    const checked = !boxes.every(box => box.checked);
    boxes.forEach(box => { box.checked = checked; });
});
const quoteBox = document.querySelector('[data-shipping-quote]');
if (quoteBox) {
    const form = quoteBox.closest('form');
    attachShippingQuote(quoteBox);
    const updateService = () => {for (const name of ['delivery_province','delivery_region']) form.elements[name].required = form.elements.shipping_type.value === 'external';};
    form.elements.shipping_type.addEventListener('change', updateService); updateService();
    const type = form.elements.package_type; const description = form.elements.package_description;
    const updatePackage = () => {
        const custom = type.value === 'other';
        description.required = custom;
        const group = form.querySelector('[data-package-description]');
        if (group) { group.hidden = !custom; description.disabled = !custom; }
    }; type.addEventListener('change', updatePackage); updatePackage();
}

document.addEventListener('click', event => {
    const button = event.target.closest('[data-print-all-labels]');
    if (!button) return;
    const form = button.closest('form');
    form.querySelectorAll('input[name="ids[]"]').forEach(box => { box.checked = true; });
    form.requestSubmit();
});

const pendingPublicForms = new WeakSet();
document.addEventListener('submit', event => {
    const form = event.target;
    if (!form.matches('[data-prevent-repeat]') || event.defaultPrevented) return;
    if (pendingPublicForms.has(form)) { event.preventDefault(); return; }
    pendingPublicForms.add(form);
    form.setAttribute('aria-busy', 'true');
    queueMicrotask(() => form.querySelectorAll('button[type="submit"], button:not([type])').forEach(button => { button.disabled = true; }));
});
window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    document.querySelectorAll('[data-prevent-repeat]').forEach(form => {
        pendingPublicForms.delete(form); form.removeAttribute('aria-busy');
        form.querySelectorAll('button').forEach(button => { button.disabled = false; });
    });
});

const contentChoice = document.querySelector('select[name="category"]');
if (contentChoice) {
    const group = document.querySelector('[data-custom-content]');
    const input = group?.querySelector('input');
    const updateContent = () => {
        if (!group || !input) return;
        const custom = contentChoice.value === 'custom';
        group.hidden = !custom; input.required = custom; input.disabled = !custom;
    };
    contentChoice.addEventListener('change', updateContent); updateContent();
}

import './theme';


for (const form of document.querySelectorAll('[data-auth-form]')) {
    form.addEventListener('submit', event => {
        if (form.getAttribute('aria-busy') === 'true') { event.preventDefault(); return; }
        form.setAttribute('aria-busy', 'true');
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true; button.setAttribute('aria-busy', 'true'); button.textContent = 'Accesso in corso…';
    });
}
window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    for (const form of document.querySelectorAll('[data-auth-form][aria-busy="true"]')) {
        form.removeAttribute('aria-busy');
        const button = form.querySelector('button[type="submit"]');
        button.disabled = false; button.removeAttribute('aria-busy'); button.textContent = 'Accedi';
    }
});

import './rider-operations';



import './toast-feedback';

const accountChoice = document.querySelector('.staff-booking-form [name="customer_id"]');
accountChoice?.addEventListener('change', () => {
    const identity = JSON.parse(accountChoice.selectedOptions[0]?.dataset.identity || 'null');
    if (!identity) return;
    const form = accountChoice.form;
    const radio = form.querySelector(`[name="sender_type"][value="${identity.sender_type}"]`);
    if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
    for (const [name, value] of Object.entries({ store_name: identity.name, contact_email: identity.email, business_type: identity.business_type, business_description: identity.business_description })) {
        form.elements[name].value = value || '';
        form.elements[name].dispatchEvent(new Event('input', { bubbles: true }));
        form.elements[name].dispatchEvent(new Event('change', { bubbles: true }));
    }
});

import './finance-workspace';
import './directories';
import './not-found';
