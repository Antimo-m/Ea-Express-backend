<?php

namespace App\Models;

use App\OrderStatus;
use App\Support\PaymentMethod;
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

#[Fillable(['delivery_zone', 'pickup_street_number', 'pickup_postal_code', 'delivery_street_number', 'delivery_postal_code', 'package_type', 'package_description', 'packages', 'store_name', 'contact_email', 'recipient_name', 'recipient_phone', 'pickup_address', 'pickup_city', 'delivery_address', 'delivery_city', 'pickup_date', 'pickup_from', 'pickup_to', 'delivery_window', 'parcel_count', 'content_description', 'category', 'urgency', 'notes', 'customer_notes', 'sender_type', 'business_type', 'business_description'])]
#[Hidden(['tracking_token', 'conversation_token'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['pricing_version' => 'integer', 'quoted_price_cents' => 'integer', 'rate_snapshot' => 'array', 'payment_proposed_at' => 'datetime', 'payment_confirmed_at' => 'datetime', 'packages' => 'array', 'conversation_expires_at' => 'datetime', 'status' => OrderStatus::class, 'pickup_date' => 'date', 'rejected_at' => 'datetime', 'tracking_started_at' => 'datetime', 'delivered_at' => 'datetime', 'paid_at' => 'datetime', 'estimated_at' => 'datetime', 'price_cents' => 'integer', 'parcel_value_cents' => 'integer', 'version' => 'integer'];
    }

    /** @return array<string, mixed> */
    public function paymentData(): array
    {
        return [
            'method' => $this->payment_method,
            'method_label' => PaymentMethod::Labels[$this->payment_method] ?? 'Non registrato (storico)',
            'state' => $this->paid_at ? 'paid' : ($this->payment_confirmed_at ? 'agreed' : ($this->payment_method ? 'proposed' : 'unrecorded')),
            'label' => $this->paid_at ? 'Pagato' : ($this->payment_confirmed_at ? 'Concordato' : ($this->payment_method ? 'In attesa di conferma' : 'Metodo non registrato')),
            'proposed_by' => $this->payment_proposed_by,
            'proposed_at' => $this->payment_proposed_at?->toIso8601String(),
            'confirmed_by' => $this->payment_confirmed_by,
            'confirmed_at' => $this->payment_confirmed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_by' => $this->paid_by,
        ];
    }

    public function priceProposals(): HasMany
    {
        return $this->hasMany(ShippingPriceProposal::class);
    }

    public function shippingRate(): BelongsTo
    {
        return $this->belongsTo(ShippingRate::class);
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

    public function pendingAccount(): HasOne
    {
        return $this->hasOne(PendingAccount::class);
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
