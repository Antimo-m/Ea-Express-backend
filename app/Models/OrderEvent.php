<?php

namespace App\Models;

use App\OrderStatus;
use App\Support\HasPublicSlug;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['rider_id', 'operational_zone', 'user_id', 'status', 'note', 'public_note', 'schedule_change', 'delay_action'])]
class OrderEvent extends Model
{
    use HasPublicSlug;

    protected function casts(): array
    {
        return ['schedule_change' => 'array', 'status' => OrderStatus::class];
    }

    public function label(): string
    {
        if ($this->delay_action !== null) {
            return $this->delay_action === 'reported' ? 'Ritardo segnalato dal Rider' : 'Segnalazione di ritardo corretta';
        }
        if (isset($this->schedule_change['after']['pickup_date'])) {
            return $this->schedule_change['before'] ? 'Ritiro ripianificato' : 'Ritiro pianificato';
        }

        return $this->status->label();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
