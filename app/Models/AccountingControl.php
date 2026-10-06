<?php

namespace App\Models;

use App\Support\HasPublicSlug;
use Illuminate\Database\Eloquent\Model;

class AccountingControl extends Model
{
    use HasPublicSlug;

    protected function casts(): array
    {
        return ['closed_through' => 'date', 'version' => 'integer'];
    }
}
