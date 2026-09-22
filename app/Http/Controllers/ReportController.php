<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingSettlement;
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
        $payments = PaymentEntry::whereHas('order', fn ($q) => $q->financialFor($request->user()))->whereBetween('created_at', $period->utcRange());
        $general = PendingSettlement::whereHas('account', fn ($q) => $q->where('direction', 'incoming')->whereNull('order_id'))->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->where('user_id', $request->user()->id))->whereBetween('created_at', $period->utcRange());
        $cash = (int) (clone $payments)->sum('amount_cents') + (int) (clone $general)->sum('amount_cents');
        $expenses = Expense::visibleTo($request->user())->whereNull('voided_at')->whereBetween('spent_on', [$period->start->toDateString(), $period->end->toDateString()]);
        $spent = (int) (clone $expenses)->sum('amount_cents');
        $manual = FinancialMovement::whereNull('voided_at')->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereRaw('1=0'))->whereBetween('occurred_on', [$period->start->toDateString(), $period->end->toDateString()]);
        $manualNet = (int) (clone $manual)->sum('amount_cents');
        $operatingNet = $cash - $spent + $manualNet;
        $cashDays = [];
        $addCash = function (string $day, int $amount) use (&$cashDays): void {
            $cashDays[$day] ??= ['incoming' => 0, 'outgoing' => 0];
            $cashDays[$day][$amount >= 0 ? 'incoming' : 'outgoing'] += abs($amount);
        };
        foreach ((clone $payments)->select(['id', 'created_at', 'amount_cents'])->lazyById(500) as $payment) {
            $addCash($payment->created_at->timezone('Europe/Rome')->format('d/m'), $payment->amount_cents);
        }
        foreach ((clone $general)->select(['id', 'created_at', 'amount_cents'])->lazyById(500) as $payment) {
            $addCash($payment->created_at->timezone('Europe/Rome')->format('d/m'), $payment->amount_cents);
        }
        foreach ((clone $expenses)->select(['id', 'spent_on', 'amount_cents'])->lazyById(500) as $expense) {
            $addCash($expense->spent_on->format('d/m'), -$expense->amount_cents);
        }
        foreach ((clone $manual)->select(['id', 'occurred_on', 'amount_cents'])->lazyById(500) as $movement) {
            $addCash($movement->occurred_on->format('d/m'), $movement->amount_cents);
        }
        $topCustomers = (clone $cohort)->whereNotNull('customer_id')->select('customer_id')->selectRaw('COUNT(*) AS shipments')->groupBy('customer_id')->with('customer:id,name')->orderByDesc('shipments')->orderBy('customer_id')->limit(5)->get();
        $zones = (clone $cohort)->select('delivery_city')->selectRaw('COUNT(*) as total')->groupBy('delivery_city')->orderByDesc('total')->orderBy('delivery_city')->limit(10)->get();
        $days = [];
        foreach ((clone $cohort)->select(['id', 'created_at'])->lazyById(500) as $order) {
            $day = $order->created_at->timezone('Europe/Rome')->format('d/m');
            $days[$day] = ($days[$day] ?? 0) + 1;
        }
        ksort($days);
        $trend = [];
        for ($date = $period->start->copy(); $date->lte($period->end); $date->addDay()) {
            $label = $date->format('d/m');
            $trend[] = ['label' => $label, 'orders' => $days[$label] ?? 0, 'incoming' => $cashDays[$label]['incoming'] ?? 0, 'outgoing' => $cashDays[$label]['outgoing'] ?? 0];
        }
        $incomingTotal = array_sum(array_column($cashDays, 'incoming'));
        $outgoingTotal = array_sum(array_column($cashDays, 'outgoing'));

        return view('reports.index', ['period' => $period, 'received' => $received, 'previous' => $before, 'growth' => $before ? round(($received - $before) / $before * 100, 1) : null, 'states' => $states, 'accepted' => $accepted, 'delivered' => $delivered->count(), 'earned' => (int) (clone $delivered)->sum('price_cents'), 'cash' => (int) $cash, 'zones' => $zones, 'days' => $days, 'trend' => $trend, 'spent' => $spent, 'manualNet' => $manualNet, 'operatingNet' => $operatingNet, 'topCustomers' => $topCustomers, 'incomingTotal' => $incomingTotal, 'outgoingTotal' => $outgoingTotal]);
    }
}
