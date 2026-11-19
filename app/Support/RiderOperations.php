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

class RiderOperations
{
    public const array DailyStates = [
        'todo' => [OrderStatus::Received, OrderStatus::Accepted, OrderStatus::PickupScheduled, OrderStatus::Rescheduled],
        'active' => [OrderStatus::RiderArriving, OrderStatus::PickedUp, OrderStatus::InTransit, OrderStatus::OutForDelivery],
        'completed' => [OrderStatus::Delivered],
        'closed' => [OrderStatus::Cancelled, OrderStatus::Rejected],
    ];

    public function dailyWorkload(Carbon $date, int $riderId, ?string $zone = null, ?Carbon $end = null): Builder
    {
        return $this->assignedQuery(null, $zone)->where('pickup_date', '>=', $date->toDateString())->where('pickup_date', '<', ($end ?? $date)->copy()->addDay()->toDateString())
            ->where(fn ($orders) => $orders->where(fn ($current) => $current->where('rider_id', $riderId)->where('status', '!=', OrderStatus::Delivered))
                ->orWhere(fn ($delivered) => $this->attributedToRider($delivered->where('status', OrderStatus::Delivered), $riderId)));
    }

    /**
     * Ordini attribuiti al Rider: quello dell'ultimo evento di consegna o, se l'evento non indica un Rider, l'assegnatario.
     *
     * L'ultimo evento si legge ordine per ordine dall'indice (stessa scelta di Order::deliveryEvent: data, poi id),
     * invece di raggruppare tutti gli eventi di consegna dell'archivio a ogni interrogazione.
     */
    private function attributedToRider(Builder $query, int $riderId): Builder
    {
        $latest = self::deliveryEventColumn('rider_id');

        return $query->where(fn ($orders) => $orders->whereRaw("({$latest}) = ?", [$riderId])
            ->orWhere(fn ($legacy) => $legacy->where('orders.rider_id', $riderId)->whereRaw("({$latest}) IS NULL")));
    }

    /**
     * Colonna dell'ultimo evento di consegna dell'ordine (stessa definizione della relazione Order::deliveryEvent).
     */
    private static function deliveryEventColumn(string $column): string
    {
        return "SELECT delivery.{$column} FROM order_events AS delivery WHERE delivery.order_id = orders.id AND delivery.status = '"
            .OrderStatus::Delivered->value."' AND delivery.delay_action IS NULL ORDER BY delivery.created_at DESC, delivery.id DESC LIMIT 1";
    }

