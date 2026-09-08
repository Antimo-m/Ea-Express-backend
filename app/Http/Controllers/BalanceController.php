<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\OrderStatus;
use App\Support\ReportingPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BalanceController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $start = Carbon::parse($data['from'] ?? now('Europe/Rome')->startOfMonth()->toDateString(), 'Europe/Rome')->startOfDay();
        $end = Carbon::parse($data['to'] ?? now('Europe/Rome')->toDateString(), 'Europe/Rome')->endOfDay();
        if ($end->lessThan($start) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => 'Scegli un intervallo ordinato, lungo al massimo un anno.']);
        }
        $period = new ReportingPeriod($start, $end);
        $orders = Order::financialFor($request->user());
        $completed = (clone $orders)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', $period->utcRange());
        $payments = PaymentEntry::query()->whereHas('order', fn ($q) => $q->financialFor($request->user()))->whereBetween('created_at', $period->utcRange());
        $expenses = Expense::visibleTo($request->user())->whereDate('spent_on', '>=', $start->toDateString())->whereDate('spent_on', '<=', $end->toDateString());
        $cash = (int) (clone $payments)->sum('amount_cents');
        $spent = (int) (clone $expenses)->whereNull('voided_at')->sum('amount_cents');
        $summaries = [];
        foreach (['Oggi' => now('Europe/Rome')->startOfDay(), 'Questa settimana' => now('Europe/Rome')->startOfWeek(), 'Questo mese' => now('Europe/Rome')->startOfMonth()] as $label => $since) {
            $summaries[$label] = (int) (clone $orders)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', [$since->utc(), now()])->sum('price_cents');
        }

        return view('balance.index', [
            'period' => $period, 'summaries' => $summaries, 'earned' => (int) (clone $completed)->sum('price_cents'), 'completedCount' => (clone $completed)->count(),
            'cancelledCount' => (clone $orders)->where('status', OrderStatus::Cancelled)->whereBetween('updated_at', $period->utcRange())->count(),
            'outstanding' => (int) (clone $completed)->whereNull('paid_at')->sum('price_cents'), 'cash' => $cash, 'spent' => $spent, 'net' => $cash - $spent,
            'orders' => $completed->latest('delivered_at')->orderByDesc('id')->paginate(10, ['*'], 'orders_page')->withQueryString(),
            'expenses' => $expenses->with('user:id,name')->latest('spent_on')->orderByDesc('id')->paginate(10, ['*'], 'expenses_page')->withQueryString(),
            'payments' => $payments->with(['order:id,reference', 'user:id,name'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'payments_page')->withQueryString(),
        ]);
    }
}
