<?php

namespace App\Support;

use App\Models\EconomicAudit;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RiderOperations
{
    public const array DailyStates = [
        'todo' => [OrderStatus::Received, OrderStatus::Accepted, OrderStatus::PickupScheduled, OrderStatus::Rescheduled],
        'active' => [OrderStatus::RiderArriving, OrderStatus::PickedUp, OrderStatus::InTransit, OrderStatus::OutForDelivery],
        'completed' => [OrderStatus::Delivered],
        'closed' => [OrderStatus::Cancelled, OrderStatus::Rejected],
    ];

    public function dailyWorkload(Carbon $date, int $riderId, ?string $zone = null): Builder
    {
        return $this->assignedQuery(null, $zone)->where('pickup_date', '>=', $date->toDateString())->where('pickup_date', '<', $date->copy()->addDay()->toDateString())
            ->where(fn ($orders) => $orders->where(fn ($current) => $current->where('rider_id', $riderId)->where('status', '!=', OrderStatus::Delivered))
                ->orWhere(fn ($delivered) => $this->attributedToRider($delivered->where('status', OrderStatus::Delivered), $riderId)));
    }

    private function attributedToRider(Builder $query, int $riderId): Builder
    {
        return $query->where(fn ($orders) => $orders
            ->whereHas('deliveryEvent', fn ($delivery) => $delivery->where('rider_id', $riderId))
            ->orWhere(fn ($legacy) => $legacy->where('rider_id', $riderId)->whereDoesntHave('deliveryEvent', fn ($delivery) => $delivery->whereNotNull('rider_id'))));
    }

    public function filterDailyState(Builder $query, string $state): Builder
    {
        if ($state === 'issues') {
            return $query->where(fn ($issues) => $issues->whereIn('status', [OrderStatus::DeliveryIssue, OrderStatus::DeliveryAttempted])->orWhere(fn ($delayed) => $delayed->whereNotIn('status', OrderStatus::closed())->whereNotNull('delay_reported_at')));
        }

        return isset(self::DailyStates[$state]) ? $query->whereIn('status', self::DailyStates[$state]) : $query;
    }

    /** @return array<string, int> */
    public function dailyCounts(Builder $query): array
    {
        $statuses = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $counts = ['total' => (int) $statuses->sum()];
        foreach (self::DailyStates as $state => $values) {
            $counts[$state] = array_sum(array_map(fn (OrderStatus $status): int => (int) ($statuses[$status->value] ?? 0), $values));
        }
        $counts['issues'] = $this->filterDailyState(clone $query, 'issues')->count();
        $counts['missing_deadlines'] = (clone $query)->whereNotIn('status', OrderStatus::closed())->whereNull('estimated_at')->whereNull('estimated_delivery_to')->whereDoesntHave('scheduleEvents', fn ($events) => $events->where(fn ($schedule) => $schedule->whereNotNull('schedule_change->after->deadline_at')->orWhereNotNull('schedule_change->after->estimated_at')))->count();
        $counts['progress'] = $counts['total'] ? (int) round($counts['completed'] / $counts['total'] * 100) : 0;

        return $counts;
    }

    /** @return array<string, int> */
    public function dailyAccounting(ReportingPeriod $period, int $riderId, Builder $workload, ?string $zone = null): array
    {
        $attributedOrders = $this->attributedToRider($this->assignedQuery(null, $zone), $riderId);
        $payments = PaymentEntry::whereIn('order_id', (clone $attributedOrders)->select('id'))->whereBetween('created_at', $period->utcRange());
        $totals = app(ReceiptLedger::class)->totals($payments);
        $expenses = Expense::whereNull('voided_at')->where('spent_on', '>=', $period->start->toDateString())->where('spent_on', '<', $period->start->copy()->addDay()->toDateString())->where(fn ($expense) => $expense->where('user_id', $riderId)->orWhereHas('pendingSettlement.account', fn ($account) => $account->whereIn('order_id', (clone $attributedOrders)->select('id'))));
        $spent = (int) $expenses->sum('amount_cents');
        $pending = PendingAccount::whereIn('order_id', (clone $attributedOrders)->select('id'))->where('occurred_on', '>=', $period->start->toDateString())->where('occurred_on', '<', $period->start->copy()->addDay()->toDateString())->whereIn('state', ['open', 'partially_paid']);
        $incoming = (int) (clone $pending)->where('direction', 'incoming')->selectRaw('COALESCE(SUM(amount_cents - settled_cents), 0) AS cents')->value('cents');
        $outgoing = (int) (clone $pending)->where('direction', 'outgoing')->selectRaw('COALESCE(SUM(amount_cents - settled_cents), 0) AS cents')->value('cents');
        $unrecorded = (clone $workload)->where('status', OrderStatus::Delivered)->whereNull('paid_at')->whereNull('receipt_voided_at');
        $expected = (int) (clone $unrecorded)->sum('price_cents');
        $alreadyRecorded = (int) PaymentEntry::whereIn('order_id', (clone $unrecorded)->select('id'))->sum('amount_cents');

        return [
            ...$totals, 'expenses' => $spent, 'balance' => $totals['net'] - $spent,
            'cash_change' => $totals['cash'] - $spent, 'unrecorded' => max(0, $expected - $alreadyRecorded),
            'pending_incoming' => $incoming, 'pending_outgoing' => $outgoing,
            'retained' => (int) (clone $payments)->selectRaw('COALESCE(SUM(COALESCE(ea_amount_cents, amount_cents)), 0) AS cents')->value('cents'),
        ];
    }

    public function query(Carbon $date, ?int $riderId = null, ?string $zone = null, ?Carbon $end = null): Builder
    {
        return $this->assignedQuery($riderId, $zone)->where('pickup_date', '>=', $date->toDateString())->where('pickup_date', '<', ($end ?? $date)->copy()->addDay()->toDateString());
    }

    private function assignedQuery(?int $riderId, ?string $zone): Builder
    {
        return Order::query()
            ->when($riderId, fn ($query) => $query->where(fn ($assignment) => $assignment->where('rider_id', $riderId)->orWhereHas('events', fn ($events) => $events->where('rider_id', $riderId))->orWhereIn('id', EconomicAudit::where('entity_type', 'orders')->where('action', 'rider.assigned')->where(fn ($audit) => $audit->where('before->rider_id', $riderId)->orWhere('after->rider_id', $riderId))->select('entity_id'))))
            ->when($zone, fn ($query) => $query->where(fn ($zones) => $zones->whereHas('events', fn ($events) => $events->whereNull('delay_action')->where('status', OrderStatus::Delivered)->where('operational_zone', $zone))->orWhere(fn ($current) => $current->whereDoesntHave('events', fn ($events) => $events->whereNull('delay_action')->where('status', OrderStatus::Delivered)->whereNotNull('operational_zone'))->whereRaw("COALESCE(NULLIF(TRIM(delivery_zone), ''), NULLIF(TRIM(delivery_city), ''), 'Zona non specificata') = ?", [$zone]))));
    }

    /** @return array<string, mixed> */
    public function day(Carbon $date, ?int $selectedRiderId = null, ?Carbon $end = null, ?string $zone = null): array
    {
        $period = new ReportingPeriod($date, ($end ?? $date)->copy()->endOfDay());
        $reference = $period->reference();
        $orders = $this->metadata($this->query($date, $selectedRiderId, $zone, $period->end), $reference)->orderBy('id')->get();
        $lateIds = $this->lateIds($period, $selectedRiderId, $zone, $missingIds);
        $backlogIds = array_values(array_diff($lateIds, $orders->modelKeys()));
        $backlog = $backlogIds ? $this->metadata(Order::whereIn('id', $backlogIds), $reference)->get() : collect();
        $all = $orders->concat($backlog)->unique('id');
        $zones = $all->groupBy(fn ($order) => $this->zone($order))->map(fn ($items, $name) => $this->group($items, (string) $name, 'zone', $period, $orders, $reference))->sortBy('name')->values();
        $riders = $all->groupBy('rider_id')->map(fn ($items) => $this->group($items, $items->first()->rider?->name ?? 'Da assegnare', 'rider', $period, $orders, $reference))->sortBy('name')->values();
        $summary = $this->counts($orders, $reference);
        $summary['overdue'] = count($lateIds);
        $summary['backlog'] = count($backlogIds);
        $summary['riders'] = $all->pluck('rider_id')->filter()->unique()->count();
        $summary['zones'] = $zones->count();
        $summary['unassigned'] = $orders->whereNull('rider_id')->whereNotIn('status', [OrderStatus::Delivered, OrderStatus::Rejected, OrderStatus::Cancelled])->count();
        $summary['missing_deadlines'] = $this->assignedQuery($selectedRiderId, $zone)->whereNotIn('status', OrderStatus::closed())->where('created_at', '<=', $reference->copy()->utc())->whereNull('estimated_at')->whereNull('estimated_delivery_to')->whereDoesntHave('scheduleEvents', fn ($events) => $events->where(fn ($schedule) => $schedule->whereNotNull('schedule_change->after->deadline_at')->orWhereNotNull('schedule_change->after->estimated_at')))->count();
        $summary['history_unknown'] = $all->filter(fn ($order) => ! app(DeliveryDeadline::class)->forOrder($order, $reference)['history_complete'])->count();
        $moved = OrderEvent::query()->where('schedule_change->before->pickup_date', '>=', $date->toDateString())->where('schedule_change->before->pickup_date', '<=', $period->end->toDateString())->with(['user:id,slug,name', 'order:id,slug,reference'])->orderByDesc('id')->limit(20)->get();
        $scheduleHistory = $moved->map(fn ($event) => ['reference' => $event->order?->reference, 'url' => $event->order ? route('orders.show', $event->order) : null, 'before' => $event->schedule_change['before'], 'after' => $event->schedule_change['after'], 'at' => $event->created_at->toIso8601String(), 'operator' => $event->user?->name, 'reason' => $event->note])->all();

        return ['date' => $date->toDateString(), 'to' => $period->end->toDateString(), 'reference_at' => $reference->toIso8601String(), 'today' => $date->isSameDay(now('Europe/Rome')), 'summary' => $summary, 'zones' => $zones->all(), 'riders' => $riders->all(), 'schedule_history' => $scheduleHistory, 'orders' => $orders, 'generated_at' => now()->toIso8601String()];
    }

    /** @return array<int, int> */
    public function lateIds(ReportingPeriod $period, ?int $riderId = null, ?string $zone = null, ?array &$missingIds = null): array
    {
        $reference = $period->reference();
        $query = $this->assignedQuery($riderId, $zone)
            ->where('created_at', '<=', $reference->copy()->utc())
            ->where(fn ($query) => $query->where('delay_reported_at', '<=', $reference->copy()->utc())->orWhereHas('delayEvents', fn ($events) => $events->where('delay_action', 'reported')->where('created_at', '<=', $reference->copy()->utc())));
        if ($reference->isSameDay(now('Europe/Rome'))) {
            $query->whereNotIn('status', OrderStatus::closed());
        }
        $ids = [];
        $missingIds = [];
        $this->metadata($query, $reference)->chunkById(300, function ($orders) use (&$ids, &$missingIds, $reference): void {
            foreach ($orders as $order) {
                $deadline = app(DeliveryDeadline::class)->forOrder($order, $reference);
                if (! $deadline['at'] && ! in_array($order->status->value, OrderStatus::closed(), true)) {
                    $missingIds[] = $order->id;
                }
                if ($deadline['overdue']) {
                    $ids[] = $order->id;
                }
            }
        });

        return $ids;
    }

    private function metadata(Builder $query, Carbon $reference): Builder
    {
        return $query->select(['id', 'slug', 'reference', 'rider_id', 'status', 'delivery_zone', 'delivery_city', 'pickup_date', 'delivered_at', 'estimated_at', 'estimated_delivery_to', 'delay_reported_at', 'price_cents', 'shipping_type', 'assigned_at', 'created_at', 'updated_at'])
            ->withSum('payments as cash_cents', DB::raw(ReceiptLedger::CashExpression))->withSum('payments as retained_cents', DB::raw('COALESCE(ea_amount_cents, amount_cents)'))
            ->with(['rider:id,slug,name', 'delayEvents', 'scheduleEvents', 'deliveryEvent', 'events' => fn ($events) => $events->where('created_at', '<=', $reference->copy()->utc())->latest('created_at')->orderByDesc('id')->limit(1)]);
    }

    /** @return array<string, int> */
    private function counts(Collection $orders, Carbon $reference): array
    {
        $delivered = $orders->where('status', OrderStatus::Delivered)->count();

        return ['total' => $orders->count(), 'delivered' => $delivered, 'undelivered' => $orders->count() - $delivered, 'active' => $orders->whereNotIn('status', [OrderStatus::Delivered, OrderStatus::Cancelled, OrderStatus::Rejected])->count(), 'closed_unfulfilled' => $orders->whereIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->count(), 'overdue' => $orders->filter(fn ($order) => app(DeliveryDeadline::class)->forOrder($order, $reference)['overdue'])->count()];
    }

    /** @return array<string, mixed> */
    private function group(Collection $all, string $name, string $kind, ReportingPeriod $period, Collection $programmed, Carbon $reference): array
    {
        $orders = $all->whereIn('id', $programmed->modelKeys());
        $rider = $all->first()->rider;
        $riders = $all->pluck('rider')->filter()->unique('id')->map(fn ($person) => ['slug' => $person->slug, 'name' => $person->name])->values()->all();
        $zones = $all->countBy(fn ($order) => $this->zone($order))->map(fn ($count, $zone) => ['name' => (string) $zone, 'count' => $count])->values()->all();
        $counts = $this->counts($orders, $reference);
        $counts['overdue'] = $this->counts($all, $reference)['overdue'];
        $counts['backlog'] = $counts['overdue'] - $this->counts($orders, $reference)['overdue'];

        return [...$counts, 'key' => $kind === 'zone' ? $name : ($rider?->slug ?? 'unassigned'), 'name' => $name, 'slug' => $kind === 'rider' ? $rider?->slug : null, 'state' => $counts['active'] || $counts['backlog'] ? 'active' : ($counts['delivered'] ? 'completed' : 'waiting'), 'label' => $counts['active'] || $counts['backlog'] ? 'Ordini in lavorazione' : 'Consegne completate', 'riders' => $riders, 'zones' => $zones, 'cash_cents' => (int) $orders->sum('cash_cents'), 'retained_cents' => (int) $orders->sum('retained_cents'), 'expected_cents' => (int) $orders->sum('price_cents'), 'missing_prices' => $orders->whereNull('price_cents')->count(), 'url' => $kind === 'rider' && $rider ? route('rider-operations.show', ['rider' => $rider->slug, 'date' => $period->start->toDateString()]) : null];
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
        $recordedZone = $order->relationLoaded('deliveryEvent') ? $order->deliveryEvent?->operational_zone : ($order->relationLoaded('events') ? $order->events->last(fn ($event) => $event->status === OrderStatus::Delivered)?->operational_zone : null);

        return trim($recordedZone ?: $order->delivery_zone ?: $order->delivery_city ?: 'Zona non specificata');
    }
}