    public function filterDailyState(Builder $query, string $state): Builder
    {
        if ($state === 'issues') {
            return $query->whereIn('status', [OrderStatus::DeliveryIssue, OrderStatus::DeliveryAttempted]);
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
        $counts['issues'] = (int) ($statuses[OrderStatus::DeliveryIssue->value] ?? 0) + (int) ($statuses[OrderStatus::DeliveryAttempted->value] ?? 0);
        $counts['valid'] = $counts['total'] - $counts['closed'];
        $counts['cancelled'] = (int) ($statuses[OrderStatus::Cancelled->value] ?? 0);
        $counts['progress'] = $counts['valid'] ? (int) round($counts['completed'] / $counts['valid'] * 100) : 0;

        return $counts;
    }

    /** @return array<string, int> */
    public function dailyAccounting(ReportingPeriod $period, int $riderId, Builder $workload, ?string $zone = null): array
    {
        // Si parte dai movimenti del periodo e si verifica l'attribuzione del loro ordine, invece di elencare tutti gli
        // ordini mai attribuiti al Rider (insieme che cresce con l'archivio).
        $attributed = fn (Builder $orders): Builder => $this->attributedToRider($this->assignedConstraints($orders, null, $zone), $riderId);
        $payments = PaymentEntry::whereHas('order', $attributed)->whereBetween('created_at', $period->utcRange());
        $totals = app(ReceiptLedger::class)->totals($payments);
        $expenses = Expense::whereNull('voided_at')->where('spent_on', '>=', $period->start->toDateString())->where('spent_on', '<', $period->end->copy()->addDay()->toDateString())->where(fn ($expense) => $expense->where('user_id', $riderId)->orWhereHas('pendingSettlement.account', fn ($account) => $account->whereHas('order', $attributed)));
        $spent = (int) $expenses->sum('amount_cents');
        $pending = PendingAccount::whereHas('order', $attributed)->where('occurred_on', '>=', $period->start->toDateString())->where('occurred_on', '<', $period->end->copy()->addDay()->toDateString())->whereIn('state', ['open', 'partially_paid']);
        $open = (clone $pending)->toBase()->selectRaw("COALESCE(SUM(CASE WHEN direction = 'incoming' THEN amount_cents - settled_cents ELSE 0 END), 0) AS incoming, COALESCE(SUM(CASE WHEN direction = 'outgoing' THEN amount_cents - settled_cents ELSE 0 END), 0) AS outgoing")->first();
        $incoming = (int) $open->incoming;
        $outgoing = (int) $open->outgoing;
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
        return $this->assignedConstraints(Order::query(), $riderId, $zone);
    }

    private function assignedConstraints(Builder $query, ?int $riderId, ?string $zone): Builder
    {
        return $query
            ->when($riderId, fn ($query) => $query->where(fn ($assignment) => $assignment->where('rider_id', $riderId)->orWhereHas('events', fn ($events) => $events->where('rider_id', $riderId))->orWhereIn('id', EconomicAudit::where('entity_type', 'orders')->where('action', 'rider.assigned')->where(fn ($audit) => $audit->where('before->rider_id', $riderId)->orWhere('after->rider_id', $riderId))->select('entity_id'))))
            ->when($zone, fn ($query) => $query->where(fn ($zones) => $zones->whereHas('events', fn ($events) => $events->whereNull('delay_action')->where('status', OrderStatus::Delivered)->where('operational_zone', $zone))->orWhere(fn ($current) => $current->whereDoesntHave('events', fn ($events) => $events->whereNull('delay_action')->where('status', OrderStatus::Delivered)->whereNotNull('operational_zone'))->whereRaw("COALESCE(NULLIF(TRIM(delivery_zone), ''), NULLIF(TRIM(delivery_city), ''), 'Zona non specificata') = ?", [$zone]))));
    }

    /** @return array<string, mixed> */
    public function day(Carbon $date, ?int $selectedRiderId = null, ?Carbon $end = null, ?string $zone = null): array
    {
        $period = new ReportingPeriod($date, ($end ?? $date)->copy()->endOfDay());
        $reference = $period->reference();
        $summary = $this->emptyCounts();
        $zones = [];
        $riders = [];
        $assignedRiders = [];
        $links = new RiderLinks(['from' => $period->start->toDateString(), 'to' => $period->end->toDateString(), 'period' => 'custom']);
        // Righe semplici invece di modelli: sui periodi lunghi l'idratazione di ordini ed eventi costava secondi.
        $this->metadata($this->query($date, $selectedRiderId, $zone, $period->end))->chunkById(5000, function (Collection $orders) use (&$summary, &$zones, &$riders, &$assignedRiders, $links): void {
            $links->load($orders->pluck('rider_id')->filter()->unique()->all());
            foreach ($orders as $order) {
                $this->countOrder($summary, $order->status, $order->rider_id);
                if ($order->rider_id) {
                    $assignedRiders[$order->rider_id] = true;
                }
                $rider = $order->rider_id ? $links->rider($order->rider_id) : null;
                $zoneName = self::zoneName($order->recorded_zone, $order->delivery_zone, $order->delivery_city);
                $this->accumulateGroup($zones, $zoneName, $zoneName, 'zone', $order, $rider, $zoneName, $links);
                $this->accumulateGroup($riders, (string) ($order->rider_id ?? 'unassigned'), $rider['name'] ?? 'Da assegnare', 'rider', $order, $rider, $zoneName, $links);
            }
        }, 'orders.id', 'id');
        $zones = $this->finalizeGroups($zones);
        $riders = $this->finalizeGroups($riders);
        $summary['riders'] = count($assignedRiders);
        $summary['zones'] = count($zones);
        $moved = OrderEvent::query()->where('rescheduled_from', '>=', $date->toDateString())->where('rescheduled_from', '<=', $period->end->toDateString())->with(['user:id,slug,name', 'order:id,slug,reference'])->orderByDesc('id')->limit(20)->get();
        $scheduleHistory = $moved->map(fn ($event) => ['reference' => $event->order?->reference, 'url' => $event->order ? route('orders.show', $event->order) : null, 'before' => $event->schedule_change['before'], 'after' => $event->schedule_change['after'], 'at' => $event->created_at->toIso8601String(), 'operator' => $event->user?->name, 'reason' => $event->note])->all();

        return ['date' => $date->toDateString(), 'to' => $period->end->toDateString(), 'reference_at' => $reference->toIso8601String(), 'today' => $date->isSameDay(now('Europe/Rome')), 'summary' => $summary, 'zones' => $zones, 'riders' => $riders, 'schedule_history' => $scheduleHistory, 'generated_at' => now()->toIso8601String()];
    }

    private function metadata(Builder $query): \Illuminate\Database\Query\Builder
    {
        return $query->toBase()->select(['orders.id', 'orders.rider_id', 'orders.status', 'orders.delivery_zone', 'orders.delivery_city', 'orders.price_cents'])
            ->selectRaw('(SELECT SUM('.ReceiptLedger::CashExpression.') FROM payment_entries WHERE payment_entries.order_id = orders.id) AS cash_cents')
            ->selectRaw('(SELECT SUM(COALESCE(ea_amount_cents, amount_cents)) FROM payment_entries WHERE payment_entries.order_id = orders.id) AS retained_cents')
            ->selectRaw('('.self::deliveryEventColumn('operational_zone').') AS recorded_zone');
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return ['total' => 0, 'delivered' => 0, 'undelivered' => 0, 'active' => 0, 'closed_unfulfilled' => 0, 'pending' => 0, 'unassigned' => 0];
    }

    /** @param array<string, mixed> $counts */
    private function countOrder(array &$counts, string $status, ?int $riderId): void
    {
        $closed = in_array($status, OrderStatus::closed(), true);
        $counts['total']++;
        $counts['delivered'] += (int) ($status === OrderStatus::Delivered->value);
        $counts['undelivered'] += (int) ($status !== OrderStatus::Delivered->value);
        $counts['active'] += (int) ! $closed;
        $counts['closed_unfulfilled'] += (int) in_array($status, [OrderStatus::Cancelled->value, OrderStatus::Rejected->value], true);
        $counts['pending'] += (int) ($riderId !== null && ! $closed);
        $counts['unassigned'] += (int) ($riderId === null && ! $closed);
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array{slug: string, name: string, entry: array<string, string>, url: string}|null  $rider
     */
    private function accumulateGroup(array &$groups, string $key, string $name, string $kind, object $order, ?array $rider, string $zone, RiderLinks $links): void
    {
        $groups[$key] ??= [...$this->emptyCounts(), 'key' => $kind === 'zone' ? $name : ($rider['slug'] ?? 'unassigned'), 'name' => $name, 'slug' => $kind === 'rider' ? ($rider['slug'] ?? null) : null, 'riders' => [], 'zones' => [], 'cash_cents' => 0, 'retained_cents' => 0, 'expected_cents' => 0, 'missing_prices' => 0, 'url' => $kind === 'rider' && $rider ? $rider['url'] : null];
        $group = &$groups[$key];
        $this->countOrder($group, $order->status, $order->rider_id);
        $group['cash_cents'] += (int) $order->cash_cents;
        $group['retained_cents'] += (int) $order->retained_cents;
        $group['expected_cents'] += (int) $order->price_cents;
        $group['missing_prices'] += (int) ($order->price_cents === null);
        if ($rider) {
            $group['riders'][$rider['slug']] = $rider['entry'];
        }
        $group['zones'][$zone] = ($group['zones'][$zone] ?? 0) + 1;
    }

    /** @param array<string, array<string, mixed>> $groups @return list<array<string, mixed>> */
    private function finalizeGroups(array $groups): array
    {
        return collect($groups)->map(function (array $group): array {
            $group['state'] = $group['active'] ? 'active' : ($group['delivered'] ? 'completed' : 'waiting');
            $group['label'] = $group['active'] ? 'Ordini in lavorazione' : 'Consegne completate';
            $group['riders'] = array_values($group['riders']);
            $group['zones'] = collect($group['zones'])->map(fn (int $count, string $zone): array => ['name' => $zone, 'count' => $count])->values()->all();

            return $group;
        })->sortBy('name')->values()->all();
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

        return self::zoneName($recordedZone, $order->delivery_zone, $order->delivery_city);
    }

    private static function zoneName(?string $recordedZone, ?string $deliveryZone, ?string $deliveryCity): string
    {
        return trim($recordedZone ?: $deliveryZone ?: $deliveryCity ?: 'Zona non specificata');
    }
}
