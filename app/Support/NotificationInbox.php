<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class NotificationInbox
{
    private function visibleNotifications(User $user): MorphMany
    {
        $orders = $user->role === UserRole::Customer ? Order::where('customer_id', $user->id) : Order::visibleTo($user);

        return $user->notifications()->whereIn('data->order_id', $orders->select('id'));
    }

    public function unreadCount(User $user): int
    {
        return $this->visibleNotifications($user)->whereNull('read_at')->count();
    }

    public function groups(User $user): LengthAwarePaginator
    {
        $groups = $this->visibleNotifications($user)->reorder()->select('data->order_id as order_id', 'data->reference as reference')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread, MAX(created_at) as latest_at')
            ->groupBy('data->order_id', 'data->reference')->orderByDesc('latest_at')->orderByDesc('order_id')->paginate(12)->withQueryString()->through(fn ($group) => (object) ['order_id' => (int) $group->order_id, 'reference' => $group->reference, 'total' => (int) $group->total, 'unread' => (int) $group->unread, 'latest_at' => Carbon::parse($group->latest_at)->toIso8601String()]);
        $names = Order::whereIn('id', $groups->getCollection()->pluck('order_id'))->withDisplayIdentity()->get(['id', 'slug', 'customer_id', 'created_by', 'reference', 'store_name', 'urgency'])->keyBy('id');

        return $groups->through(function ($group) use ($names) {
            $order = $names->get($group->order_id);
            $group->order_slug = $order?->slug;
            unset($group->order_id);
            $group->customer_name = $order?->displayName() ?? 'Cliente';
            $group->urgency = $order?->urgency ?? 'standard';

            return $group;
        });
    }

    /** @return array<string, mixed> */
    public function history(User $user, int $orderId): array
    {
        $orders = $user->role === UserRole::Customer ? Order::where('customer_id', $user->id) : Order::visibleTo($user);
        abort_unless($orders->whereKey($orderId)->exists(), 404);
        $items = $this->visibleNotifications($user)->where('data->order_id', $orderId)->latest()->orderByDesc('id')->paginate(20);

        $slugs = Order::whereIn('id', $items->getCollection()->map(fn ($item) => $item->data['order_id']))->pluck('slug', 'id')->all();

        return ['data' => $items->getCollection()->map(fn (DatabaseNotification $item) => $this->item($item, $slugs)), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage()]];
    }

    /** @return array<string, mixed> */
    public function feed(User $user): array
    {
        return ['unread' => $this->visibleNotifications($user)->whereNull('read_at')->count(), 'items' => $this->visibleNotifications($user)->latest()->orderByDesc('id')->limit(50)->get()->map(fn (DatabaseNotification $item) => ['id' => $item->id, 'read' => $item->read_at !== null])];
    }

    /** @return array<string, mixed> */
    public function item(DatabaseNotification $item, array $slugs): array
    {
        return ['customer_name' => $item->data['customer_name'] ?? null, 'id' => $item->id, 'title' => $item->data['title'], 'reference' => $item->data['reference'], 'order_slug' => $slugs[$item->data['order_id']] ?? null, 'is_message' => $item->data['message'] ?? false, 'read_at' => $item->read_at?->toIso8601String(), 'created_at' => $item->created_at->toIso8601String()];
    }
}
