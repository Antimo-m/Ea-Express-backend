<?php

namespace App\Support;

class PaymentMethod
{
    public const array Labels = ['cash' => 'Contanti', 'card' => 'Carta tramite POS', 'bank_transfer' => 'Bonifico', 'other' => 'Altro'];
}
