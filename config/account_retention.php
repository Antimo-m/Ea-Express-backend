<?php

/*
 * Account disabilitati: dopo «deactivated_days» giorni entrano nella revisione di conservazione.
 * In questa fase il processo produce soltanto un report (dry-run): nessuna eliminazione o
 * anonimizzazione viene eseguita finché regola definitiva e vincoli di conservazione non sono approvati.
 */
return [
    'deactivated_days' => (int) env('ACCOUNT_DEACTIVATED_REVIEW_DAYS', 90),
];
