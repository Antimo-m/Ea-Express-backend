<?php

return [
    'Operatività' => [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'grid-1x2'],
        ['label' => 'Ritiri raggruppati', 'route' => 'pickups.index', 'icon' => 'calendar2-week'],
        ['label' => 'Ordini in entrata', 'route' => 'orders.incoming', 'icon' => 'inbox', 'admin' => true],
        ['label' => 'Spedizioni in corso', 'route' => 'orders.in-progress', 'icon' => 'truck'],
        ['label' => 'Tracking', 'route' => 'tracking.index', 'icon' => 'geo-alt'],
        ['label' => 'Messaggi', 'route' => 'messages.index', 'icon' => 'chat-dots'],
        ['label' => 'Storico ordini', 'route' => 'orders.history', 'icon' => 'clock-history'],
    ],
    'Amministrazione e contabilità' => [
        ['label' => 'Destinatari non affidabili', 'route' => 'recipient-incidents.index', 'icon' => 'person-exclamation', 'admin' => true],
        ['label' => 'Bilancio', 'route' => 'balance.index', 'icon' => 'wallet2', 'admin' => true],
        ['label' => 'Sospesi', 'route' => 'pending.index', 'icon' => 'hourglass-split', 'admin' => true],
        ['label' => 'Statistiche Clienti', 'route' => 'stores.index', 'icon' => 'shop', 'admin' => true],
        ['label' => 'Resoconti', 'route' => 'reports.index', 'icon' => 'bar-chart', 'admin' => true],
    ],
    'Configurazione commerciale' => [
        ['label' => 'Listini', 'route' => 'rates.index', 'icon' => 'tags', 'admin' => true],
    ],
    'Account e sistema' => [
        ['label' => 'Utenti', 'route' => 'users.index', 'icon' => 'people', 'admin' => true],
        ['label' => 'Il mio profilo', 'route' => 'profile.edit', 'icon' => 'person-circle'],
        ['label' => 'Impostazioni', 'route' => 'settings.index', 'icon' => 'sliders2', 'admin' => true],
    ],
];
