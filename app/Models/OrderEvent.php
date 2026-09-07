<?php

namespace App\Models;

use App\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'status', 'note', 'public_note'])]
class OrderEvent extends Model
{
    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
