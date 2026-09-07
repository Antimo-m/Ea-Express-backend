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
        return $user->isStaff() && ($user->role === UserRole::Admin || $order->rider_id === $user->id || ($order->rider_id === null && $order->status === OrderStatus::Received) || ($order->recoverable() && $order->rejected_by === $user->id));
    }
}
