<?php

namespace App\Support;

use App\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OrderStatistics
{
    public function period(Request $request): ReportingPeriod
    {
        $data = $request->validate(['period' => ['nullable', 'in:today,week,month,custom'], 'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'], 'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d']]);
        $today = now('Europe/Rome');
        $start = match ($data['period'] ?? 'month') {
            'today' => $today->copy()->startOfDay(),'week' => $today->copy()->startOfWeek(),'custom' => Carbon::parse($data['from'], 'Europe/Rome')->startOfDay(),default => $today->copy()->startOfMonth()
        };
        $end = ($data['period'] ?? null) === 'custom' ? Carbon::parse($data['to'], 'Europe/Rome')->endOfDay() : $today->copy()->endOfDay();
        if ($start->greaterThan($end) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => 'Intervallo non valido: massimo un anno.']);
        }

        return new ReportingPeriod($start, $end);
    }

    /** @return Collection<int, mixed> */
    public function prices(Builder $query, bool $byAccount = false): Collection
    {
        $query = (clone $query)->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->whereNotNull('price_cents');
        if ($byAccount) {
            $query->select('customer_id')->groupBy('customer_id');
        }

        return $query->selectRaw('price_cents, COUNT(*) AS shipments, SUM(price_cents) AS total_cents')->groupBy('price_cents')->orderBy('price_cents')->get();
    }

    /** @return array<string,mixed> */
    public function summarize(Builder $query, bool $includePrices = true): array
    {
        $row = (clone $query)->selectRaw("COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered,
            COALESCE(SUM(CASE WHEN status IN ('cancelled','rejected') THEN 1 ELSE 0 END), 0) AS cancelled,
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','rejected') THEN price_cents ELSE 0 END), 0) AS shipping_spend_cents,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN price_cents ELSE 0 END), 0) AS delivered_spend_cents,
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','rejected') AND price_cents IS NULL THEN 1 ELSE 0 END), 0) AS unpriced,
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','rejected') THEN parcel_value_cents ELSE 0 END), 0) AS parcel_value_cents,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN parcel_value_cents ELSE 0 END), 0) AS gross_cents,
            COALESCE(SUM(CASE WHEN status = 'delivered' AND parcel_value_cents IS NULL THEN 1 ELSE 0 END), 0) AS missing_values,
            COALESCE(SUM(CASE WHEN shipping_type = 'regional' THEN 1 ELSE 0 END), 0) AS regional_count,
            COALESCE(SUM(CASE WHEN shipping_type = 'external' THEN 1 ELSE 0 END), 0) AS external_count")->first();
        $summary = array_map(intval(...), $row->getAttributes());
        $summary['in_progress'] = $summary['total'] - $summary['delivered'] - $summary['cancelled'];
        $summary['completion_percent'] = $summary['total'] ? round(100 * $summary['delivered'] / $summary['total'], 1) : 0;
        $summary['net_cents'] = $summary['gross_cents'] - $summary['delivered_spend_cents'];
        if ($includePrices) {
            $summary['prices'] = $this->prices($query);
        }

        return $summary;
    }

    /** @return list<array<string,mixed>> */
    public function trend(Builder $query, ReportingPeriod $period): array
    {
        $days = [];
        for ($day = $period->start->copy(); $day->lte($period->end); $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'shipments' => 0, 'delivered' => 0, 'value_cents' => 0, 'gross_cents' => 0, 'shipping_cents' => 0, 'net_cents' => 0];
        }
        $hourExpression = match ($query->getConnection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', created_at)",
            default => "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')",
        };
        $rows = (clone $query)->whereBetween('created_at', $period->utcRange())->reorder()->selectRaw("{$hourExpression} AS utc_hour, COUNT(*) AS shipments,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','rejected') THEN parcel_value_cents ELSE 0 END), 0) AS value_cents,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN parcel_value_cents ELSE 0 END), 0) AS gross_cents,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN price_cents ELSE 0 END), 0) AS shipping_cents")
            ->groupByRaw($hourExpression)->toBase()->get();
        foreach ($rows as $row) {
            $key = Carbon::parse($row->utc_hour, 'UTC')->timezone('Europe/Rome')->toDateString();
            foreach (['shipments', 'delivered', 'value_cents', 'gross_cents', 'shipping_cents'] as $metric) {
                $days[$key][$metric] += (int) $row->$metric;
            }
            $days[$key]['net_cents'] += (int) $row->gross_cents - (int) $row->shipping_cents;
        }

        return array_values($days);
    }
}
