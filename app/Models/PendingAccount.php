<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['subject', 'direction', 'description', 'amount_cents', 'customer_id', 'order_id', 'created_by', 'occurred_on', 'due_on', 'notes'])]
class PendingAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'settled_cents' => 'integer', 'occurred_on' => 'date', 'due_on' => 'date', 'settled_at' => 'datetime', 'version' => 'integer'];
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(PendingSettlement::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
