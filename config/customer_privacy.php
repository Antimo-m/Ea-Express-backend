<?php

/*
 * Dati legali del titolare: restano vuoti finché il cliente non li fornisce.
 * Le pagine pubbliche nascondono i campi vuoti senza mostrare segnaposto; prima del lancio vanno
 * compilati PRIVACY_CONTROLLER_*, PRIVACY_CONTACT_EMAIL e, dopo la verifica GDPR, PRIVACY_LEGAL_REVIEWED=true.
 */
return [
    'version' => '2026-11-16',
    'controller_name' => env('PRIVACY_CONTROLLER_NAME'),
    'controller_address' => env('PRIVACY_CONTROLLER_ADDRESS'),
    'controller_vat' => env('PRIVACY_CONTROLLER_VAT'),
    'contact_email' => env('PRIVACY_CONTACT_EMAIL'),
    'privacy_url' => env('PRIVACY_POLICY_URL'),
    'reviewed' => (bool) env('PRIVACY_LEGAL_REVIEWED', false),
    'remember_minutes' => (int) env('CUSTOMER_REMEMBER_MINUTES', 43200),
];
