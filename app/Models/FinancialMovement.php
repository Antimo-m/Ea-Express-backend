<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kind', 'description', 'occurred_on', 'notes', 'customer_id', 'order_id'])]
class FinancialMovement extends Model
{
    use HasFactory;

    public const array Kinds = ['income' => 'Entrata aggiuntiva', 'extra_expense' => 'Spesa extra', 'adjustment_in' => 'Rettifica positiva', 'adjustment_out' => 'Rettifica negativa'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'version' => 'integer', 'occurred_on' => 'date', 'voided_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
