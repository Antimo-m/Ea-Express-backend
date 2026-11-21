<?php

namespace App\Support;

/**
 * Consiglio mostrato nelle email con un collegamento (ripristino password, tracking).
 *
 * È un consiglio, non un requisito: i link funzionano in qualunque browser, ma quello usato abitualmente
 * ha già l'accesso al portale e alle sue pagine protette.
 */
final class MailLinkAdvice
{
    public const string Text = 'Per aprire correttamente il collegamento, ti consigliamo di utilizzare lo stesso browser con cui accedi abitualmente a EA-Express.';
}
