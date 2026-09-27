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
        $report = $statistics->customerReport($orders, $period);
        $summary = $report['summary'];
        $days = (int) $period->start->copy()->startOfDay()->diffInDays($period->end->copy()->startOfDay()) + 1;
        $previousPeriod = new ReportingPeriod($period->start->copy()->subDays($days), $period->start->copy()->subSecond());
        $comparison = $statistics->customerReport($orders, $previousPeriod)['summary'];
        $changes = [];
        foreach (['total', 'delivered', 'in_progress', 'cancelled', 'gross_cents', 'delivered_spend_cents', 'net_cents'] as $metric) {
            $changes[$metric] = $comparison[$metric] !== 0 ? round(($summary[$metric] - $comparison[$metric]) * 100 / abs($comparison[$metric]), 1) : null;
        }

        return response()->json([
            ...$summary,
            'trend' => $report['trend'],
            'months' => $report['months'],
            'chart_summaries' => $report['chart_summaries'],
            'comparison' => $comparison,
            'changes' => $changes,
            'previous_from' => $previousPeriod->start->toDateString(),
            'previous_to' => $previousPeriod->end->toDateString(),
            'from' => $period->start->toDateString(),
            'to' => $period->end->toDateString(),
            'change_percent' => $changes['total'],
            'comparison_note' => 'Volumi mensili nello stesso periodo selezionato. I mesi iniziali e finali possono essere parziali.',
            'date_basis' => 'created_at',
            'revenue_basis' => 'delivered_merchandise_less_shipping',
        ]);
    }
}
