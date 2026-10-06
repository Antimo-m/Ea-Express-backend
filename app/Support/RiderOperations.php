<?php

namespace App\Support;

use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\OrderEvent;
use App\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RiderOperations
{
    public function query(Carbon $date, ?int $riderId = null, ?string $zone = null): Builder
    {
        return Order::query()->where('pickup_date', '>=', $date->toDateString())->where('pickup_date', '<', $date->copy()->addDay()->toDateString())
            ->when($riderId, fn ($query) => $query->where(fn ($assignment) => $assignment->where('rider_id', $riderId)->orWhereHas('events', fn ($events) => $events->where('rider_id', $riderId))->orWhereIn('id', EconomicAudit::where('entity_type', 'orders')->where('action', 'rider.assigned')->where(fn ($audit) => $audit->where('before->rider_id', $riderId)->orWhere('after->rider_id', $riderId))->select('entity_id'))))
            ->when($zone, fn ($query) => $query->where(fn ($zones) => $zones->whereHas('events', fn ($events) => $events->where('status', OrderStatus::Delivered)->where('operational_zone', $zone))->orWhere(fn ($current) => $current->whereDoesntHave('events', fn ($events) => $events->where('status', OrderStatus::Delivered)->whereNotNull('operational_zone'))->whereRaw("COALESCE(NULLIF(TRIM(delivery_zone), ''), NULLIF(TRIM(delivery_city), ''), 'Zona non specificata') = ?", [$zone]))));
    }

    /** @return array<string, mixed> */
    public function day(Carbon $date, ?int $selectedRiderId = null): array
    {
        $orders = $this->query($date, $selectedRiderId)->select(['id', 'slug', 'reference', 'rider_id', 'status', 'delivery_zone', 'delivery_city', 'pickup_date', 'delivered_at', 'estimated_at', 'estimated_delivery_to', 'price_cents', 'shipping_type', 'assigned_at'])
            ->withSum('payments as cash_cents', 'amount_cents')
            ->withSum('payments as retained_cents', DB::raw('COALESCE(ea_amount_cents, amount_cents)'))
            ->with(['rider:id,slug,name', 'events' => fn ($events) => $events->whereIn('status', [OrderStatus::Rescheduled, OrderStatus::Delivered])->latest('created_at')->orderByDesc('id')->limit(2)])
            ->orderBy('id')->get();
        foreach ($orders as $order) {
            $order->setRelation('events', $order->events->sortBy('id')->values());
        }
        $zones = $orders->groupBy(fn ($order) => $this->zone($order))->map(fn ($items, $zone) => $this->group($items, (string) $zone, 'zone', $date))->sortBy('name')->values();
        $riders = $orders->groupBy('rider_id')->map(fn ($items) => $this->group($items, $items->first()->rider?->name ?? 'Da assegnare', 'rider', $date))->sortBy('name')->values();
        $summary = $this->counts($orders);
        $summary['riders'] = $orders->pluck('rider_id')->filter()->unique()->count();
        $summary['zones'] = $zones->count();
        $summary['unassigned'] = $orders->whereNull('rider_id')->whereNotIn('status', [OrderStatus::Delivered, OrderStatus::Rejected, OrderStatus::Cancelled])->count();
        $moved = OrderEvent::query()->where('schedule_change->before->pickup_date', $date->toDateString())->with(['user:id,slug,name', 'order:id,slug,reference'])->orderByDesc('id')->limit(20)->get();
        $scheduleHistory = $moved->map(fn ($event) => ['reference' => $event->order?->reference, 'url' => $event->order ? route('orders.show', $event->order) : null, 'before' => $event->schedule_change['before'], 'after' => $event->schedule_change['after'], 'at' => $event->created_at->toIso8601String(), 'operator' => $event->user?->name, 'reason' => $event->note])->all();

        return ['date' => $date->toDateString(), 'today' => $date->isSameDay(now('Europe/Rome')), 'summary' => $summary, 'zones' => $zones->all(), 'riders' => $riders->all(), 'schedule_history' => $scheduleHistory, 'orders' => $orders, 'generated_at' => now()->toIso8601String()];
    }

    /** @return array<string, int> */
    private function counts(Collection $orders): array
    {
        $delivered = $orders->where('status', OrderStatus::Delivered)->count();

        return ['total' => $orders->count(), 'delivered' => $delivered, 'undelivered' => $orders->count() - $delivered, 'active' => $orders->whereNotIn('status', [OrderStatus::Delivered, OrderStatus::Cancelled, OrderStatus::Rejected])->count(), 'closed_unfulfilled' => $orders->whereIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->count(), 'overdue' => $orders->filter(fn ($order) => app(DeliveryDeadline::class)->forOrder($order)['overdue'])->count()];
    }

    /** @return array<string, mixed> */
    private function group(Collection $orders, string $name, string $kind, Carbon $date): array
    {
        $rider = $orders->first()->rider;
        $riders = $orders->pluck('rider')->filter()->unique('id')->map(fn ($person) => ['slug' => $person->slug, 'name' => $person->name])->values()->all();
        $zones = $orders->countBy(fn ($order) => $this->zone($order))->map(fn ($count, $zone) => ['name' => (string) $zone, 'count' => $count])->values()->all();
        $counts = $this->counts($orders);

        return [...$counts, 'key' => $kind === 'zone' ? $name : ($rider?->slug ?? 'unassigned'), 'name' => $name, 'slug' => $kind === 'rider' ? $rider?->slug : null, 'state' => $counts['active'] ? 'active' : ($counts['delivered'] ? 'completed' : 'waiting'), 'label' => $counts['active'] ? 'Ordini in lavorazione' : ($counts['delivered'] ? 'Consegne completate' : 'Nessun ordine in lavorazione'), 'riders' => $riders, 'zones' => $zones, 'cash_cents' => (int) $orders->sum('cash_cents'), 'retained_cents' => (int) $orders->sum('retained_cents'), 'expected_cents' => (int) $orders->sum('price_cents'), 'missing_prices' => $orders->whereNull('price_cents')->count(), 'url' => $kind === 'rider' && $rider ? route('rider-operations.show', ['rider' => $rider->slug, 'date' => $date->toDateString()]) : null];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function activity(Collection $orders, ?int $selectedRiderId = null): Collection
    {
        $audits = EconomicAudit::where('entity_type', 'orders')->where('action', 'rider.assigned')->whereIn('entity_id', $orders->modelKeys())->orderBy('created_at')->orderBy('id')->get()->groupBy('entity_id');
        $activity = collect();
        foreach ($orders as $order) {
            foreach ($order->events as $event) {
                $riderId = $event->rider_id;
                if (! $riderId) {
                    $history = $audits->get($order->id, collect());
                    $assignment = $history->last(fn ($audit) => $audit->created_at->lte($event->created_at));
                    $riderId = $assignment ? ($assignment->after['rider_id'] ?? null) : ($history->first()?->before['rider_id'] ?? null);
                    if (! $riderId && $history->isEmpty() && $order->assigned_at?->lte($event->created_at)) {
                        $riderId = $order->rider_id;
                    }
                }
                if ($riderId && (! $selectedRiderId || $riderId === $selectedRiderId)) {
                    $activity->push(['rider_id' => (int) $riderId, 'zone' => $event->operational_zone ?: $this->zone($order), 'event' => $event, 'order' => $order]);
                }
            }
        }

        return $activity->sortBy(fn ($item) => $item['event']->created_at->format('Y-m-d H:i:s').sprintf('%012d', $item['event']->id))->values();
    }

    public function zone(Order $order): string
    {
        $recordedZone = $order->relationLoaded('events') ? $order->events->last(fn ($event) => $event->status === OrderStatus::Delivered)?->operational_zone : null;

        return trim($recordedZone ?: $order->delivery_zone ?: $order->delivery_city ?: 'Zona non specificata');
    }
}
