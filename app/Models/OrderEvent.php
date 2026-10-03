<?php

namespace App\Models;

use App\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rider_id', 'operational_zone', 'user_id', 'status', 'note', 'public_note', 'schedule_change'])]
class OrderEvent extends Model
{
    protected function casts(): array
    {
        return ['schedule_change' => 'array', 'status' => OrderStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
