<?php

namespace App\Models;

use Database\Factories\OrderMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['body'])]
class OrderMessage extends Model
{
    /** @use HasFactory<OrderMessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function conversationData(): array
    {
        return ['id' => $this->id, 'body' => $this->body, 'sender' => $this->user_id ? 'courier' : 'customer', 'created_at' => $this->created_at->toIso8601String(), 'delivered_at' => $this->delivered_at?->toIso8601String(), 'read_at' => $this->read_at?->toIso8601String()];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
