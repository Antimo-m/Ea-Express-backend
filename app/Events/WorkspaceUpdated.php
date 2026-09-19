<?php

namespace App\Events;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

class WorkspaceUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $eventId;

    public int $tries = 5;

    public array $backoff = [1, 3, 10, 30];

    public function __construct(public int $orderId, public string $kind)
    {
        $this->eventId = (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return [];
        }

        $channels = User::where('is_active', true)->where(fn ($query) => $query->where('id', $order->customer_id)->orWhereIn('role', [UserRole::Admin, UserRole::Rider]))->get()->filter(fn (User $user) => $user->role === UserRole::Customer ? $order->customer_id === $user->id : ($this->kind === 'order' || $user->can('view', $order)))->map(fn (User $user) => new PrivateChannel(($user->role === UserRole::Customer ? 'customer.' : 'staff.').$user->id))->values()->all();
        if ($this->kind === 'order' && $order->tracking_started_at) {
            $channels[] = new PrivateChannel('tracking.'.hash('sha256', $order->tracking_token));
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'workspace.updated';
    }

    public function broadcastWith(): array
    {
        if ($this->kind === 'order') {
            return ['event_id' => $this->eventId, 'kind' => $this->kind];
        }

        return ['event_id' => $this->eventId, 'order_id' => $this->orderId, 'kind' => $this->kind];
    }
}
