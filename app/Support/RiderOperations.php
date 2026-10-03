<?php

namespace App\Support;

use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RiderOperations
{
    public function __construct(private RiderTracking $tracking) {}

    /** @return array<string, mixed> */
    public function day(Carbon $date, bool $includePayments = false, ?int $selectedRiderId = null): array
    {
        $period = new ReportingPeriod($date->copy()->startOfDay(), $date->copy()->endOfDay());
        $today = $date->isSameDay(now('Europe/Rome'));
        $orders = Order::query()->where(function ($query) use ($period, $today, $date): void {
            $query->whereBetween('delivered_at', $period->utcRange())
                ->orWhereIn('id', OrderEvent::select('order_id')->whereBetween('created_at', $period->utcRange()));
            if ($today) {
                $query->orWhere(fn ($unassigned) => $unassigned->whereNull('rider_id')->whereNotIn('status', OrderStatus::closed())->whereDate('pickup_date', '<=', $date->toDateString()));
                $query->orWhere(fn ($active) => $active->whereNotNull('rider_id')->whereNull('carrier_handed_at')->whereNotIn('status', [...OrderStatus::closed(), 'received']));
            }
        })->when($selectedRiderId, function ($query) use ($selectedRiderId): void {
            $query->where(function ($assignment) use ($selectedRiderId): void {
                $assignment->where('rider_id', $selectedRiderId)
                    ->orWhereHas('events', fn ($events) => $events->where(fn ($identity) => $identity->where('rider_id', $selectedRiderId)->orWhere('user_id', $selectedRiderId)))
                    ->orWhereIn('id', EconomicAudit::where('entity_type', 'orders')->where('action', 'rider.assigned')->where(fn ($audit) => $audit->where('before->rider_id', $selectedRiderId)->orWhere('after->rider_id', $selectedRiderId))->select('entity_id'));
            });
        })->withSum('payments as cash_cents', 'amount_cents')->with(['rider:id,name,role,is_active,email_verified_at', 'events' => fn ($events) => $events->whereBetween('created_at', $period->utcRange())->with('user:id,name,role')->orderBy('created_at')->orderBy('id')])->when($includePayments, fn ($query) => $query->withDisplayIdentity()->with(['payments' => fn ($payments) => $payments->orderBy('created_at')->orderBy('id'), 'payments.user:id,name']))->orderBy('id')->get();
        $audits = EconomicAudit::where('entity_type', 'orders')->where('action', 'rider.assigned')->whereIn('entity_id', $orders->modelKeys())->orderBy('created_at')->orderBy('id')->get()->groupBy('entity_id');
        $riders = User::whereIn('role', [UserRole::Rider, UserRole::Admin])->orderBy('name')->get(['id', 'name', 'is_active', 'email_verified_at', 'role'])->keyBy('id');
        foreach ($orders as $order) {
            if ($order->rider && ! $riders->has($order->rider_id)) {
                $riders->put($order->rider_id, $order->rider);
            }
        }
        $activity = collect();
        foreach ($orders as $order) {
            foreach ($order->events as $event) {
                if (! $event->created_at->copy()->timezone('Europe/Rome')->isSameDay($date)) {
                    continue;
                }
                $riderId = $event->rider_id;
                if (! $riderId && ! $event->operational_zone) {
                    $history = $audits->get($order->id, collect());
                    $assignment = $history->last(fn ($audit) => $audit->created_at->lte($event->created_at));
                    $riderId = $assignment ? ($assignment->after['rider_id'] ?? null) : ($history->first()?->before['rider_id'] ?? null);
                    if (! $riderId && $history->isEmpty()) {
                        $riderId = $event->user?->role === UserRole::Rider ? $event->user_id : (($order->assigned_at && $order->assigned_at->lte($event->created_at)) ? $order->rider_id : null);
                    }
                }
                if (! $riderId) {
                    continue;
                }
                $zone = $event->operational_zone ?: $this->zone($order);
                $activity->push(['rider_id' => (int) $riderId, 'zone' => $zone, 'event' => $event, 'order' => $order]);
            }
        }
        $deliveredEvents = $activity->filter(fn ($item) => $item['event']->status === OrderStatus::Delivered)->groupBy(fn ($item) => $item['order']->id)->map(fn ($items) => $items->first());
        $deliveries = collect();
        foreach ($orders as $order) {
            if (! $order->delivered_at || ! $order->delivered_at->copy()->timezone('Europe/Rome')->isSameDay($date)) {
                continue;
            }
            $delivery = $deliveredEvents->get($order->id);
            if ($delivery) {
                $deliveries->push($delivery);
            } elseif ($order->rider_id) {
                $deliveries->push(['rider_id' => (int) $order->rider_id, 'zone' => $this->zone($order), 'event' => null, 'order' => $order]);
            }
        }
        $snapshotsByOrder = $today && $selectedRiderId === null ? $this->tracking->snapshots($orders)->keyBy('order_id') : collect();
        $zones = collect();
        $locations = [];
        if ($today && $selectedRiderId === null) {
            $active = $orders->filter(fn ($order) => $order->rider_id && ! $order->carrier_handed_at && ! in_array($order->status->value, [...OrderStatus::closed(), 'received'], true));
            foreach ($active->groupBy(fn ($order) => $this->zone($order)) as $zone => $zoneOrders) {
                $rows = [];
                foreach ($zoneOrders->groupBy('rider_id') as $riderId => $riderOrders) {
                    $snapshots = $riderOrders->map(function ($order) use ($snapshotsByOrder): array {
                        $snapshot = $snapshotsByOrder->get($order->id);
                        if ($snapshot['state'] === 'live' && Carbon::parse($snapshot['location']['recorded_at'])->lt(now()->subSeconds(90))) {
                            $snapshot['state'] = 'stale';
                        }

                        return $snapshot;
                    });
                    $snapshot = $snapshots->firstWhere('state', 'live') ?? $snapshots->firstWhere('state', 'stale') ?? $snapshots->first();
                    $rows[] = $this->row($riders->get($riderId), (string) $zone, $date, $riderOrders->count(), $deliveries, $snapshot);
                    foreach ($snapshots->where('state', 'live') as $position) {
                        $locations[] = ['rider_id' => (int) $riderId, 'rider_name' => $riders->get($riderId)?->name ?? 'Rider', 'zone' => $zone, 'reference' => $position['reference'], 'order_url' => route('orders.show', $position['order_id']), 'location' => $position['location']];
                    }
                }
                $zones->put($zone, $rows);
            }
            $idle = $riders->where('role', UserRole::Rider)->filter(fn ($rider) => ! $active->contains('rider_id', $rider->id));
            if ($idle->isNotEmpty()) {
                $zones->put('Nessuna consegna in corso', $idle->map(fn ($rider) => $this->row($rider, 'Nessuna consegna in corso', $date, 0, $deliveries, null))->values()->all());
            }
        } elseif ($selectedRiderId === null) {
            foreach ($deliveries->groupBy('zone') as $zone => $items) {
                $zones->put($zone, $items->groupBy('rider_id')->map(fn ($rows, $riderId) => $this->row($riders->get($riderId), (string) $zone, $date, 0, $deliveries, null, true))->values()->all());
            }
        }

        $historicalActive = $activity->groupBy(fn ($item) => $item['rider_id'].':'.$item['order']->id)->map(fn ($items) => $items->last())->filter(fn ($item) => ! in_array($item['event']->status->value, OrderStatus::closed(), true));
        $activeOrders = $today ? $orders->filter(fn ($order) => $order->rider_id && ! $order->carrier_handed_at && ! in_array($order->status->value, [...OrderStatus::closed(), 'received'], true))->toBase() : $historicalActive->pluck('order')->unique('id');
        $dailyRiders = collect();
        $workingIds = ($today ? $activeOrders->pluck('rider_id') : $historicalActive->pluck('rider_id'))->merge($deliveries->pluck('rider_id'))->unique();
        if ($today) {
            $workingIds = $workingIds->merge($riders->where('role', UserRole::Rider)->keys())->unique();
        }
        foreach ($workingIds as $riderId) {
            $completed = $deliveries->where('rider_id', $riderId);
            $assigned = $today ? $activeOrders->where('rider_id', $riderId) : $historicalActive->where('rider_id', $riderId)->pluck('order')->unique('id');
            $dailyOrders = $assigned->merge($completed->pluck('order'))->unique('id');
            $snapshot = $assigned->map(fn ($order) => $snapshotsByOrder->get($order->id))->filter()->sortByDesc(fn ($position) => $position['location']['received_at'] ?? '')->first();
            $row = $this->row($riders->get($riderId), 'Nessuna consegna in corso', $date, $assigned->count(), $deliveries, $snapshot, ! $today);
            $assignedZones = $today ? $assigned->map(fn ($order) => ['id' => $order->id, 'zone' => $this->zone($order)]) : $historicalActive->where('rider_id', $riderId)->map(fn ($item) => ['id' => $item['order']->id, 'zone' => $item['zone']]);
            $zoneCounts = $assignedZones->merge($completed->map(fn ($item) => ['id' => $item['order']->id, 'zone' => $item['zone']]))->unique('id')->countBy('zone');
            $row['zones'] = $zoneCounts->map(fn ($count, $name) => ['name' => (string) $name, 'count' => $count])->values()->all();
            $row['total_count'] = $dailyOrders->count();
            $row['cash_cents'] = (int) $dailyOrders->sum('cash_cents');
            $row['expected_cents'] = (int) $dailyOrders->sum('price_cents');
            $row['missing_prices'] = $dailyOrders->whereNull('price_cents')->count();
            $row['url'] = route('rider-operations.show', ['rider' => $riderId, 'date' => $date->toDateString()]);
            $dailyRiders->push($row);
        }
        if ($today) {
            foreach ($deliveries->groupBy('zone') as $zone => $items) {
                $rows = collect($zones->get($zone, []));
                foreach ($items->groupBy('rider_id') as $riderId => $completed) {
                    if (! $rows->contains('id', $riderId)) {
                        $rows->push($this->row($riders->get($riderId), (string) $zone, $date, 0, $deliveries, null));
                    }
                }
                $zones->put($zone, $rows->all());
            }
        }
        if (! $today) {
            foreach ($historicalActive->groupBy('zone') as $zone => $items) {
                $rows = collect($zones->get($zone, []))->keyBy('id');
                foreach ($items->groupBy('rider_id') as $riderId => $assigned) {
                    $rows->put($riderId, $this->row($riders->get($riderId), (string) $zone, $date, $assigned->count(), $deliveries, null, true));
                }
                $zones->put($zone, $rows->values()->all());
            }
        }
        $unassigned = $today ? $orders->filter(fn ($order) => ! $order->rider_id && ! in_array($order->status->value, OrderStatus::closed(), true)) : collect();
        $unassignedZones = $unassigned->countBy(fn ($order) => $this->zone($order))->map(fn ($count, $zone) => ['name' => (string) $zone, 'count' => $count, 'url' => route('orders.incoming', ['zone' => $zone])])->values()->all();
        $summary = ['total' => $activeOrders->merge($deliveries->pluck('order'))->merge($unassigned)->unique('id')->count(), 'active' => $activeOrders->count(), 'delivered' => $deliveries->count(), 'riders' => $dailyRiders->where('total_count', '>', 0)->count(), 'zones' => $dailyRiders->flatMap(fn ($rider) => array_column($rider['zones'], 'name'))->unique()->count(), 'unassigned' => $unassigned->count()];

        return ['date' => $date->toDateString(), 'today' => $today, 'summary' => $summary, 'riders' => $dailyRiders->sortBy('name')->values()->all(), 'unassigned' => $unassignedZones, 'zones' => $zones->sortKeys()->map(fn ($rows, $zone) => ['name' => (string) $zone, 'riders' => $rows])->values()->all(), 'locations' => $locations, 'legacy_zones' => $deliveries->filter(fn ($item) => ! $item['event']?->operational_zone)->count(), 'map' => ['tiles' => config('tracking.tiles_url'), 'attribution' => config('tracking.tiles_attribution')], 'activity' => $activity, 'deliveries' => $deliveries, 'orders' => $orders, 'generated_at' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function row(?User $rider, string $zone, Carbon $date, int $activeCount, Collection $deliveries, ?array $snapshot, bool $historical = false): array
    {
        $state = $historical ? 'completed' : ($rider && ! $rider->is_active ? 'offline' : ($snapshot['state'] ?? 'waiting'));
        $labels = ['live' => 'In consegna · GPS aggiornato', 'stale' => 'Offline · GPS non aggiornato', 'locating' => 'In attesa del GPS', 'ready' => 'Tracking non disponibile', 'waiting' => $activeCount ? 'In attesa · ordini assegnati' : 'Nessuna consegna in corso', 'offline' => 'Offline', 'completed' => 'Attività registrata', 'carrier' => 'Affidato al corriere'];

        return ['id' => $rider?->id, 'name' => $rider?->name ?? 'Rider non disponibile', 'state' => $state, 'label' => $labels[$state] ?? 'In attesa', 'active_count' => $activeCount, 'delivered_count' => $deliveries->where('rider_id', $rider?->id)->when($zone !== 'Nessuna consegna in corso', fn ($items) => $items->where('zone', $zone))->count(), 'last_gps' => $snapshot['location']['received_at'] ?? null, 'last_recorded_gps' => $snapshot['location']['recorded_at'] ?? null, 'url' => $rider ? route('rider-operations.show', ['rider' => $rider->id, 'date' => $date->toDateString(), ...($zone !== 'Nessuna consegna in corso' ? ['zone' => $zone] : [])]) : null];
    }

    public function zone(Order $order): string
    {
        return trim($order->delivery_zone ?: $order->delivery_city ?: 'Zona non specificata');
    }
}
