<?php

return [
    'version' => '2026-10-28',
    'controller_name' => env('PRIVACY_CONTROLLER_NAME'),
    'controller_address' => env('PRIVACY_CONTROLLER_ADDRESS'),
    'contact_email' => env('PRIVACY_CONTACT_EMAIL'),
    'privacy_url' => env('PRIVACY_POLICY_URL'),
    'reviewed' => (bool) env('PRIVACY_LEGAL_REVIEWED', false),
    'remember_minutes' => (int) env('CUSTOMER_REMEMBER_MINUTES', 43200),
];
