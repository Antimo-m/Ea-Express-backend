<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Carbon;

class NotificationInbox
{
    /** Indice di copertura per raggruppare per ordine senza leggere le righe della tabella. */
    private const string OrderIndex = 'notifications_inbox_order_index';

    /** Indice per le notifiche più recenti: la lettura si ferma appena raggiunto il limite. */
    private const string RecentIndex = 'notifications_inbox_recent_index';

    private function visibleNotifications(User $user, ?string $index = null): MorphMany
    {
        $orders = $user->role === UserRole::Customer ? Order::where('customer_id', $user->id) : Order::visibleTo($user);
        $notifications = $user->notifications();
        if ($index !== null) {
            $notifications->getQuery()->getQuery()->forceIndex($index);
        }

        return $notifications->whereIn('order_id', $orders->select('id'));
    }

    public function unreadCount(User $user): int
    {
        return $this->visibleNotifications($user)->whereNull('read_at')->count();
    }

    /**
     * Gruppi per ordine, dal più recente: ordinamento per ultima notifica e, a parità, per ordine decrescente.
     *
     * Invece di aggregare e ordinare tutti gli ordini notificati, si scorrono le notifiche dalla più recente fino a
     * raccogliere gli ordini della pagina, poi si calcolano i totali soltanto per quelli.
     */
    public function groups(User $user): LengthAwarePaginator
    {
        $perPage = 12;
        $page = Paginator::resolveCurrentPage();
        $total = (int) $this->visibleNotifications($user, self::OrderIndex)->reorder()->toBase()->selectRaw('COUNT(DISTINCT order_id) AS aggregate')->value('aggregate');
        $orderIds = array_slice($this->recentOrderIds($user, $page * $perPage), ($page - 1) * $perPage, $perPage);
        $stats = $orderIds === [] ? collect() : $this->visibleNotifications($user, self::OrderIndex)->reorder()->whereIn('order_id', $orderIds)->toBase()->select('order_id')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread, MAX(created_at) as latest_at')
            ->groupBy('order_id')->get()->keyBy(fn ($group): int => (int) $group->order_id);
        $names = Order::whereIn('id', $orderIds)->withDisplayIdentity()->get(['id', 'slug', 'customer_id', 'created_by', 'reference', 'store_name', 'urgency'])->keyBy('id');
        $orderIds = array_values(array_filter($orderIds, fn (int $orderId): bool => $stats->has($orderId)));
        $items = array_map(function (int $orderId) use ($stats, $names): object {
            $group = $stats[$orderId];
            $order = $names->get($orderId);

            return (object) ['reference' => $order?->reference, 'total' => (int) $group->total, 'unread' => (int) $group->unread, 'latest_at' => Carbon::parse($group->latest_at)->toIso8601String(),
                'order_slug' => $order?->slug, 'customer_name' => $order?->displayName() ?? 'Cliente', 'urgency' => $order?->urgency ?? 'standard'];
        }, $orderIds);

        return (new Paginator($items, $total, $perPage, $page, ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page']))->withQueryString();
    }

    /**
     * Ordini distinti nell'ordine della loro notifica più recente; a parità di istante, ordine decrescente.
     *
     * @return list<int>
     */
    private function recentOrderIds(User $user, int $needed): array
    {
        $ids = [];
        $seen = [];
        $batch = max(100, $needed * 3);
        $recent = fn () => $this->visibleNotifications($user, self::RecentIndex)->reorder()->toBase()->select(['order_id', 'created_at'])->orderByDesc('created_at');
        $cursor = null;
        while (count($ids) < $needed) {
            $rows = $recent()->when($cursor !== null, fn ($query) => $query->where('created_at', '<', $cursor))->limit($batch)->get();
            if ($rows->isEmpty()) {
                break;
            }
            $complete = $rows->count() < $batch;
            $cursor = $rows->last()->created_at;
            if (! $complete) {
                $rows = $rows->reject(fn ($row): bool => $row->created_at === $cursor)->concat($recent()->where('created_at', $cursor)->get());
            }
            foreach ($rows->groupBy('created_at') as $instant) {
                foreach ($instant->map(fn ($row): int => (int) $row->order_id)->unique()->sortDesc() as $orderId) {
                    if (! isset($seen[$orderId])) {
                        $seen[$orderId] = true;
                        $ids[] = $orderId;
                    }
                }
            }
            if ($complete) {
                break;
            }
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    public function history(User $user, int $orderId): array
    {
        $orders = $user->role === UserRole::Customer ? Order::where('customer_id', $user->id) : Order::visibleTo($user);
        abort_unless($orders->whereKey($orderId)->exists(), 404);
        $items = $this->visibleNotifications($user)->where('order_id', $orderId)->latest()->orderByDesc('id')->paginate(20);

        $slugs = Order::whereIn('id', $items->getCollection()->map(fn ($item) => $item->data['order_id']))->pluck('slug', 'id')->all();

        return ['data' => $items->getCollection()->map(fn (DatabaseNotification $item) => $this->item($item, $slugs)), 'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage()]];
    }

    /** @return array<string, mixed> */
    public function feed(User $user): array
    {
        return ['unread' => $this->visibleNotifications($user)->whereNull('read_at')->count(), 'items' => $this->visibleNotifications($user, self::RecentIndex)->latest()->orderByDesc('id')->limit(50)->get()->map(fn (DatabaseNotification $item) => ['id' => $item->id, 'read' => $item->read_at !== null])];
    }

    /** @return array<string, mixed> */
    public function item(DatabaseNotification $item, array $slugs): array
    {
        return ['customer_name' => $item->data['customer_name'] ?? null, 'id' => $item->id, 'title' => $item->data['title'], 'reference' => $item->data['reference'], 'order_slug' => $slugs[$item->data['order_id']] ?? null, 'is_message' => $item->data['message'] ?? false, 'read_at' => $item->read_at?->toIso8601String(), 'created_at' => $item->created_at->toIso8601String()];
    }
}
