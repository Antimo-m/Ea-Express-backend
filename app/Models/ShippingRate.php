<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['is_default', 'shipping_type', 'carrier_name', 'carrier_cost_cents', 'max_weight_kg', 'max_dimension_cm', 'delivery_days_min', 'delivery_days_max', 'zone', 'street', 'area', 'city', 'city_key', 'postal_code', 'price_cents', 'delivery_time', 'active', 'supersedes_id', 'source_reference'])]
class ShippingRate extends Model
{
    use HasFactory;

    public function successor(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'carrier_cost_cents' => 'integer', 'delivery_days_min' => 'integer', 'delivery_days_max' => 'integer', 'max_weight_kg' => 'decimal:2', 'max_dimension_cm' => 'decimal:2', 'archived_at' => 'datetime', 'active' => 'boolean', 'price_cents' => 'integer'];
    }
}
