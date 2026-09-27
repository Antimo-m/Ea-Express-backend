<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderStatistics;
use App\Support\ReportingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerStatisticsController extends Controller
{
    public function __invoke(Request $request, OrderStatistics $statistics): JsonResponse
    {
        $period = $statistics->period($request);
        $orders = Order::where('customer_id', $request->user()->id);
        $selected = (clone $orders)->whereBetween('created_at', $period->utcRange());
        $summary = array_intersect_key($statistics->summarize($selected, false), array_flip(['total', 'delivered', 'cancelled', 'in_progress', 'completion_percent', 'regional_count', 'external_count', 'gross_cents', 'delivered_spend_cents', 'net_cents', 'missing_values']));
        $days = (int) $period->start->copy()->startOfDay()->diffInDays($period->end->copy()->startOfDay()) + 1;
        $previousPeriod = new ReportingPeriod($period->start->copy()->subDays($days), $period->start->copy()->subSecond());
        $comparison = array_intersect_key($statistics->summarize((clone $orders)->whereBetween('created_at', $previousPeriod->utcRange()), false), $summary);
        $changes = [];
        foreach (['total', 'delivered', 'in_progress', 'cancelled', 'gross_cents', 'delivered_spend_cents', 'net_cents'] as $metric) {
            $changes[$metric] = $comparison[$metric] !== 0 ? round(($summary[$metric] - $comparison[$metric]) * 100 / abs($comparison[$metric]), 1) : null;
        }
        $months = [];
        $now = now('Europe/Rome');
        for ($i = 5; $i >= 0; $i--) {
            $start = $now->copy()->startOfMonth()->subMonths($i);
            $end = $i === 0 ? $now->copy() : $start->copy()->endOfMonth();
            $months[] = ['month' => $start->format('Y-m'), 'shipments' => (clone $orders)->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])->count()];
        }
        $previousStart = $now->copy()->startOfMonth()->subMonth();
        $previousEnd = $previousStart->copy()->day(min($now->day, $previousStart->daysInMonth))->setTime($now->hour, $now->minute, $now->second);
        $previous = (clone $orders)->whereBetween('created_at', [$previousStart->utc(), $previousEnd->utc()])->count();
        $current = $months[5]['shipments'];

        return response()->json([...$summary, 'trend' => array_map(fn (array $point): array => array_intersect_key($point, array_flip(['date', 'shipments', 'delivered', 'gross_cents', 'shipping_cents', 'net_cents'])), $statistics->trend($selected, $period)), 'comparison' => $comparison, 'changes' => $changes, 'previous_from' => $previousPeriod->start->toDateString(), 'previous_to' => $previousPeriod->end->toDateString(), 'from' => $period->start->toDateString(), 'to' => $period->end->toDateString(), 'months' => $months, 'this_month' => $current, 'previous_comparable' => $previous, 'change_percent' => $previous ? round(($current - $previous) * 100 / $previous, 1) : null, 'comparison_note' => 'Mese in corso rispetto agli stessi giorni del mese precedente. I volumi misurano l’utilizzo del servizio.']);
    }
}
