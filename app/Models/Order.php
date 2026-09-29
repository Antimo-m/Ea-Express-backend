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
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['shipping_type', 'delivery_province', 'delivery_region', 'weight_kg', 'max_dimension_cm', 'delivery_zone', 'pickup_street_number', 'pickup_postal_code', 'delivery_street_number', 'delivery_postal_code', 'package_type', 'package_description', 'packages', 'store_name', 'contact_email', 'recipient_name', 'recipient_phone', 'pickup_address', 'pickup_city', 'delivery_address', 'delivery_city', 'pickup_date', 'pickup_from', 'pickup_to', 'delivery_window', 'parcel_count', 'content_description', 'category', 'urgency', 'notes', 'customer_notes', 'sender_type', 'business_type', 'business_description'])]
#[Hidden(['tracking_token', 'conversation_token', 'carrier_cost_cents'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $attributes = ['version' => 1, 'shipping_type' => 'regional', 'carrier_cost_cents' => 0];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'carrier_cost_cents' => 'integer', 'carrier_handed_at' => 'datetime', 'estimated_delivery_from' => 'date', 'estimated_delivery_to' => 'date', 'weight_kg' => 'decimal:2', 'max_dimension_cm' => 'decimal:2', 'pickup_reminded_on' => 'date', 'receipt_voided_at' => 'datetime', 'pricing_version' => 'integer', 'quoted_price_cents' => 'integer', 'rate_snapshot' => 'array', 'payment_proposed_at' => 'datetime', 'payment_confirmed_at' => 'datetime', 'packages' => 'array', 'conversation_expires_at' => 'datetime', 'status' => OrderStatus::class, 'pickup_date' => 'date', 'rejected_at' => 'datetime', 'tracking_started_at' => 'datetime', 'delivered_at' => 'datetime', 'paid_at' => 'datetime', 'estimated_at' => 'datetime', 'price_cents' => 'integer', 'parcel_value_cents' => 'integer', 'version' => 'integer'];
    }

    /** @return array{state: string, label: string, paid_at: ?string} */
    public function receiptData(): array
    {
        return [
            'state' => $this->receipt_voided_at ? 'voided' : ($this->paid_at ? 'paid' : 'unrecorded'),
            'label' => $this->receipt_voided_at ? 'Incasso stornato' : ($this->paid_at ? 'Pagato' : 'Da registrare'),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }

    public function totalCents(): ?int
    {
        return $this->price_cents === null ? null : ($this->parcel_value_cents ?? 0) + $this->price_cents;
    }

    public function priceProposals(): HasMany
    {
        return $this->hasMany(ShippingPriceProposal::class);
    }

    public function shippingRate(): BelongsTo
    {
        return $this->belongsTo(ShippingRate::class);
    }

    /** @return array{pickup_date: string, pickup_from: string, pickup_to: string} */
    public function pickupSchedule(): array
    {
        return ['pickup_date' => $this->pickup_date->toDateString(), 'pickup_from' => substr($this->pickup_from, 0, 5), 'pickup_to' => substr($this->pickup_to, 0, 5)];
    }

    #[Scope]
    protected function awaitingPickup(Builder $query): void
    {
        $query->whereIn('status', [OrderStatus::Received, OrderStatus::Accepted, OrderStatus::PickupScheduled, OrderStatus::RiderArriving, OrderStatus::DeliveryIssue, OrderStatus::Rescheduled])
            ->whereDoesntHave('events', fn ($events) => $events->where('status', OrderStatus::PickedUp));
    }

    public function customerEditable(): bool
    {
        return $this->status === OrderStatus::Received && $this->rider_id === null;
    }

    public function priceLabel(): string
    {
        return match ($this->price_state) {
            'awaiting_rider' => 'Prezzo da confermare','awaiting_customer' => 'Nuova proposta','agreed' => 'Prezzo accettato','rejected' => 'Proposta rifiutata','unavailable' => 'Tariffa da verificare',default => $this->price_cents === null ? 'Tariffa da verificare' : 'Prezzo storico'
        };
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentEntry::class);
    }

    public function pendingAccount(): HasOne
    {
        return $this->hasOne(PendingAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function displayName(): string
    {
        return trim($this->store_name ?? '') ?: (trim($this->customer?->name ?? '') ?: (trim($this->creator?->name ?? '') ?: $this->reference));
    }

    #[Scope]
    protected function withDisplayIdentity(Builder $query): void
    {
        $query->with(['customer:id,name', 'creator:id,name']);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(OrderMessage::class)->ofMany(['created_at' => 'max', 'id' => 'max']);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OrderMessage::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
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
        $query->where('rider_id', $user->id)->whereNotIn('status', [OrderStatus::Received, OrderStatus::Rejected]);
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
