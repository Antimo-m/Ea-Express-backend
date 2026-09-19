<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['zone', 'street', 'area', 'city', 'city_key', 'postal_code', 'price_cents', 'delivery_time', 'active', 'supersedes_id', 'source_reference'])]
class ShippingRate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean', 'price_cents' => 'integer'];
    }
}
