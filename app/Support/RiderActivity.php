<?php

namespace App\Support;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statistiche operative e tariffarie di un Rider.
 *
 * - «Adesso»: stato attuale degli ordini aperti assegnati al Rider (non dipende dal periodo).
 * - «Nel periodo»: ritiri, consegne e annullamenti contati alla data dell'evento, attribuiti al Rider
 *   registrato sull'evento (o all'assegnatario per gli eventi storici senza Rider). Un ordine assegnato
 *   lunedì e consegnato mercoledì conta come consegna di mercoledì.
 * - Valore tariffario: tariffa di spedizione concordata (`price_cents`), mai il valore della merce né gli
 *   incassi registrati. Le consegne senza tariffa concordata sono contate a parte, senza stime.
 */
class RiderActivity
{
    /** @var array<string, list<OrderStatus>> */
    public const array CurrentStates = [
        'waiting' => [OrderStatus::Accepted, OrderStatus::RiderArriving],
        'scheduled' => [OrderStatus::PickupScheduled],
        'picked' => [OrderStatus::PickedUp],
        'travelling' => [OrderStatus::InTransit, OrderStatus::OutForDelivery],
        'issues' => [OrderStatus::DeliveryAttempted, OrderStatus::DeliveryIssue, OrderStatus::Rescheduled],
    ];

    /** @return array{waiting: int, scheduled: int, picked: int, travelling: int, issues: int, open: int, late: int, open_tariff_cents: int, open_missing_prices: int} */
    public function current(int $riderId): array
    {
        $open = Order::query()->where('rider_id', $riderId)->whereNotIn('status', [...OrderStatus::closed(), OrderStatus::Received->value]);
        $statuses = (clone $open)->toBase()->selectRaw('status, COUNT(*) AS total, COALESCE(SUM(price_cents), 0) AS tariff, SUM(CASE WHEN price_cents IS NULL THEN 1 ELSE 0 END) AS missing')->groupBy('status')->get()->keyBy('status');
        $counts = [];
        foreach (self::CurrentStates as $key => $states) {
            $counts[$key] = array_sum(array_map(fn (OrderStatus $status): int => (int) ($statuses[$status->value]->total ?? 0), $states));
        }
        $now = now();
        $today = now('Europe/Rome')->toDateString();
        // In ritardo: previsione di consegna superata oppure, senza previsione, ritiro di un giorno passato non ancora effettuato.
        $late = (clone $open)->where(fn ($orders) => $orders->where('estimated_at', '<', $now)
            ->orWhere(fn ($pickup) => $pickup->whereNull('estimated_at')->where('pickup_date', '<', $today)->whereIn('status', [OrderStatus::Accepted, OrderStatus::PickupScheduled, OrderStatus::RiderArriving])))->count();

        return [...$counts, 'open' => (int) $statuses->sum('total'), 'late' => $late, 'open_tariff_cents' => (int) $statuses->sum('tariff'), 'open_missing_prices' => (int) $statuses->sum('missing')];
    }

    /**
     * @return array{assigned: int, picked_up: int, delivered: int, cancelled: int, delivered_tariff_cents: int, missing_prices: int,
     *     granularity: string, buckets: list<array{key: string, label: string, deliveries: int, tariff_cents: int}>}
     */
    public function period(int $riderId, ReportingPeriod $period, RiderOperations $operations): array
    {
        $range = $period->utcRange();
        $assigned = $operations->dailyWorkload($period->start, $riderId, end: $period->end)->count();
        $pickedUp = (int) $this->attributedEvents($riderId, OrderStatus::PickedUp, $range)->distinct()->count('event.order_id');
        $cancelled = (int) $this->attributedEvents($riderId, OrderStatus::Cancelled, $range)->distinct()->count('event.order_id');
        $granularity = $this->granularity($period);
        $buckets = $this->emptyBuckets($period, $granularity);
        $delivered = 0;
        $tariff = 0;
        $missing = 0;
        foreach ($this->deliveries($riderId, $range)->select(['event.created_at', 'orders.price_cents'])->orderBy('event.created_at')->cursor() as $row) {
            $key = $this->bucketKey(Carbon::parse($row->created_at, 'UTC')->timezone('Europe/Rome'), $granularity);
            $delivered++;
            $missing += (int) ($row->price_cents === null);
            $tariff += (int) $row->price_cents;
            if (isset($buckets[$key])) {
                $buckets[$key]['deliveries']++;
                $buckets[$key]['tariff_cents'] += (int) $row->price_cents;
            }
        }

        return ['assigned' => $assigned, 'picked_up' => $pickedUp, 'delivered' => $delivered, 'cancelled' => $cancelled, 'delivered_tariff_cents' => $tariff, 'missing_prices' => $missing, 'granularity' => $granularity, 'buckets' => array_values($buckets)];
    }

