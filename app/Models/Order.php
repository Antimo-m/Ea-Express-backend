<?php

namespace App\Models;

use App\OrderStatus;
use App\UserRole;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['packages', 'store_name', 'contact_email', 'recipient_name', 'recipient_phone', 'pickup_address', 'pickup_city', 'delivery_address', 'delivery_city', 'pickup_date', 'pickup_from', 'pickup_to', 'delivery_window', 'parcel_count', 'category', 'urgency', 'notes', 'customer_notes', 'sender_type', 'business_type', 'business_description'])]
#[Hidden(['tracking_token', 'conversation_token'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['packages' => 'array', 'conversation_expires_at' => 'datetime', 'status' => OrderStatus::class, 'pickup_date' => 'date', 'rejected_at' => 'datetime', 'tracking_started_at' => 'datetime', 'delivered_at' => 'datetime', 'paid_at' => 'datetime', 'estimated_at' => 'datetime', 'price_cents' => 'integer', 'parcel_value_cents' => 'integer', 'version' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OrderMessage::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class);
    }

    #[Scope]
    protected function financialFor(Builder $query, User $user): void
    {
        if ($user->role !== UserRole::Admin) {
            $query->where('rider_id', $user->id);
        }
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->role === UserRole::Admin) {
            return;
        }
        if ($user->role !== UserRole::Rider) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(function (Builder $q) use ($user) {
            $q->where('rider_id', $user->id)->orWhere('created_by', $user->id)
                ->orWhere('rejected_by', $user->id)->orWhere(fn (Builder $pool) => $pool->whereNull('rider_id')->where('status', OrderStatus::Received));
        });
    }

    /** @return list<OrderStatus> */
    public function allowedTransitions(): array
    {
        if ($this->status === OrderStatus::Rescheduled) {
            $pickedUp = $this->events()->where('status', OrderStatus::PickedUp)->exists();

            return [$pickedUp ? OrderStatus::OutForDelivery : OrderStatus::RiderArriving, OrderStatus::DeliveryIssue, OrderStatus::Cancelled];
        }

        return $this->status->next();
    }

    public function recoverable(): bool
    {
        return $this->status === OrderStatus::Rejected && $this->rejected_at?->greaterThanOrEqualTo(now()->subHour());
    }
}
