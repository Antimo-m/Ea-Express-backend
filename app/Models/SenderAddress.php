<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_name', 'sender_type', 'business_type', 'business_description', 'pickup_address', 'pickup_street_number', 'pickup_postal_code', 'pickup_city'])]
class SenderAddress extends Model
{
    use HasFactory;

    public const Fields = ['store_name', 'sender_type', 'business_type', 'business_description', 'pickup_address', 'pickup_street_number', 'pickup_postal_code', 'pickup_city'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
