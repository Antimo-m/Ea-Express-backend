<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\PendingSettlement;
use App\Models\User;
use App\OrderStatus;
use App\Support\ReportingPeriod;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BalanceController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['customer_id' => ['nullable', 'integer', 'exists:users,id'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $start = Carbon::parse($data['from'] ?? now('Europe/Rome')->startOfMonth()->toDateString(), 'Europe/Rome')->startOfDay();
        $end = Carbon::parse($data['to'] ?? now('Europe/Rome')->toDateString(), 'Europe/Rome')->endOfDay();
        if ($end->lessThan($start) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => 'Scegli un intervallo ordinato, lungo al massimo un anno.']);
        }
        $period = new ReportingPeriod($start, $end);
        $customerId = $data['customer_id'] ?? null;
        $orders = Order::financialFor($request->user())->when($customerId, fn ($q) => $q->where('customer_id', $customerId));
        $completed = (clone $orders)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', $period->utcRange());
        $payments = PaymentEntry::query()->whereHas('order', fn ($q) => $q->financialFor($request->user())->when($customerId, fn ($q) => $q->where('customer_id', $customerId)))->whereBetween('created_at', $period->utcRange());
        $expenses = Expense::visibleTo($request->user())->when($customerId, fn ($q) => $q->whereHas('pendingSettlement.account', fn ($account) => $account->where('customer_id', $customerId)))->whereDate('spent_on', '>=', $start->toDateString())->whereDate('spent_on', '<=', $end->toDateString());
        $cash = (int) (clone $payments)->sum('amount_cents');
        $generalQuery = PendingSettlement::whereHas('account', fn ($q) => $q->where('direction', 'incoming')->whereNull('order_id')->when($customerId, fn ($q) => $q->where('customer_id', $customerId)))->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->where('user_id', $request->user()->id))->whereBetween('created_at', $period->utcRange());
        $generalReceipts = (int) (clone $generalQuery)->sum('amount_cents');
        $cash += (int) $generalReceipts;
        $receivableIds = (clone $completed)->whereNull('paid_at')->select('id');
        $outstanding = (int) (clone $completed)->whereNull('paid_at')->sum('price_cents') - (int) PaymentEntry::whereIn('order_id', $receivableIds)->sum('amount_cents');
        $spent = (int) (clone $expenses)->whereNull('voided_at')->sum('amount_cents');
        $summaries = [];
        foreach (['Oggi' => now('Europe/Rome')->startOfDay(), 'Questa settimana' => now('Europe/Rome')->startOfWeek(), 'Questo mese' => now('Europe/Rome')->startOfMonth()] as $label => $since) {
            $summaries[$label] = (int) (clone $orders)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', [$since->utc(), now()])->sum('price_cents');
        }

        $manual = FinancialMovement::query()->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereRaw('1=0'))->when($customerId, fn ($q) => $q->where('customer_id', $customerId))->whereDate('occurred_on', '>=', $start->toDateString())->whereDate('occurred_on', '<=', $end->toDateString());
        $manualTotals = (clone $manual)->whereNull('voided_at')->selectRaw('kind, SUM(amount_cents) AS cents')->groupBy('kind')->pluck('cents', 'kind');
        $extraIncome = (int) ($manualTotals['income'] ?? 0);
        $extraExpenses = -(int) ($manualTotals['extra_expense'] ?? 0);
        $adjustments = (int) ($manualTotals['adjustment_in'] ?? 0) + (int) ($manualTotals['adjustment_out'] ?? 0);
        $operatingNet = $cash - $spent + $extraIncome - $extraExpenses + $adjustments;
        $pendingQuery = PendingAccount::query()->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereHas('order', fn ($o) => $o->financialFor($request->user())))->when($customerId, fn ($q) => $q->where('customer_id', $customerId))->whereDate('occurred_on', '>=', $start->toDateString())->whereDate('occurred_on', '<=', $end->toDateString())->whereIn('state', ['open', 'partially_paid']);
        $pendingIncoming = (int) (clone $pendingQuery)->where('direction', 'incoming')->selectRaw('COALESCE(SUM(amount_cents-settled_cents),0) AS cents')->value('cents');
        $pendingOutgoing = (int) (clone $pendingQuery)->where('direction', 'outgoing')->selectRaw('COALESCE(SUM(amount_cents-settled_cents),0) AS cents')->value('cents');
        $pendingImpact = $pendingIncoming - $pendingOutgoing;
        $finalNet = $operatingNet + $pendingImpact;
        $customers = User::where('role', UserRole::Customer)->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereIn('id', (clone $orders)->select('customer_id')))->orderBy('name')->get(['id', 'name']);

        return view('balance.index', [
            'period' => $period, 'customers' => $customers, 'extraIncome' => $extraIncome, 'extraExpenses' => $extraExpenses, 'adjustments' => $adjustments, 'operatingNet' => $operatingNet, 'pendingIncoming' => $pendingIncoming, 'pendingOutgoing' => $pendingOutgoing, 'pendingImpact' => $pendingImpact, 'finalNet' => $finalNet,
            'pendingCount' => (clone $pendingQuery)->count(),
            'pendingDetails' => $pendingQuery->with(['customer', 'order'])->orderBy('occurred_on')->orderBy('id')->paginate(15, ['*'], 'pending_page')->withQueryString(),
            'manualMovements' => $manual->with(['customer', 'order', 'user'])->latest('occurred_on')->orderByDesc('id')->paginate(15, ['*'], 'manual_page')->withQueryString(),
            'generalEntries' => $generalQuery->with(['account', 'user'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'general_page')->withQueryString(), 'summaries' => $summaries, 'earned' => (int) (clone $completed)->sum('price_cents'), 'completedCount' => (clone $completed)->count(),
            'cancelledCount' => (clone $orders)->where('status', OrderStatus::Cancelled)->whereBetween('updated_at', $period->utcRange())->count(),
            'outstanding' => $outstanding, 'generalReceipts' => (int) $generalReceipts, 'cash' => $cash, 'spent' => $spent, 'net' => $cash - $spent,
            'orders' => $completed->with('pendingAccount')->latest('delivered_at')->orderByDesc('id')->paginate(10, ['*'], 'orders_page')->withQueryString(),
            'expenses' => $expenses->with(['user:id,name', 'pendingSettlement.account'])->latest('spent_on')->orderByDesc('id')->paginate(10, ['*'], 'expenses_page')->withQueryString(),
            'payments' => $payments->with(['order:id,reference', 'user:id,name'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'payments_page')->withQueryString(),
        ]);
    }
}
