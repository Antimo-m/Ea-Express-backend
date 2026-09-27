<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\OrderStatus;
use App\Support\OrderStatistics;
use App\Support\ReportingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerStatisticsController extends Controller
{
    public function __invoke(Request $request, OrderStatistics $statistics): JsonResponse
    {
        $request->validate(['tariff' => ['nullable', 'integer', 'min:0'], 'detail_page' => ['nullable', 'integer', 'min:1']]);
        $period = $statistics->period($request);
        $orders = Order::where('customer_id', $request->user()->id);
        $selected = (clone $orders)->whereBetween('created_at', $period->utcRange());
        $summary = $statistics->summarize($selected);
        $days = (int) $period->start->copy()->startOfDay()->diffInDays($period->end->copy()->startOfDay()) + 1;
        $previousPeriod = new ReportingPeriod($period->start->copy()->subDays($days), $period->start->copy()->subSecond());
        $comparison = $statistics->summarize((clone $orders)->whereBetween('created_at', $previousPeriod->utcRange()));
        $changes = [];
        foreach (['total', 'delivered', 'gross_cents', 'net_cents', 'shipping_spend_cents'] as $metric) {
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

        $detail = $request->filled('tariff') ? (clone $orders)->withDisplayIdentity()->whereBetween('created_at', $period->utcRange())->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Rejected])->where('price_cents', $request->integer('tariff'))->latest()->orderByDesc('id')->paginate(10, ['id', 'reference', 'store_name', 'customer_id', 'created_by', 'delivery_city', 'price_cents', 'status', 'created_at'], 'detail_page') : null;

        $detail?->through(fn (Order $order): array => [...$order->only(['id', 'reference', 'delivery_city', 'price_cents', 'status', 'created_at']), 'display_name' => $order->displayName()]);

        return response()->json([...$summary, 'trend' => $statistics->trend($selected, $period), 'comparison' => $comparison, 'changes' => $changes, 'previous_from' => $previousPeriod->start->toDateString(), 'previous_to' => $previousPeriod->end->toDateString(), 'detail' => $detail, 'from' => $period->start->toDateString(), 'to' => $period->end->toDateString(), 'months' => $months, 'this_month' => $current, 'previous_comparable' => $previous, 'change_percent' => $previous ? round(($current - $previous) * 100 / $previous, 1) : null, 'comparison_note' => 'Mese in corso rispetto agli stessi giorni del mese precedente. I volumi misurano l’utilizzo del servizio.']);
    }
}
