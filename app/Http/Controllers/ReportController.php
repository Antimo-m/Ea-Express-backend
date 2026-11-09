<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingSettlement;
use App\OrderStatus;
use App\Support\OrderStatistics;
use App\Support\ReceiptLedger;
use App\Support\ReportingPeriod;
use App\Support\ShippingEconomics;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], ['to.after_or_equal' => 'La data finale deve essere uguale o successiva alla data iniziale.']);
        $customPeriod = ! empty($data['from']) && ! empty($data['to']);
        $period = $customPeriod
            ? new ReportingPeriod(Carbon::createFromFormat('!Y-m-d', $data['from'], 'Europe/Rome'), Carbon::createFromFormat('!Y-m-d', $data['to'], 'Europe/Rome')->endOfDay())
            : ReportingPeriod::month($data['month'] ?? now('Europe/Rome')->format('Y-m'));
        if ($period->start->diffInDays($period->end) > 366) {
            throw ValidationException::withMessages(['to' => 'Intervallo non valido: massimo un anno.']);
        }
        $previous = $customPeriod
            ? new ReportingPeriod($period->start->copy()->subDays((int) $period->start->diffInDays($period->end->copy()->startOfDay()) + 1), $period->start->copy()->subDay()->endOfDay())
            : ReportingPeriod::month($period->start->copy()->subMonth()->format('Y-m'));
        $base = Order::financialFor($request->user());
        if ($request->user()->role !== UserRole::Admin) {
            $base = Order::query()->where(fn ($q) => $q->where('rider_id', $request->user()->id)->orWhere('created_by', $request->user()->id)->orWhere('rejected_by', $request->user()->id));
        }
        $cohort = (clone $base)->whereBetween('created_at', $period->utcRange());
        $received = (clone $cohort)->count();
        $before = (clone $base)->whereBetween('created_at', $previous->utcRange())->count();
        $states = (clone $cohort)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $accepted = (clone $cohort)->whereHas('events', fn ($q) => $q->where('status', OrderStatus::Accepted))->count();
        $delivered = Order::financialFor($request->user())->where('status', OrderStatus::Delivered)->whereNull('receipt_voided_at')->whereBetween('delivered_at', $period->utcRange());
        $payments = PaymentEntry::whereHas('order', fn ($q) => $q->financialFor($request->user()))->whereBetween('created_at', $period->utcRange());
        $general = PendingSettlement::whereHas('account', fn ($q) => $q->where('direction', 'incoming')->whereNull('order_id'))->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->where('user_id', $request->user()->id))->whereBetween('created_at', $period->utcRange());
        $ledgerTotals = app(ReceiptLedger::class)->totals($payments);
        $generalTotal = (int) (clone $general)->sum('amount_cents');
        $cash = $ledgerTotals['cash'] + $generalTotal;
        $expenses = Expense::visibleTo($request->user())->whereNull('voided_at')->whereDate('spent_on', '>=', $period->start->toDateString())->whereDate('spent_on', '<=', $period->end->toDateString());
        $spent = (int) (clone $expenses)->sum('amount_cents');
        $manual = FinancialMovement::whereNull('voided_at')->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereRaw('1=0'))->whereDate('occurred_on', '>=', $period->start->toDateString())->whereDate('occurred_on', '<=', $period->end->toDateString());
        $manualNet = (int) (clone $manual)->where('kind', '!=', 'cash')->sum('amount_cents');
        $receiptEconomics = app(ShippingEconomics::class)->receipts($payments);
        $operatingNet = $ledgerTotals['net'] + $generalTotal - $spent + $manualNet;
        $cashDays = [];
        $addCash = function (string $day, int $amount) use (&$cashDays): void {
            $cashDays[$day] ??= ['incoming' => 0, 'outgoing' => 0];
            $cashDays[$day][$amount >= 0 ? 'incoming' : 'outgoing'] += abs($amount);
        };
        foreach ((clone $payments)->select(['id', 'slug', 'created_at', 'amount_cents', 'ea_amount_cents'])->lazyById(500) as $payment) {
            $addCash($payment->created_at->timezone('Europe/Rome')->toDateString(), $payment->amount_cents);
        }
        foreach ((clone $general)->select(['id', 'slug', 'created_at', 'amount_cents'])->lazyById(500) as $payment) {
            $addCash($payment->created_at->timezone('Europe/Rome')->toDateString(), $payment->amount_cents);
        }
        foreach ((clone $expenses)->select(['id', 'slug', 'spent_on', 'amount_cents'])->lazyById(500) as $expense) {
            $addCash($expense->spent_on->toDateString(), -$expense->amount_cents);
        }
        foreach ((clone $manual)->where('kind', '!=', 'cash')->select(['id', 'slug', 'occurred_on', 'amount_cents'])->lazyById(500) as $movement) {
            $addCash($movement->occurred_on->toDateString(), $movement->amount_cents);
        }
        $topCustomers = (clone $cohort)->whereNotNull('customer_id')->select('customer_id')->selectRaw('COUNT(*) AS shipments')->groupBy('customer_id')->with('customer:id,slug,name')->orderByDesc('shipments')->orderBy('customer_id')->limit(5)->get();
        $zones = (clone $cohort)->select('delivery_city')->selectRaw('COUNT(*) as total')->groupBy('delivery_city')->orderByDesc('total')->orderBy('delivery_city')->limit(10)->get();
        $days = [];
        $ordersByDate = [];
        foreach (app(OrderStatistics::class)->trend($cohort, $period) as $point) {
            if ($point['shipments'] > 0) {
                $day = substr($point['date'], 8, 2).'/'.substr($point['date'], 5, 2);
                $days[$customPeriod ? $point['date'] : $day] = $point['shipments'];
                $ordersByDate[$point['date']] = $point['shipments'];
            }
        }
        ksort($days);
        $trend = [];
        for ($date = $period->start->copy(); $date->lte($period->end); $date->addDay()) {
            $label = $date->format($period->start->year === $period->end->year ? 'd/m' : 'd/m/Y');
            $key = $date->toDateString();
            $trend[] = ['label' => $label, 'orders' => $ordersByDate[$key] ?? 0, 'incoming' => $cashDays[$key]['incoming'] ?? 0, 'outgoing' => $cashDays[$key]['outgoing'] ?? 0];
        }
        $incomingTotal = array_sum(array_column($cashDays, 'incoming'));
        $outgoingTotal = array_sum(array_column($cashDays, 'outgoing'));

        $shippingEconomics = app(ShippingEconomics::class)->summarize($delivered);

        return view('reports.index', ['customPeriod' => $customPeriod, 'receiptEconomics' => $receiptEconomics, 'shippingEconomics' => $shippingEconomics, 'shippingCounts' => (clone $cohort)->select('shipping_type')->selectRaw('COUNT(*) AS total')->groupBy('shipping_type')->pluck('total', 'shipping_type'), 'period' => $period, 'received' => $received, 'previous' => $before, 'growth' => $before ? round(($received - $before) / $before * 100, 1) : null, 'states' => $states, 'accepted' => $accepted, 'delivered' => $delivered->count(), 'earned' => (int) (clone $delivered)->sum('price_cents'), 'ledgerTotals' => $ledgerTotals, 'cash' => (int) $cash, 'zones' => $zones, 'days' => $days, 'trend' => $trend, 'spent' => $spent, 'manualNet' => $manualNet, 'operatingNet' => $operatingNet, 'topCustomers' => $topCustomers, 'incomingTotal' => $incomingTotal, 'outgoingTotal' => $outgoingTotal]);
    }
}
