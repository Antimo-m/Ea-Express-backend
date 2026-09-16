<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

class NotificationInbox
{
    public function groups(User $user): LengthAwarePaginator
    {
        return $user->notifications()->reorder()->select('data->order_id as order_id', 'data->reference as reference')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread, MAX(created_at) as latest_at')
            ->groupBy('data->order_id', 'data->reference')->orderByDesc('latest_at')->orderByDesc('order_id')->paginate(12)->withQueryString()->through(fn ($group) => (object) ['order_id' => (int) $group->order_id, 'reference' => $group->reference, 'total' => (int) $group->total, 'unread' => (int) $group->unread, 'latest_at' => Carbon::parse($group->latest_at)->toIso8601String()]);
    }

    /** @return array<string, mixed> */
    public function history(User $user, int $orderId): array
    {
        $items = $user->notifications()->where('data->order_id', $orderId)->latest()->orderByDesc('id')->paginate(20);

        return ['data' => $items->getCollection()->map(fn (DatabaseNotification $item) => $this->item($item)), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage()]];
    }

    /** @return array<string, mixed> */
    public function feed(User $user): array
    {
        return ['unread' => $user->unreadNotifications()->count(), 'items' => $user->notifications()->latest()->orderByDesc('id')->limit(50)->get()->map(fn (DatabaseNotification $item) => ['id' => $item->id, 'read' => $item->read_at !== null])];
    }

    /** @return array<string, mixed> */
    private function item(DatabaseNotification $item): array
    {
        return ['id' => $item->id, 'title' => $item->data['title'], 'reference' => $item->data['reference'], 'order_id' => $item->data['order_id'], 'is_message' => $item->data['message'] ?? false, 'read_at' => $item->read_at?->toIso8601String(), 'created_at' => $item->created_at->toIso8601String()];
    }
}
