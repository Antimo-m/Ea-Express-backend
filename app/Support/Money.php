<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    public static function cents(string $value): int
    {
        $value = str_replace(',', '.', $value);
        if (! preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $value)) {
            throw new InvalidArgumentException('Importo non valido.');
        }
        $parts = explode('.', $value);

        return ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public static function format(?int $cents): string
    {
        return $cents === null ? 'Tariffa da verificare' : '€ '.number_format($cents / 100, 2, ',', '.');
    }
}
