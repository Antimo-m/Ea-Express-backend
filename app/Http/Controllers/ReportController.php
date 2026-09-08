<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentEntry;
use App\OrderStatus;
use App\Support\ReportingPeriod;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $period = ReportingPeriod::month($data['month'] ?? now('Europe/Rome')->format('Y-m'));
        $previous = ReportingPeriod::month($period->start->copy()->subMonth()->format('Y-m'));
        $base = Order::financialFor($request->user());
        if ($request->user()->role !== UserRole::Admin) {
            $base = Order::query()->where(fn ($q) => $q->where('rider_id', $request->user()->id)->orWhere('created_by', $request->user()->id)->orWhere('rejected_by', $request->user()->id));
        }
        $cohort = (clone $base)->whereBetween('created_at', $period->utcRange());
        $received = (clone $cohort)->count();
        $before = (clone $base)->whereBetween('created_at', $previous->utcRange())->count();
        $states = (clone $cohort)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $accepted = (clone $cohort)->whereHas('events', fn ($q) => $q->where('status', OrderStatus::Accepted))->count();
        $delivered = Order::financialFor($request->user())->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', $period->utcRange());
        $cash = PaymentEntry::whereHas('order', fn ($q) => $q->financialFor($request->user()))->whereBetween('created_at', $period->utcRange())->sum('amount_cents');
        $zones = (clone $cohort)->select('delivery_city')->selectRaw('COUNT(*) as total')->groupBy('delivery_city')->orderByDesc('total')->orderBy('delivery_city')->limit(10)->get();
        $days = [];
        foreach ((clone $cohort)->select(['id', 'created_at'])->lazyById(500) as $order) {
            $day = $order->created_at->timezone('Europe/Rome')->format('d/m');
            $days[$day] = ($days[$day] ?? 0) + 1;
        }
        ksort($days);

        return view('reports.index', ['period' => $period, 'received' => $received, 'previous' => $before, 'growth' => $before ? round(($received - $before) / $before * 100, 1) : null, 'states' => $states, 'accepted' => $accepted, 'delivered' => $delivered->count(), 'earned' => (int) (clone $delivered)->sum('price_cents'), 'cash' => (int) $cash, 'zones' => $zones, 'days' => $days]);
    }
}
