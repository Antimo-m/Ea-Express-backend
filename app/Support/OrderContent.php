<?php

namespace App\Support;

class OrderContent
{
    public const array Categories = [
        'custom' => 'Scrivi il contenuto',
        'clothing' => 'Abbigliamento',
        'documents' => 'Documenti',
        'books' => 'Libri e cancelleria',
        'electronics' => 'Elettronica e accessori',
        'household' => 'Articoli per la casa',
        'cosmetics' => 'Cosmetici e cura personale',
        'toys' => 'Giocattoli e giochi',
        'sports' => 'Articoli sportivi',
        'spare_parts' => 'Ricambi e utensili',
        'crafts' => 'Artigianato e regali',
        'other' => 'Altro',
    ];

    public static function label(string $category, ?string $description = null): string
    {
        if ($category === 'custom') {
            return $description ?? 'Contenuto non specificato';
        }

        $label = self::Categories[$category] ?? $category;

        return $description ? $label.': '.$description : $label;
    }
}
