import { attachFormPopovers } from './form-popovers';
attachFormPopovers();
import './package-fields';
import './live-workspace';
import Offcanvas from 'bootstrap/js/dist/offcanvas';
import 'bootstrap/js/dist/dropdown';

for (const button of document.querySelectorAll('[data-password-toggle]')) {
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
            const response = await fetch(`${group.dataset.historyUrl}?page=${page}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
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

import { createNotificationMonitor } from './notification-monitor';
if (document.body.dataset.notificationsUrl) {
    const button = document.querySelector('[data-sound-toggle]');
    const monitor = createNotificationMonitor({
        scope: document.body.dataset.notificationScope,
        load: async () => {
            const response = await fetch(document.body.dataset.notificationsUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error();
            return response.json();
        },
        onChange({ enabled, unread }) {
            button.setAttribute('aria-pressed', String(enabled));
            button.setAttribute('aria-label', enabled ? 'Disattiva suoni notifiche' : 'Attiva suoni notifiche');
            button.querySelector('span').textContent = enabled ? 'Suoni attivi' : 'Attiva suoni';
            button.querySelector('i').className = enabled ? 'bi bi-volume-up' : 'bi bi-volume-mute';
            if (unread !== undefined) {
                const dot = document.querySelector('[data-notification-dot]');
                if (dot) { dot.hidden = unread === 0; dot.setAttribute('aria-label', `${unread} notifiche da leggere`); }
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
