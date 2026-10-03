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

    public function staffPeriod(Request $request): ReportingPeriod
    {
        $data = $request->validate(['period' => ['nullable', 'in:today,week,month,year,custom'], 'year' => ['required_if:period,year', 'nullable', 'integer', 'between:2000,2100'], 'month' => ['nullable', 'date_format:Y-m']]);
        if (($data['period'] ?? null) === 'year') {
            return ReportingPeriod::year((int) $data['year']);
        }
        if (($data['period'] ?? 'month') === 'month' && ! empty($data['month'])) {
            return ReportingPeriod::month($data['month']);
        }

        return $this->period($request);
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
    public function trend(Builder $query, ReportingPeriod $period, string $dateBasis = 'created_at'): array
    {
        $days = [];
        for ($day = $period->start->copy(); $day->lte($period->end); $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'shipments' => 0, 'delivered' => 0, 'cancelled' => 0, 'regional_count' => 0, 'external_count' => 0, 'missing_values' => 0, 'missing_prices' => 0, 'value_cents' => 0, 'gross_cents' => 0, 'shipping_cents' => 0, 'net_cents' => 0];
        }
        $dateExpression = $dateBasis === 'activity' ? "CASE WHEN status = 'delivered' THEN delivered_at ELSE created_at END" : 'created_at';
        $hourExpression = match ($query->getConnection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', {$dateExpression})",
            default => "DATE_FORMAT({$dateExpression}, '%Y-%m-%d %H:00:00')",
        };
        $rows = (clone $query)->whereBetween($query->getConnection()->raw($dateExpression), $period->utcRange())->reorder()->selectRaw("{$hourExpression} AS utc_hour, COUNT(*) AS shipments,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
            SUM(CASE WHEN status IN ('cancelled','rejected') THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN shipping_type = 'regional' THEN 1 ELSE 0 END) AS regional_count,
            SUM(CASE WHEN shipping_type = 'external' THEN 1 ELSE 0 END) AS external_count,
            SUM(CASE WHEN status = 'delivered' AND parcel_value_cents IS NULL THEN 1 ELSE 0 END) AS missing_values,
            SUM(CASE WHEN status = 'delivered' AND price_cents IS NULL THEN 1 ELSE 0 END) AS missing_prices,
            COALESCE(SUM(CASE WHEN status NOT IN ('cancelled','rejected') THEN parcel_value_cents ELSE 0 END), 0) AS value_cents,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN parcel_value_cents ELSE 0 END), 0) AS gross_cents,
            COALESCE(SUM(CASE WHEN status = 'delivered' THEN price_cents ELSE 0 END), 0) AS shipping_cents")
            ->groupByRaw($hourExpression)->toBase()->get();
        foreach ($rows as $row) {
            $key = Carbon::parse($row->utc_hour, 'UTC')->timezone('Europe/Rome')->toDateString();
            foreach (['shipments', 'delivered', 'cancelled', 'regional_count', 'external_count', 'missing_values', 'missing_prices', 'value_cents', 'gross_cents', 'shipping_cents'] as $metric) {
                $days[$key][$metric] += (int) $row->$metric;
            }
            $days[$key]['net_cents'] += (int) $row->gross_cents - (int) $row->shipping_cents;
        }

        return array_values($days);
    }

    /** @return array<string,mixed> */
    public function customerReport(Builder $orders, ReportingPeriod $period, string $dateBasis = 'created_at'): array
    {
        $points = $this->trend($orders, $period, $dateBasis);
        $summary = [];
        foreach (['total' => 'shipments', 'delivered' => 'delivered', 'cancelled' => 'cancelled', 'regional_count' => 'regional_count', 'external_count' => 'external_count', 'gross_cents' => 'gross_cents', 'delivered_spend_cents' => 'shipping_cents', 'net_cents' => 'net_cents', 'missing_values' => 'missing_values', 'missing_prices' => 'missing_prices'] as $metric => $field) {
            $summary[$metric] = array_sum(array_column($points, $field));
        }
        $summary['in_progress'] = $summary['total'] - $summary['delivered'] - $summary['cancelled'];
        $summary['completion_percent'] = $summary['total'] ? round(100 * $summary['delivered'] / $summary['total'], 1) : 0;
        $months = [];
        foreach ($points as $point) {
            $month = substr($point['date'], 0, 7);
            $months[$month] ??= ['month' => $month, 'shipments' => 0, 'delivered' => 0, 'net_cents' => 0];
            foreach (['shipments', 'delivered', 'net_cents'] as $field) {
                $months[$month][$field] += $point[$field];
            }
        }
        $orderPeak = collect($points)->where('shipments', '>', 0)->sortByDesc('shipments')->first();
        $revenuePeak = collect($points)->where('delivered', '>', 0)->sortByDesc('net_cents')->first();
        $monthPeak = collect($months)->where('shipments', '>', 0)->sortByDesc('shipments')->first();
        $chartSummaries = [
            'orders' => ['total' => $summary['total'], 'peak' => $orderPeak ? ['date' => $orderPeak['date'], 'value' => $orderPeak['shipments']] : null],
            'revenue' => ['total' => $summary['net_cents'], 'peak' => $revenuePeak ? ['date' => $revenuePeak['date'], 'value' => $revenuePeak['net_cents']] : null],
            'months' => ['total' => $summary['total'], 'peak' => $monthPeak ? ['date' => $monthPeak['month'], 'value' => $monthPeak['shipments']] : null],
        ];

        return ['summary' => $summary, 'trend' => array_map(fn (array $point): array => array_intersect_key($point, array_flip(['date', 'shipments', 'delivered', 'gross_cents', 'shipping_cents', 'net_cents'])), $points), 'months' => array_values($months), 'chart_summaries' => $chartSummaries];
    }
}
