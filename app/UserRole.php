<?php

namespace App;

enum UserRole: string
{
    case Admin = 'admin';
    case Rider = 'rider';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Amministratore',
            self::Rider => 'Rider',
            self::Customer => 'Cliente',
        };
    }
}
