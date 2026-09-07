<?php

return [
    'Operatività' => [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'grid-1x2'],
        ['label' => 'Ordini in entrata', 'route' => 'orders.incoming', 'icon' => 'inbox', 'upcoming' => true],
        ['label' => 'Spedizioni in corso', 'route' => 'orders.in-progress', 'icon' => 'truck', 'upcoming' => true],
        ['label' => 'Tracking', 'route' => 'tracking.index', 'icon' => 'geo-alt', 'upcoming' => true],
        ['label' => 'Messaggi', 'route' => 'messages.index', 'icon' => 'chat-dots', 'upcoming' => true],
    ],
    'La tua attività' => [
        ['label' => 'Storico ordini', 'route' => 'orders.history', 'icon' => 'clock-history', 'upcoming' => true],
        ['label' => 'Bilancio', 'route' => 'balance.index', 'icon' => 'wallet2', 'upcoming' => true],
        ['label' => 'Resoconti', 'route' => 'reports.index', 'icon' => 'bar-chart', 'upcoming' => true],
    ],
    'Account' => [
        ['label' => 'Il mio profilo', 'route' => 'profile.edit', 'icon' => 'person-circle'],
        ['label' => 'Impostazioni', 'route' => 'settings.index', 'icon' => 'sliders2', 'upcoming' => true],
    ],
];
