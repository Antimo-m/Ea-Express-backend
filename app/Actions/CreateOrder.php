<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\CheckoutReview;
use App\Support\CustomerIdentity;
use App\Support\Money;
use App\Support\ShippingQuote;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOrder
{
    public function __construct(private NotifyOrderParticipants $notify, private RecordOrderMail $mail) {}

    /** @param array<string,mixed> $data */
    public function handle(User $creator, array $data): Order
    {
        return DB::transaction(function () use ($creator, $data): Order {
            User::query()->lockForUpdate()->findOrFail($creator->id);
            $review = $creator->role === UserRole::Customer ? app(CheckoutReview::class)->confirm($creator, $data) : null;
            if ($review && ($existing = Order::where('checkout_key', $review['key'])->where('customer_id', $creator->id)->first())) {
                return $existing;
            }
            $method = $data['payment_method'] ?? null;
            unset($data['checkout_token'], $data['payment_method']);
            $value = $data['parcel_value'] ?? null;
            unset($data['parcel_value']);
            $data = CustomerIdentity::normalize($data, $creator->sender_type ?? 'business');
            $customerId = $data['customer_id'] ?? null;
            unset($data['customer_id']);
            $order = new Order($data);
            if ($creator->isStaff() && $customerId) {
                $customer = User::where('role', UserRole::Customer)->where('is_active', true)->findOrFail($customerId);
                $order->customer_id = $customer->id;
                $order->contact_email = $customer->email;
            }
            app(ShippingQuote::class)->apply($order, $review['quote'] ?? null);
            if ($review) {
                $order->checkout_key = $review['key'];
                $order->rate_snapshot = $review['quote'];
                $order->price_cents = $review['quote']['price_cents'];
                $order->price_state = 'agreed';
            }
            $order->payment_method = $method;
            $order->payment_proposed_by = $method ? $creator->id : null;
            $order->payment_proposed_at = $method ? now() : null;
            $order->parcel_value_cents = $value !== null ? Money::cents((string) $value) : null;
            $order->reference = 'EA-'.Str::ulid();
            $order->tracking_token = Str::random(64);
            $order->created_by = $creator->id;
            $order->status = OrderStatus::Received;
            if ($creator->role === UserRole::Customer) {
                $order->customer_id = $creator->id;
                $order->store_name = $data['store_name'] ?? $creator->name;
                if ($order->sender_type !== 'private') {
                    $order->business_type = $data['business_type'] ?? $creator->business_type;
                    $order->business_description = $data['business_description'] ?? $creator->business_description;
                }
                $order->contact_email = $creator->email;
            }
            $order->save();
            app(RecordEconomicAudit::class)->handle($creator, $order, 'price.quoted', null, ['quote' => $order->rate_snapshot, 'price_cents' => $order->price_cents, 'parcel_value_cents' => $order->parcel_value_cents, 'total_cents' => $order->price_cents === null ? null : $order->price_cents + ($order->parcel_value_cents ?? 0)]);
            $order->events()->create(['user_id' => $creator->id, 'status' => OrderStatus::Received]);
            $this->mail->handle($order, 'scheduled');
            $this->notify->handle($order, 'Nuova richiesta', $creator->id);

            return $order;
        });
    }
}
