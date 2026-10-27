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
use App\Support\PublicReferences;
use App\Support\ReportingPeriod;
use App\Support\ShippingEconomics;
use App\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BalanceController extends Controller
{
    public function index(Request $request): View
    {
        $customerReference = Rule::exists('users', 'slug')->where('role', UserRole::Customer->value);
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'month' => ['nullable', 'date_format:Y-m'], 'customer_id' => ['nullable', 'uuid', $customerReference], 'customer_ids' => ['sometimes', 'array', 'list', 'max:200'], 'customer_ids.*' => ['required', 'uuid', $customerReference], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'ledger' => ['nullable', 'in:payments,expenses,cash,recorded,reversed,pending'], 'receipt_sort' => ['nullable', 'in:recent,oldest,amount_high,amount_low']]);
        $ledger = $data['ledger'] ?? collect(['recorded_page' => 'recorded', 'reversed_page' => 'reversed', 'expenses_page' => 'expenses', 'cash_page' => 'cash', 'pending_page' => 'pending'])->first(fn (string $view, string $page): bool => $request->has($page)) ?? 'payments';
        $receiptSort = $data['receipt_sort'] ?? 'recent';
        [$receiptColumn, $receiptDirection] = match ($receiptSort) {
            'oldest' => ['delivered_at', 'asc'], 'amount_high' => ['price_cents', 'desc'], 'amount_low' => ['price_cents', 'asc'], default => ['delivered_at', 'desc'],
        };
        $selectedCustomerSlugs = array_values(array_unique([...($data['customer_ids'] ?? []), ...(! empty($data['customer_id']) ? [$data['customer_id']] : [])]));
        $data['customer_ids'] = $selectedCustomerSlugs;
        $data = PublicReferences::ids($data);
        $start = Carbon::parse($data['from'] ?? now('Europe/Rome')->startOfMonth()->toDateString(), 'Europe/Rome')->startOfDay();
        $end = Carbon::parse($data['to'] ?? now('Europe/Rome')->toDateString(), 'Europe/Rome')->endOfDay();
        if (! empty($data['year'])) {
            $annual = ReportingPeriod::year((int) $data['year']);
            $start = $annual->start;
            $end = $annual->end;
        } elseif (! empty($data['month'])) {
            $month = ReportingPeriod::month($data['month']);
            $start = $month->start;
            $end = $month->end;
        }
        if ($end->lessThan($start) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => 'Scegli un intervallo ordinato, lungo al massimo un anno.']);
        }
        $period = new ReportingPeriod($start, $end);
        $customerIds = $data['customer_ids'];
        $orders = Order::financialFor($request->user())->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds));
        $completed = (clone $orders)->where('status', OrderStatus::Delivered)->whereNull('receipt_voided_at')->whereBetween('delivered_at', $period->utcRange());
        $reversed = (clone $orders)->where('status', OrderStatus::Delivered)->whereBetween('receipt_voided_at', $period->utcRange());
        $payments = PaymentEntry::query()->where('amount_cents', '!=', 0)->whereHas('order', fn ($q) => $q->financialFor($request->user())->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds)))->whereBetween('created_at', $period->utcRange());
        $expenses = Expense::visibleTo($request->user())->when($customerIds, fn ($q) => $q->whereHas('pendingSettlement.account', fn ($account) => $account->whereIn('customer_id', $customerIds)))->whereDate('spent_on', '>=', $start->toDateString())->whereDate('spent_on', '<=', $end->toDateString());
        $cash = (int) (clone $payments)->sum('amount_cents');
        $generalQuery = PendingSettlement::where('amount_cents', '>', 0)->whereHas('account', fn ($q) => $q->where('direction', 'incoming')->whereNull('order_id')->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds)))->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->where('user_id', $request->user()->id))->whereBetween('created_at', $period->utcRange());
        $generalReceipts = (int) (clone $generalQuery)->sum('amount_cents');
        $cash += (int) $generalReceipts;
        $receivableIds = (clone $completed)->whereNull('paid_at')->whereNull('receipt_voided_at')->select('id');
        $outstanding = (int) (clone $completed)->whereNull('paid_at')->whereNull('receipt_voided_at')->sum('price_cents') - (int) PaymentEntry::whereIn('order_id', $receivableIds)->sum('amount_cents');
        $spent = (int) (clone $expenses)->whereNull('voided_at')->sum('amount_cents');
        $summaries = [];
        foreach (['Oggi' => now('Europe/Rome')->startOfDay(), 'Questa settimana' => now('Europe/Rome')->startOfWeek(), 'Questo mese' => now('Europe/Rome')->startOfMonth()] as $label => $since) {
            $summaries[$label] = (int) (clone $orders)->where('status', OrderStatus::Delivered)->whereNull('receipt_voided_at')->whereBetween('delivered_at', [$since->utc(), now()])->sum('price_cents');
        }

        $manual = FinancialMovement::query()->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereRaw('1=0'))->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds))->whereDate('occurred_on', '>=', $start->toDateString())->whereDate('occurred_on', '<=', $end->toDateString());
        $manualTotals = (clone $manual)->whereNull('voided_at')->selectRaw('kind, SUM(amount_cents) AS cents')->groupBy('kind')->pluck('cents', 'kind');
        $extraIncome = (int) ($manualTotals['income'] ?? 0);
        $extraExpenses = -(int) ($manualTotals['extra_expense'] ?? 0);
        $adjustments = (int) ($manualTotals['adjustment_in'] ?? 0) + (int) ($manualTotals['adjustment_out'] ?? 0);
        $cashAdditions = (int) ($manualTotals['cash'] ?? 0);
        $reversalTotal = -(int) (clone $payments)->where('amount_cents', '<', 0)->sum('amount_cents');
        $grossIncome = (int) (clone $payments)->where('amount_cents', '>', 0)->sum('amount_cents') + $generalReceipts + $extraIncome + $adjustments;
        $registeredIncome = $grossIncome - $reversalTotal;
        $totalExpenses = $spent + $extraExpenses;
        $receiptEconomics = app(ShippingEconomics::class)->receipts($payments);
        $operatingNet = $registeredIncome - $totalExpenses;
        $pendingQuery = PendingAccount::query()->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereHas('order', fn ($o) => $o->financialFor($request->user())))->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds))->whereDate('occurred_on', '>=', $start->toDateString())->whereDate('occurred_on', '<=', $end->toDateString())->whereIn('state', ['open', 'partially_paid']);
        $pendingIncoming = (int) (clone $pendingQuery)->where('direction', 'incoming')->selectRaw('COALESCE(SUM(amount_cents-settled_cents),0) AS cents')->value('cents');
        $pendingOutgoing = (int) (clone $pendingQuery)->where('direction', 'outgoing')->selectRaw('COALESCE(SUM(amount_cents-settled_cents),0) AS cents')->value('cents');
        $pendingImpact = $pendingIncoming - $pendingOutgoing;
        $finalNet = $operatingNet + $pendingImpact + $cashAdditions;
        $customers = User::where('role', UserRole::Customer)->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereIn('id', (clone $orders)->select('customer_id')))->orderBy('name')->get(['id', 'slug', 'name', 'email']);

        return view('balance.index', [
            'ledger' => $ledger, 'receiptSort' => $receiptSort,
            'selectedCustomerSlugs' => $selectedCustomerSlugs, 'reversalTotal' => $reversalTotal, 'registeredIncome' => $registeredIncome,
            'reversedCount' => (clone $reversed)->count(),
            'manualEntries' => $ledger === 'cash' ? (clone $manual)->with(['user:id,slug,name', 'customer:id,slug,name', 'order:id,slug,reference'])->latest('occurred_on')->orderByDesc('id')->paginate(10, ['*'], 'cash_page')->withQueryString() : null,
            'grossIncome' => $grossIncome, 'totalExpenses' => $totalExpenses, 'cashAdditions' => $cashAdditions,
            'cashEntries' => (clone $manual)->where('kind', 'cash')->with('user:id,slug,name')->latest('occurred_on')->orderByDesc('id')->paginate(10, ['*'], 'cash_page')->withQueryString(),
            'receiptEconomics' => $receiptEconomics, 'shippingEconomics' => app(ShippingEconomics::class)->summarize($completed), 'period' => $period, 'customers' => $customers, 'extraIncome' => $extraIncome, 'extraExpenses' => $extraExpenses, 'adjustments' => $adjustments, 'operatingNet' => $operatingNet, 'pendingIncoming' => $pendingIncoming, 'pendingOutgoing' => $pendingOutgoing, 'pendingImpact' => $pendingImpact, 'finalNet' => $finalNet,
            'pendingCount' => (clone $pendingQuery)->count(),
            'generalEntries' => $generalQuery->with(['account', 'user'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'general_page')->withQueryString(), 'summaries' => $summaries, 'earned' => (int) (clone $completed)->sum('price_cents'), 'completedCount' => (clone $completed)->count(),
            'cancelledCount' => (clone $orders)->where('status', OrderStatus::Cancelled)->whereBetween('updated_at', $period->utcRange())->count(),
            'outstanding' => $outstanding, 'generalReceipts' => (int) $generalReceipts, 'cash' => $cash, 'spent' => $spent, 'net' => $cash - $spent,
            'orders' => (clone $completed)->whereNull('paid_at')->whereNull('receipt_voided_at')->withDisplayIdentity()->with('pendingAccount')->withSum('payments', 'amount_cents')->orderBy($receiptColumn, $receiptDirection)->orderByDesc('id')->paginate(10, ['*'], 'orders_page')->withQueryString(),
            'reversedOrders' => (clone $reversed)->withDisplayIdentity()->with('pendingAccount')->withSum('payments', 'amount_cents')->latest('receipt_voided_at')->orderByDesc('id')->paginate(10, ['*'], 'reversed_page')->withQueryString(),
            'recordedOrders' => (clone $completed)->whereNotNull('paid_at')->withDisplayIdentity()->with('pendingAccount')->withSum('payments', 'amount_cents')->latest('delivered_at')->orderByDesc('id')->paginate(10, ['*'], 'recorded_page')->withQueryString(),
            'expenses' => $expenses->with(['user:id,slug,name', 'pendingSettlement.account'])->latest('spent_on')->orderByDesc('id')->paginate(10, ['*'], 'expenses_page')->withQueryString(),
            'payments' => $payments->with(['order:id,slug,reference', 'user:id,slug,name'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'payments_page')->withQueryString(),
        ]);
    }
}
