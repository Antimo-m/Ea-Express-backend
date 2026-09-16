<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Support\CustomerIdentity;
use App\Support\Money;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateOrder
{
    public function __construct(private NotifyOrderParticipants $notify) {}

    /** @param array<string,mixed> $data */
    public function handle(User $creator, array $data): Order
    {
        return DB::transaction(function () use ($creator, $data): Order {
            $value = $data['parcel_value'] ?? null;
            unset($data['parcel_value']);
            $data = CustomerIdentity::normalize($data, $creator->sender_type ?? 'business');
            $order = new Order($data);
            $order->parcel_value_cents = $value !== null ? Money::cents((string) $value) : null;
            $order->reference = 'EA-'.Str::ulid();
            $order->tracking_token = Str::random(64);
            $order->created_by = $creator->id;
            $order->status = OrderStatus::Received;
            if ($creator->role === UserRole::Customer) {
                $order->customer_id = $creator->id;
                $order->store_name = $data['store_name'] ?? $creator->name;
                if ($order->sender_type === 'business') {
                    $order->business_type = $data['business_type'] ?? $creator->business_type;
                    $order->business_description = $data['business_description'] ?? $creator->business_description;
                }
                $order->contact_email = $creator->email;
            }
            $order->save();
            $order->events()->create(['user_id' => $creator->id, 'status' => OrderStatus::Received]);
            $this->notify->handle($order, 'Nuova richiesta', $creator->id);

            return $order;
        });
    }
}
