<?php

return [
    'paths' => ['api/v1/customer/*'], 'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'OPTIONS'],
    'allowed_origins' => array_filter(explode(',', env('CUSTOMER_ALLOWED_ORIGINS', 'http://localhost:5173'))),
    'allowed_origins_patterns' => [], 'allowed_headers' => ['Content-Type', 'Accept', 'X-CSRF-TOKEN', 'X-Requested-With'],
    'exposed_headers' => [], 'max_age' => 600, 'supports_credentials' => true,
];
