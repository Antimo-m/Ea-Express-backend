<?php

namespace App\Actions;

use App\Events\WorkspaceUpdated;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderActivity;
use App\UserRole;

class NotifyAccountingParticipants
{
    public function handle(Order $order, string $title, ?int $actorId): void
    {
        WorkspaceUpdated::dispatch($order->id, 'accounting');
        User::where('role', UserRole::Admin)->where('is_active', true)->where('notify_orders', true)
            ->when($actorId !== null, fn ($query) => $query->where('id', '!=', $actorId))
            ->each(fn (User $user) => $user->notify(new OrderActivity($order->id, $order->reference, $title, false, $order->displayName())));
    }
}
