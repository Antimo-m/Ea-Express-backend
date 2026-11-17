<?php

namespace App\Support;

/**
 * Condizioni d'uso della beta. BOZZA da far validare a un professionista prima della pubblicazione:
 * ogni modifica sostanziale richiede di aggiornare config('beta.terms_version').
 */
class BetaTerms
{
    /** @return list<array{title: string, body: string}> */
    public static function sections(): array
    {
        return [
            ['title' => 'Servizio sperimentale', 'body' => 'EA-Express è in versione beta: un periodo di prova del portale e del servizio di ritiro e consegna, offerto in forma sperimentale e gratuita. Funzioni, tempi e disponibilità possono cambiare o essere sospesi in qualsiasi momento.'],
            ['title' => 'Nessun pagamento tramite l’app', 'body' => 'Durante la beta il portale non gestisce pagamenti: nessuna somma viene addebitata o incassata tramite l’app. Eventuali accordi commerciali tra le parti restano possibili e vengono definiti al di fuori del portale. Le tariffe mostrate sono indicative e servono a provare il funzionamento del servizio.'],
            ['title' => 'Uso responsabile', 'body' => 'Inserisci dati veritieri e affida al servizio solo merce lecita, imballata correttamente e senza valore rilevante. Non usare il portale per spedire oggetti di valore, documenti importanti o merci soggette a regole particolari.'],
            ['title' => 'Nessuna garanzia', 'body' => 'Trattandosi di una prova, il servizio è fornito così com’è, senza garanzie su tempi di consegna, continuità o assenza di errori. Segnala ogni problema a EA-Express: ci aiuterà a migliorare il servizio prima del lancio ufficiale.'],
            ['title' => 'Dati personali', 'body' => 'I dati dell’account e delle spedizioni sono trattati secondo l’informativa privacy del portale, solo per far funzionare il servizio durante la beta. Al termine della prova ti informeremo su cosa accadrà ai tuoi dati prima di ogni passaggio al servizio ufficiale.'],
            ['title' => 'Fine della beta', 'body' => 'La beta può terminare in qualsiasi momento. Al lancio ufficiale ti verranno proposte le condizioni definitive del servizio, che potrai accettare o rifiutare.'],
        ];
    }

    /** @return array{beta: bool, terms_version: string, privacy_version: string, terms: list<array{title: string, body: string}>} */
    public static function platform(): array
    {
        return [
            'beta' => (bool) config('beta.enabled'),
            'terms_version' => (string) config('beta.terms_version'),
            'privacy_version' => (string) config('customer_privacy.version'),
            'terms' => config('beta.enabled') ? self::sections() : [],
        ];
    }
}
