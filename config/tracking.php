<?php

return [
    'history_days' => 30,
    'history_min_seconds' => 60,
    'history_heartbeat_seconds' => 120,
    'history_distance_metres' => 50,
    'cache_store' => env('TRACKING_CACHE_STORE', env('CACHE_STORE', 'database')),
    'tiles_url' => env('MAP_TILES_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'tiles_attribution' => env('MAP_TILES_ATTRIBUTION', '© OpenStreetMap contributors'),
];
