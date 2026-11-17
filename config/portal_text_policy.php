<?php

/*
 * Filtro centralizzato dei contenuti offensivi (gestionale, portale e frontend tramite /text-policy).
 * Le parole sono confrontate a parola intera dopo la normalizzazione (maiuscole, accenti, leetspeak,
 * separatori e lettere ripetute); un termine che termina con «*» vale come radice. Non si applica a
 * indirizzi e password. Ogni modifica sostanziale richiede di incrementare «version».
 */
return [
    'version' => 2,
    'message' => 'Il contenuto inserito non è consentito. Verifica il campo e riprova.',
    'substitutions' => [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '€' => 'e',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
    ],
    'terms' => [
        // Volgarità e insulti italiani.
        'cazzo', 'cazzi', 'cazzata', 'cazzate', 'cazzone', 'cazzoni', 'cazzuto', 'incazzato',
        'coglion*', 'stronz*', 'merda', 'merde', 'merdos*', 'merdaccia', 'vaffancul*', 'fanculo', 'affanculo',
        'puttana', 'puttane', 'puttaniere', 'puttanata', 'zoccola', 'zoccole', 'mignotta', 'mignotte',
        'frocio', 'froci', 'ricchione', 'ricchioni', 'figlio di puttana', 'testa di cazzo',
        // Bestemmie.
        'porco dio', 'porca madonna', 'dio cane', 'dio porco', 'dio maiale', 'dio bestia', 'madonna puttana', 'madonna troia', 'porco gesu', 'cristo porco',
        // Minacce e violenza esplicita.
        'ti ammazzo', 'vi ammazzo', 'ti uccido', 'vi uccido', 'ti sgozzo', 'ti sparo', 'ammazzati', 'kill you',
        // Volgarità e insulti inglesi.
        'fuck*', 'motherfucker', 'shit', 'bullshit', 'asshole', 'bitch', 'bitches', 'cunt', 'dickhead', 'whore',
        'nigger', 'nigga', 'faggot',
        // Sostanze stupefacenti.
        'cocaina', 'cocaine', 'hashish', 'heroin', 'marijuana', 'methamphetamine', 'mdma',
    ],
];
