<?php

return [
    'required' => 'Il campo :attribute è obbligatorio.',
    'string' => 'Il campo :attribute deve essere un testo.',
    'email' => 'Inserisci un indirizzo email valido.',
    'lowercase' => 'Il campo :attribute deve contenere solo lettere minuscole.',
    'unique' => 'Questo valore per :attribute è già utilizzato.',
    'confirmed' => 'La conferma del campo :attribute non corrisponde.',
    'current_password' => 'La password attuale non è corretta.',
    'min' => ['string' => 'Il campo :attribute deve contenere almeno :min caratteri.'],
    'max' => ['string' => 'Il campo :attribute non può superare :max caratteri.'],
    'attributes' => [
        'name' => 'nome e cognome',
        'email' => 'email',
        'password' => 'password',
        'password_confirmation' => 'conferma password',
        'current_password' => 'password attuale',
        'token' => 'codice di recupero',
    ],
];
