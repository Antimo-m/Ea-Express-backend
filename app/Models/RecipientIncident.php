<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['recipient_risk_profile_id', 'order_id', 'recorded_by', 'phone_key', 'address_key', 'recipient', 'reason', 'occurred_at'])]
class RecipientIncident extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['recipient' => 'array', 'occurred_at' => 'datetime', 'dismissed_at' => 'datetime', 'version' => 'integer'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(RecipientRiskProfile::class, 'recipient_risk_profile_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
