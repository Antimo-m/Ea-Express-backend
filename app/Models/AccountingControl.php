<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingControl extends Model
{
    protected function casts(): array
    {
        return ['closed_through' => 'date', 'version' => 'integer'];
    }
}
