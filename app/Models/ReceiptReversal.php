<?php

namespace App\Models;

use App\Support\HasPublicSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptReversal extends Model
{
    use HasPublicSlug;

    protected function casts(): array
    {
        return ['original_amount_cents' => 'integer', 'collected_cents' => 'integer', 'corrected_cents' => 'integer', 'original_paid_at' => 'datetime', 'restored_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentEntry::class);
    }
}
