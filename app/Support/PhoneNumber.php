<?php

namespace App\Support;

/**
 * Telefoni dei destinatari: sanificazione, normalizzazione e validazione strutturale.
 *
 * - Sanificazione: sono ammessi solo cifre, spazi, trattini, punti, barre, parentesi e un "+" iniziale.
 * - Normalizzazione: i numeri italiani diventano "+39 <numero nazionale>", gli esteri "+<cifre>".
 * - Validazione: piano di numerazione italiano (fissi 0…, cellulari 3…) o formato E.164 per l'estero,
 *   più l'esclusione di sequenze palesemente fittizie. Non verifica che il numero esista.
 *
 * La stessa logica è replicata in resources/js/phone.js e nel portale (src/utils/phone.js);
 * tests/fixtures/phone-numbers.json contiene i casi condivisi.
 */
class PhoneNumber
{
    public const Message = 'Inserisci un numero di telefono valido.';

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $raw = trim($value);
        if ($raw === '' || mb_strlen($raw) > 30 || ! preg_match('/^\+?[0-9 ().\/-]+$/D', $raw)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw);
        $international = str_starts_with($raw, '+') || str_starts_with($digits, '00');
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if ($international && ! str_starts_with($digits, '39')) {
            return self::plausibleInternational($digits) ? '+'.$digits : null;
        }
        $national = $international ? substr($digits, 2) : $digits;

        return self::plausibleItalian($national) ? '+39 '.$national : null;
    }

    public static function isValid(mixed $value): bool
    {
        return self::normalize($value) !== null;
    }

    private static function plausibleItalian(string $national): bool
    {
        if (! preg_match('/^(?:0\d{5,10}|3[1-9]\d{7,8})$/D', $national)) {
            return false;
        }

        return ! self::fictitious($national);
    }

    private static function plausibleInternational(string $digits): bool
    {
        return preg_match('/^[1-9]\d{7,14}$/D', $digits) === 1 && ! self::fictitious(substr($digits, 1));
    }

    /** Sequenze ripetute o progressive che nessun operatore assegna (es. 0000000000, 3331111111, 0123456789). */
    private static function fictitious(string $number): bool
    {
        if (preg_match('/^.(\d)\1+$/D', $number) || preg_match('/(\d)\1{6}$/D', $number)) {
            return true;
        }
        $ascending = $descending = true;
        for ($index = 1, $length = strlen($number); $index < $length; $index++) {
            $step = (int) $number[$index] - (int) $number[$index - 1];
            $ascending = $ascending && in_array($step, [1, -9], true);
            $descending = $descending && in_array($step, [-1, 9], true);
        }

        return $ascending || $descending;
    }
}
