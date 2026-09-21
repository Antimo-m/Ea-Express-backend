<?php

return [
    'Operatività' => [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'grid-1x2'],
        ['label' => 'Ritiri raggruppati', 'route' => 'pickups.index', 'icon' => 'calendar2-week'],
        ['label' => 'Listino', 'route' => 'rates.index', 'icon' => 'tags'],
        ['label' => 'Ordini in entrata', 'route' => 'orders.incoming', 'icon' => 'inbox'],
        ['label' => 'Spedizioni in corso', 'route' => 'orders.in-progress', 'icon' => 'truck'],
        ['label' => 'Tracking', 'route' => 'tracking.index', 'icon' => 'geo-alt'],
        ['label' => 'Messaggi', 'route' => 'messages.index', 'icon' => 'chat-dots'],
    ],
    'Analisi e contabilità' => [
        ['label' => 'Storico ordini', 'route' => 'orders.history', 'icon' => 'clock-history'],
        ['label' => 'Bilancio', 'route' => 'balance.index', 'icon' => 'wallet2'],
        ['label' => 'Sospesi', 'route' => 'pending.index', 'icon' => 'hourglass-split', 'admin' => true],
        ['label' => 'Statistiche clienti', 'route' => 'stores.index', 'icon' => 'shop'],
        ['label' => 'Resoconti', 'route' => 'reports.index', 'icon' => 'bar-chart'],
    ],
    'Account' => [
        ['label' => 'Il mio profilo', 'route' => 'profile.edit', 'icon' => 'person-circle'],
        ['label' => 'Impostazioni', 'route' => 'settings.index', 'icon' => 'sliders2'],
    ],
];
