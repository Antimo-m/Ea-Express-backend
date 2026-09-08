<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
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
            $order = new Order($data);
            $order->reference = 'EA-'.Str::ulid();
            $order->tracking_token = Str::random(64);
            $order->created_by = $creator->id;
            $order->status = OrderStatus::Received;
            if ($creator->role === UserRole::Customer) {
                $order->customer_id = $creator->id;
                $order->store_name = $creator->name;
                $order->contact_email = $creator->email;
            }
            $order->save();
            $order->events()->create(['user_id' => $creator->id, 'status' => OrderStatus::Received]);
            $this->notify->handle($order, 'Nuova richiesta', $creator->id);

            return $order;
        });
    }
}
