<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    public function view(User $user, Order $order): Response
    {
        return Order::visibleTo($user)->whereKey($order->id)->exists() ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Order $order): bool
    {
        return $user->is_active && ($user->role === UserRole::Admin || ($user->role === UserRole::Rider && $user->email_verified_at && $order->rider_id === $user->id && ! in_array($order->status, [OrderStatus::Received, OrderStatus::Rejected], true)));
    }
}
