<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderActivity;
use App\OrderStatus;
use App\UserRole;

class NotifyOrderParticipants
{
    public function handle(Order $order, string $title, ?int $actorId, bool $message = false): void
    {
        $recipients = User::query()->whereIn('role', [UserRole::Admin, UserRole::Rider])->where($message ? 'notify_messages' : 'notify_orders', true);
        if ($actorId !== null) {
            $recipients->where('id', '!=', $actorId);
        }
        $recipients->where(function ($query) use ($order) {
            $query->where('role', UserRole::Admin)->orWhere('id', $order->rider_id)->orWhere('id', $order->created_by);
            if ($order->status === OrderStatus::Received && $order->rider_id === null) {
                $query->orWhere('role', UserRole::Rider);
            }
        });
        $recipients->each(function (User $user) use ($order, $title, $message) {
            if ($user->can('view', $order)) {
                $user->notify(new OrderActivity($order->id, $order->reference, $title, $message));
            }
        });
    }
}