    /**
     * Consegne del periodo attribuite al Rider: ultimo evento di consegna dell'ordine (come Order::deliveryEvent),
     * registrato nel periodo, su un ordine ancora consegnato. Una sola riga per ordine.
     *
     * @param  array{Carbon, Carbon}  $range
     */
    public function deliveries(int $riderId, array $range): QueryBuilder
    {
        return $this->attributedEvents($riderId, OrderStatus::Delivered, $range)
            ->where('orders.status', OrderStatus::Delivered->value)
            ->whereNotExists(fn ($later) => $later->selectRaw('1')->from('order_events AS later')->whereColumn('later.order_id', 'event.order_id')
                ->where('later.status', OrderStatus::Delivered->value)->whereNull('later.delay_action')
                ->where(fn ($after) => $after->whereColumn('later.created_at', '>', 'event.created_at')
                    ->orWhere(fn ($same) => $same->whereColumn('later.created_at', 'event.created_at')->whereColumn('later.id', '>', 'event.id'))));
    }

    /**
     * Eventi di uno stato registrati nel periodo e attribuiti al Rider: quello indicato sull'evento oppure,
     * per gli eventi storici senza Rider, l'assegnatario dell'ordine.
     *
     * @param  array{Carbon, Carbon}  $range
     */
    private function attributedEvents(int $riderId, OrderStatus $status, array $range): QueryBuilder
    {
        return DB::table('order_events AS event')->join('orders', 'orders.id', '=', 'event.order_id')
            ->where('event.status', $status->value)->whereNull('event.delay_action')
            ->whereBetween('event.created_at', $range)
            ->where(fn ($rider) => $rider->where('event.rider_id', $riderId)->orWhere(fn ($legacy) => $legacy->whereNull('event.rider_id')->where('orders.rider_id', $riderId)));
    }

    /** Giorni fino a due mesi, mesi fino a tre anni, poi anni. */
    private function granularity(ReportingPeriod $period): string
    {
        $days = (int) $period->start->copy()->startOfDay()->diffInDays($period->end->copy()->startOfDay()) + 1;

        return match (true) {
            $days <= 62 => 'day',
            $days <= 1096 => 'month',
            default => 'year',
        };
    }

    private function bucketKey(Carbon $date, string $granularity): string
    {
        return match ($granularity) {
            'day' => $date->format('Y-m-d'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y'),
        };
    }

    /** @return array<string, array{key: string, label: string, deliveries: int, tariff_cents: int}> */
    private function emptyBuckets(ReportingPeriod $period, string $granularity): array
    {
        $buckets = [];
        $cursor = $period->start->copy()->locale('it');
        while ($cursor->lte($period->end)) {
            $key = $this->bucketKey($cursor, $granularity);
            $buckets[$key] ??= ['key' => $key, 'label' => match ($granularity) {
                'day' => $cursor->translatedFormat('D j M'),
                'month' => ucfirst($cursor->translatedFormat('M Y')),
                default => $cursor->format('Y'),
            }, 'deliveries' => 0, 'tariff_cents' => 0];
            $cursor = match ($granularity) {
                'day' => $cursor->addDay(),
                'month' => $cursor->startOfMonth()->addMonthNoOverflow(),
                default => $cursor->startOfYear()->addYear(),
            };
        }

        return $buckets;
    }
}
