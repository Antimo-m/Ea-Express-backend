<?php

return [
    /*
     * Beta pubblica senza attività commerciale: avvisi, tariffe indicative e accettazione delle condizioni.
     * Impostare BETA_MODE=false al lancio ufficiale.
     */
    'enabled' => (bool) env('BETA_MODE', true),
    'terms_version' => '2026-11-12',
];
