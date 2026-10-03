<?php

namespace App\Events;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class RiderLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $orderId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return [];
        }

        return User::where('is_active', true)->where(function ($query) use ($order): void {
            $query->where('role', UserRole::Admin)
                ->orWhere(fn ($q) => $q->where('role', UserRole::Customer)->where('id', $order->customer_id))
                ->orWhere(fn ($q) => $q->where('role', UserRole::Rider)->whereNotNull('email_verified_at')->where('id', $order->rider_id));
        })->get(['id', 'role'])->map(fn (User $user) => new PrivateChannel(($user->role === UserRole::Customer ? 'customer.' : 'staff.').$user->id))->all();
    }

    public function broadcastAs(): string
    {
        return 'rider.location-updated';
    }

    /** @return array{order_id: int} */
    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId];
    }
}
