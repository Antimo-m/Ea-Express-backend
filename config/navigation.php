<?php

return [
    'Operatività' => [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'grid-1x2'],
        ['label' => 'Agenda ritiri', 'route' => 'pickups.index', 'active' => 'pickups.*', 'icon' => 'calendar2-week'],
        ['label' => 'Ordini in entrata', 'route' => 'orders.incoming', 'icon' => 'inbox', 'admin' => true],
        ['label' => 'Spedizioni in corso', 'route' => 'orders.in-progress', 'icon' => 'truck'],
        ['label' => 'Storico ordini', 'route' => 'orders.history', 'icon' => 'clock-history'],
    ],
    'Rider e clienti' => [
        ['label' => 'Rider', 'route' => 'users.index', 'icon' => 'people', 'admin' => true],
        ['label' => 'Rider per zona', 'route' => 'rider-operations.index', 'icon' => 'people', 'admin' => true],
        ['label' => 'Messaggi', 'route' => 'messages.index', 'icon' => 'chat-dots'],
        ['label' => 'Clienti', 'route' => 'customers.index', 'icon' => 'shop', 'admin' => true],
        ['label' => 'Destinatari non affidabili', 'route' => 'recipient-incidents.index', 'icon' => 'person-exclamation', 'admin' => true],
    ],
    'Contabilità e analisi' => [
        ['label' => 'Bilancio', 'route' => 'balance.index', 'icon' => 'wallet2', 'admin' => true],
        ['label' => 'Sospesi', 'route' => 'pending.index', 'icon' => 'hourglass-split', 'admin' => true],
        ['label' => 'Resoconti', 'route' => 'reports.index', 'icon' => 'bar-chart', 'admin' => true],
    ],
    'Configurazione commerciale' => [
        ['label' => 'Listini', 'route' => 'rates.index', 'icon' => 'tags', 'admin' => true],
    ],
    'Account e sistema' => [
        ['label' => 'Il mio profilo', 'route' => 'profile.edit', 'icon' => 'person-circle'],
        ['label' => 'Impostazioni', 'route' => 'settings.index', 'icon' => 'sliders2', 'admin' => true],
    ],
];
