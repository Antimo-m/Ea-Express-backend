<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'proposed_by', 'previous_price_cents', 'price_cents', 'reason', 'state', 'responded_by', 'responded_at', 'response_note'])]
class ShippingPriceProposal extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'previous_price_cents' => 'integer', 'responded_at' => 'datetime'];
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
