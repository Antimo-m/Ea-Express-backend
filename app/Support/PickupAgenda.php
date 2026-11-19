<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Agenda dei ritiri: tutte le richieste con quella data di ritiro programmata, in qualsiasi stato,
 * limitate a ciò che l'utente può vedere (Admin: tutto; Rider: soltanto i propri ordini accettati).
 * Un ordine non sparisce dall'agenda quando viene ritirato, consegnato o annullato.
 */
class PickupAgenda
{
    /**
     * Avanzamento della giornata: ogni stato appartiene a una sola categoria, così nessun ordine è contato due volte.
     *
     * @var array<string, list<OrderStatus>>
     */
    public const array Progress = [
        'waiting' => [OrderStatus::Received],
        'in_progress' => [OrderStatus::Accepted, OrderStatus::PickupScheduled, OrderStatus::RiderArriving, OrderStatus::Rescheduled, OrderStatus::DeliveryIssue],
        'picked_up' => [OrderStatus::PickedUp, OrderStatus::InTransit, OrderStatus::OutForDelivery, OrderStatus::DeliveryAttempted],
        'delivered' => [OrderStatus::Delivered],
        'cancelled' => [OrderStatus::Cancelled, OrderStatus::Rejected],
    ];

    /** @return Builder<Order> */
    public function scheduled(User $viewer, Carbon $date): Builder
    {
        $query = Order::visibleTo($viewer);
        if ($query->getConnection()->getDriverName() === 'sqlite') {
            return $query->where('pickup_date', '>=', $date->toDateString())
                ->where('pickup_date', '<=', $date->toDateString().' 23:59:59');
        }

        return $query->where('pickup_date', $date->toDateString());
    }

    /** @return array{total: int, waiting: int, in_progress: int, picked_up: int, delivered: int, cancelled: int} */
    public function progress(User $viewer, Carbon $date): array
    {
        $byStatus = $this->scheduled($viewer, $date)->toBase()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $progress = ['total' => (int) $byStatus->sum()];
        foreach (self::Progress as $category => $statuses) {
            $progress[$category] = (int) collect($statuses)->sum(fn (OrderStatus $status): int => (int) ($byStatus[$status->value] ?? 0));
        }

        return $progress;
    }

    /** @return array{groups: LengthAwarePaginator, totalOrders: int} */
    public function accounts(User $viewer, Carbon $date, int $page = 1, int $perPage = 10): array
    {
        $scheduled = $this->scheduled($viewer, $date);
        $totalOrders = (clone $scheduled)->count();
        $groups = (clone $scheduled)
            ->selectRaw('customer_id, CASE WHEN customer_id IS NULL THEN id ELSE 0 END AS manual_order_id, COUNT(*) AS orders_count, SUM(parcel_count) AS parcel_count, MIN(pickup_from) AS first_pickup_time, MIN(id) AS first_order_id')
            ->groupBy('customer_id')->groupByRaw('CASE WHEN customer_id IS NULL THEN id ELSE 0 END')
            ->orderBy('first_pickup_time')->orderBy('first_order_id')
            ->toBase()->paginate($perPage, ['*'], 'page', $page);
        $customers = User::query()->whereIn('id', $groups->getCollection()->pluck('customer_id')->filter()->all())
            ->get(['id', 'slug', 'name'])->keyBy('id');
        $manualIds = $groups->getCollection()->pluck('manual_order_id')->filter()->all();
        $manual = $manualIds ? DB::table('orders')->leftJoin('users as creators', 'creators.id', '=', 'orders.created_by')
            ->whereIn('orders.id', $manualIds)
            ->select(['orders.id', 'orders.slug'])
            ->selectRaw("COALESCE(NULLIF(TRIM(orders.store_name), ''), NULLIF(TRIM(creators.name), ''), orders.reference) AS name")
            ->get()->keyBy('id') : collect();
        $groups->through(function (object $group) use ($customers, $manual, $date): stdClass {
            $account = $group->customer_id === null ? $manual->get($group->manual_order_id) : $customers->get($group->customer_id);

            return $this->summary($account->slug, $account->name, $group->customer_id === null ? 'manual' : 'customer', (int) $group->orders_count, (int) $group->parcel_count, $date);
        });
        $groups->appends(['date' => $date->toDateString(), 'per_page' => $perPage]);

        return compact('groups', 'totalOrders');
    }

    /** @return array{query: Builder<Order>, summary: stdClass} */
    public function account(User $viewer, Carbon $date, int $referenceId, string $kind): array
    {
        $query = $this->scheduled($viewer, $date);
        if ($kind === 'manual') {
            $query->whereKey($referenceId)->whereNull('customer_id');
        } else {
            $query->where('customer_id', $referenceId);
        }
        $counts = (clone $query)->toBase()->selectRaw('COUNT(*) AS orders_count, COALESCE(SUM(parcel_count), 0) AS parcel_count')->first();
        abort_unless($counts->orders_count > 0, 404);
        if ($kind === 'manual') {
            $account = DB::table('orders')->leftJoin('users as creators', 'creators.id', '=', 'orders.created_by')
                ->where('orders.id', $referenceId)->select('orders.slug')
                ->selectRaw("COALESCE(NULLIF(TRIM(orders.store_name), ''), NULLIF(TRIM(creators.name), ''), orders.reference) AS name")->first();
        } else {
            $account = User::query()->whereKey($referenceId)->where('role', UserRole::Customer)->firstOrFail(['slug', 'name']);
        }
        $summary = $this->summary($account->slug, $account->name, $kind, (int) $counts->orders_count, (int) $counts->parcel_count, $date);

        return compact('query', 'summary');
    }

    /** @param Builder<Order> $query */
    public function orders(Builder $query, int $total, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $query->select([
            'id', 'slug', 'customer_id', 'created_by', 'rider_id', 'reference', 'store_name', 'recipient_name', 'recipient_phone',
            'pickup_date', 'pickup_from', 'pickup_to', 'pickup_address', 'pickup_street_number', 'pickup_postal_code', 'pickup_city',
            'delivery_address', 'delivery_street_number', 'delivery_postal_code', 'delivery_city', 'delivery_zone', 'parcel_count', 'status', 'created_at',
        ])->withDisplayIdentity()->with('rider:id,slug,name')
            ->orderBy('pickup_from')->orderBy('created_at')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page, $total);
    }

    private function summary(string $slug, string $name, string $kind, int $ordersCount, int $parcelCount, Carbon $date): stdClass
    {
        return (object) [
            'slug' => $slug, 'name' => $name, 'kind' => $kind, 'orders_count' => $ordersCount, 'parcel_count' => $parcelCount,
            'orders_url' => route('pickups.account-orders', ['account' => $slug, 'date' => $date->toDateString(), 'kind' => $kind]),
        ];
    }
}
