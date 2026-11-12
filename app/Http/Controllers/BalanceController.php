<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\PendingSettlement;
use App\Models\ReceiptReversal;
use App\Models\User;
use App\OrderStatus;
use App\Support\FinancialRegister;
use App\Support\PendingPositionSnapshot;
use App\Support\PublicReferences;
use App\Support\ReceiptLedger;
use App\Support\ReportingPeriod;
use App\Support\ShippingEconomics;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
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
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'month' => ['nullable', 'date_format:Y-m'], 'customer_id' => ['nullable', 'uuid', $customerReference], 'customer_ids' => ['sometimes', 'array', 'list', 'max:200'], 'customer_ids.*' => ['required', 'uuid', $customerReference], 'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'], 'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'], 'ledger' => ['nullable', 'in:payments,expenses,cash,recorded,reversed,pending,refunds,history'], 'receipt_sort' => ['nullable', 'in:recent,oldest,amount_high,amount_low'], 'period' => ['nullable', 'in:today,week,month,year,custom'], 'pending_state' => ['nullable', 'in:outstanding,open,partially_paid,paid,cancelled,all'], 'pending_origin' => ['nullable', 'in:all,prior,period'], 'pending_direction' => ['nullable', 'in:incoming,outgoing'], 'q' => ['nullable', 'string', 'max:150']]);
        $ledger = $data['ledger'] ?? collect(['recorded_page' => 'recorded', 'reversed_page' => 'reversed', 'reversals_page' => 'reversed', 'legacy_reversals_page' => 'reversed', 'expenses_page' => 'expenses', 'cash_page' => 'cash', 'pending_page' => 'pending'])->first(fn (string $view, string $page): bool => $request->has($page)) ?? 'payments';
        $ledger = $ledger === 'recorded' ? 'payments' : $ledger;
        $receiptSort = $data['receipt_sort'] ?? 'recent';
        $search = trim($data['q'] ?? '');
        $term = '%'.$search.'%';
        [$receiptColumn, $receiptDirection] = match ($receiptSort) {
            'oldest' => ['delivered_at', 'asc'], 'amount_high' => ['price_cents', 'desc'], 'amount_low' => ['price_cents', 'asc'], default => ['delivered_at', 'desc'],
        };
        $selectedCustomerSlugs = array_values(array_unique([...($data['customer_ids'] ?? []), ...(! empty($data['customer_id']) ? [$data['customer_id']] : [])]));
        $data['customer_ids'] = $selectedCustomerSlugs;
        $data = PublicReferences::ids($data);
        $start = Carbon::parse($data['from'] ?? now('Europe/Rome')->startOfMonth()->toDateString(), 'Europe/Rome')->startOfDay();
        $end = Carbon::parse($data['to'] ?? now('Europe/Rome')->toDateString(), 'Europe/Rome')->endOfDay();
        if (! empty($data['period']) && $data['period'] !== 'custom') {
            $start = match ($data['period']) {
                'year' => ReportingPeriod::year((int) ($data['year'] ?? now('Europe/Rome')->year))->start,
                'today' => now('Europe/Rome')->startOfDay(),
                'week' => now('Europe/Rome')->startOfWeek(),
                default => now('Europe/Rome')->startOfMonth(),
            };
            $end = $data['period'] === 'year' ? $start->copy()->endOfYear() : now('Europe/Rome')->endOfDay();
        } elseif (($data['period'] ?? null) !== 'custom' && ! empty($data['year'])) {
            $annual = ReportingPeriod::year((int) $data['year']);
            $start = $annual->start;
            $end = $annual->end;
        } elseif (($data['period'] ?? null) !== 'custom' && ! empty($data['month'])) {
            $month = ReportingPeriod::month($data['month']);
            $start = $month->start;
            $end = $month->end;
        }
        if ($end->lessThan($start) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => 'Scegli un intervallo ordinato, lungo al massimo un anno.']);
        }
        $period = new ReportingPeriod($start, $end);
        $customerIds = $data['customer_ids'];
        $referenceDate = $period->reference();
        $cutoff = $referenceDate->copy()->utc();
        $orders = Order::financialFor($request->user())->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds))->when($search !== '', fn (Builder $q) => $this->searchOrder($q, $term));
        $completed = (clone $orders)->where('status', OrderStatus::Delivered)->whereNull('receipt_voided_at')->whereBetween('delivered_at', $period->utcRange());
        $reversed = (clone $orders)->where('status', OrderStatus::Delivered)->whereBetween('receipt_voided_at', $period->utcRange());
        $payments = PaymentEntry::query()->where(fn ($q) => $q->where('amount_cents', '!=', 0)->orWhere('cash_amount_cents', '!=', 0)->orWhere('kind', 'restoration'))->whereHas('order', fn ($q) => $q->financialFor($request->user())->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds)))->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match->where('note', 'like', $term)->orWhereHas('order', fn (Builder $order) => $this->searchOrder($order, $term))))->whereBetween('created_at', $period->utcRange());
        $expenses = Expense::visibleTo($request->user())->when($customerIds, fn ($q) => $q->whereHas('pendingSettlement.account', fn ($account) => $account->whereIn('customer_id', $customerIds)))->whereDate('spent_on', '>=', $start->toDateString())->whereDate('spent_on', '<=', $end->toDateString())->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match->where('description', 'like', $term)->orWhereHas('pendingSettlement.account', fn (Builder $account) => $this->searchPending($account, $term))));
        $ledgerTotals = app(ReceiptLedger::class)->totals($payments);
        $cash = $ledgerTotals['cash'];
        $generalQuery = PendingSettlement::where('amount_cents', '>', 0)->whereHas('account', fn ($q) => $q->where('direction', 'incoming')->whereNull('order_id')->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds)))->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->where('user_id', $request->user()->id))->whereBetween('created_at', $period->utcRange())->when($search !== '', fn (Builder $q) => $q->whereHas('account', fn (Builder $account) => $this->searchPending($account, $term)));
        $generalReceipts = (int) (clone $generalQuery)->sum('amount_cents');
        $cash += (int) $generalReceipts;
        $receivableIds = (clone $completed)->whereNull('paid_at')->whereNull('receipt_voided_at')->select('id');
        $outstanding = (int) (clone $completed)->whereNull('paid_at')->whereNull('receipt_voided_at')->sum('price_cents') - app(ReceiptLedger::class)->cash(PaymentEntry::whereIn('order_id', $receivableIds));
        $spent = (int) (clone $expenses)->whereNull('voided_at')->sum('amount_cents');
        $summaries = [];
        foreach (['Oggi' => now('Europe/Rome')->startOfDay(), 'Questa settimana' => now('Europe/Rome')->startOfWeek(), 'Questo mese' => now('Europe/Rome')->startOfMonth()] as $label => $since) {
            $summaries[$label] = (int) (clone $orders)->where('status', OrderStatus::Delivered)->whereNull('receipt_voided_at')->whereBetween('delivered_at', [$since->utc(), now()])->sum('price_cents');
        }

        $manual = FinancialMovement::query()->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereRaw('1=0'))->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds))->whereDate('occurred_on', '>=', $start->toDateString())->whereDate('occurred_on', '<=', $end->toDateString())->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match->where('description', 'like', $term)->orWhere('notes', 'like', $term)->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $term))->orWhereHas('order', fn (Builder $order) => $this->searchOrder($order, $term))));
        $manualTotals = (clone $manual)->whereNull('voided_at')->selectRaw('kind, SUM(amount_cents) AS cents')->groupBy('kind')->pluck('cents', 'kind');
        $extraIncome = (int) ($manualTotals['income'] ?? 0);
        $extraExpenses = -(int) ($manualTotals['extra_expense'] ?? 0);
        $adjustments = (int) ($manualTotals['adjustment_in'] ?? 0) + (int) ($manualTotals['adjustment_out'] ?? 0);
        $cashAdditions = (int) ($manualTotals['cash'] ?? 0);
        $reversalTotal = (int) ReceiptReversal::where(fn ($q) => $q->whereNull('restored_at')->orWhere('restored_at', '>', $cutoff))->where('created_at', '<=', $cutoff)
            ->whereHas('order', fn (Builder $query) => $query->financialFor($request->user())->when($customerIds, fn (Builder $query) => $query->whereIn('customer_id', $customerIds)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match->where('reason', 'like', $term)->orWhereHas('order', fn (Builder $order) => $this->searchOrder($order, $term))))
            ->sum('corrected_cents');
        $restorationTotal = $ledgerTotals['restorations'];
        $refundTotal = $ledgerTotals['refunds'];
        $grossIncome = $ledgerTotals['net'] + $generalReceipts + $extraIncome + $adjustments;
        $registeredIncome = $grossIncome;
        $totalExpenses = $spent + $extraExpenses;
        $receiptEconomics = app(ShippingEconomics::class)->receipts($payments);
        $operatingNet = $registeredIncome - $totalExpenses;
        $pendingQuery = PendingAccount::query()->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereHas('order', fn ($o) => $o->financialFor($request->user())))->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds))->when($search !== '', fn (Builder $q) => $this->searchPending($q, $term));
        $snapshot = app(PendingPositionSnapshot::class)->at($pendingQuery, $referenceDate);
        $pendingIncoming = $snapshot['incoming'];
        $pendingOutgoing = $snapshot['outgoing'];
        $pendingImpact = $pendingIncoming - $pendingOutgoing;
        $openIds = array_keys(array_filter($snapshot['positions'], fn (array $position): bool => in_array($position['state'], ['open', 'partially_paid'])));
        $pendingState = $data['pending_state'] ?? 'outstanding';
        $pendingIds = array_keys(array_filter($snapshot['positions'], fn (array $position): bool => match ($pendingState) {
            'all' => true, 'outstanding' => in_array($position['state'], ['open', 'partially_paid']), default => $position['state'] === $pendingState,
        }));
        $pendingEntries = $ledger === 'pending' ? (clone $pendingQuery)->whereIn('id', $pendingIds)
            ->when($data['pending_direction'] ?? null, fn ($q, $direction) => $q->where('direction', $direction))
            ->when(($data['pending_origin'] ?? 'all') !== 'all', fn ($q) => $q->whereDate('occurred_on', ($data['pending_origin'] === 'prior' ? '<' : '>='), $start->toDateString()))
            ->with(['customer:id,slug,name', 'order:id,slug,reference', 'settlements' => fn ($q) => $q->where('created_at', '<=', $cutoff)->with('user:id,slug,name')])
            ->latest('occurred_on')->orderByDesc('id')->paginate(10, ['*'], 'pending_page')->withQueryString()
            ->through(fn (PendingAccount $account): PendingAccount => app(PendingPositionSnapshot::class)->apply($account, $snapshot['positions'][$account->id])) : null;
        $refundsDueQuery = ReceiptReversal::where('created_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('restored_at')->orWhere('restored_at', '>', $cutoff))
            ->whereHas('order', fn ($q) => $q->financialFor($request->user())->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds))->when($search !== '', fn ($q) => $this->searchOrder($q, $term)))
            ->withSum(['payments as refunded_cash' => fn ($q) => $q->whereIn('kind', ['refund', 'cash_reconciliation'])->where('created_at', '<=', $cutoff)], 'cash_amount_cents');
        $refundsDue = 0;
        $refundsDueQuery->chunkById(200, function ($reversals) use (&$refundsDue): void {
            foreach ($reversals as $reversal) {
                $refundsDue += max(0, $reversal->collected_cents + (int) $reversal->refunded_cash);
            }
        });
        $cashBalance = $cash - $spent + (int) $manualTotals->sum();
        $monetaryPayments = (clone $payments)->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', 'cash_reconciliation'))->whereDoesntHave('audits', fn ($q) => $q->where('action', 'payment.refund_disputed'));
        $cashIn = app(ReceiptLedger::class)->cash((clone $monetaryPayments)->whereRaw(ReceiptLedger::CashExpression.' > 0')) + $generalReceipts
            + (int) (clone $manual)->whereNull('voided_at')->where('amount_cents', '>', 0)->sum('amount_cents');
        $cashOut = -app(ReceiptLedger::class)->cash((clone $monetaryPayments)->whereRaw(ReceiptLedger::CashExpression.' < 0')) + $spent
            - (int) (clone $manual)->whereNull('voided_at')->where('amount_cents', '<', 0)->sum('amount_cents');
        $registerEntries = in_array($ledger, ['payments', 'expenses', 'cash', 'refunds', 'history'])
            ? app(FinancialRegister::class)->page($ledger, $payments, $expenses, $manual, $generalQuery, $referenceDate) : null;
        $manualSections = [];
        $paymentSections = [];
        $customers = User::where('role', UserRole::Customer)->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereIn('id', (clone $orders)->select('customer_id')))->orderBy('name')->get(['id', 'slug', 'name', 'email']);

        return view('balance.index', [
            'uncertainRefunds' => PaymentEntry::where('kind', 'refund')->where('created_at', '<=', $cutoff)
                ->whereHas('order', fn ($q) => $q->financialFor($request->user())->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds))->when($search !== '', fn ($q) => $this->searchOrder($q, $term)))
                ->whereDoesntHave('audits', fn ($q) => $q->where('created_at', '<=', $cutoff)->where(fn ($q) => $q->whereIn('action', ['payment.refund_verified', 'payment.refund_disputed'])->orWhere('after->cash_confirmation', 'explicit_payment')))->count(), 'ledgerTotals' => $ledgerTotals, 'referenceDate' => $referenceDate, 'pendingUncertain' => $snapshot['uncertain'], 'pendingState' => $pendingState, 'refundsDue' => $refundsDue, 'cashIn' => $cashIn, 'cashOut' => $cashOut, 'registerEntries' => $registerEntries,
            'restorationTotal' => $restorationTotal, 'refundTotal' => $refundTotal,
            'legacyReversals' => (clone $payments)->where('amount_cents', '<', 0)->whereNull('original_entry_id')->with(['order' => fn ($q) => $q->withDisplayIdentity(), 'user:id,slug,name'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'legacy_reversals_page')->withQueryString(),
            'reversalEntries' => ReceiptReversal::where('created_at', '<=', $cutoff)
                ->where(fn ($q) => $q->whereNull('restored_at')->orWhere('restored_at', '>', $cutoff))
                ->whereHas('order', fn ($q) => $q->financialFor($request->user())->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds)))
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('reason', 'like', $term)->orWhereHas('order', fn ($q) => $this->searchOrder($q, $term))))
                ->with(['order' => fn ($q) => $q->withDisplayIdentity()->withReceiptLedger(), 'user:id,slug,name', 'restoredBy:id,slug,name', 'payments' => fn ($q) => $q->where('created_at', '<=', $cutoff)->with('originalEntry')])
                ->latest()->orderByDesc('id')->paginate(10, ['*'], 'reversals_page')->withQueryString(),
            'ledger' => $ledger, 'receiptSort' => $receiptSort, 'search' => $search, 'cashBalance' => $cashBalance, 'manualSections' => $manualSections, 'paymentSections' => $paymentSections,
            'cashMovementBalance' => $cashAdditions, 'operatingResult' => $operatingNet, 'finalNet' => $operatingNet,
            'pendingBalance' => $snapshot['uncertain'] ? null : $pendingImpact,
            'deliveredCount' => (clone $orders)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', $period->utcRange())->count(),
            'pendingEntries' => $pendingEntries,
            'selectedCustomerSlugs' => $selectedCustomerSlugs, 'reversalTotal' => $reversalTotal, 'registeredIncome' => $registeredIncome,
            'reversedCount' => (clone $reversed)->count(),
            'manualEntries' => $ledger === 'cash' ? (clone $manual)->with(['user:id,slug,name', 'customer:id,slug,name', 'order:id,slug,reference'])->latest('occurred_on')->orderByDesc('id')->paginate(10, ['*'], 'cash_page')->withQueryString() : null,
            'grossIncome' => $grossIncome, 'totalExpenses' => $totalExpenses, 'cashAdditions' => $cashAdditions,
            'receiptEconomics' => $receiptEconomics, 'shippingEconomics' => app(ShippingEconomics::class)->summarize($completed), 'period' => $period, 'customers' => $customers, 'extraIncome' => $extraIncome, 'extraExpenses' => $extraExpenses, 'adjustments' => $adjustments, 'operatingNet' => $operatingNet, 'pendingIncoming' => $pendingIncoming, 'pendingOutgoing' => $pendingOutgoing, 'pendingImpact' => $pendingImpact,
            'pendingCount' => count($openIds),
            'generalEntries' => $generalQuery->with(['account', 'user'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'general_page')->withQueryString(), 'summaries' => $summaries, 'earned' => (int) (clone $completed)->sum('price_cents'), 'completedCount' => (clone $completed)->count(),
            'cancelledCount' => (clone $orders)->where('status', OrderStatus::Cancelled)->whereBetween('updated_at', $period->utcRange())->count(),
            'outstanding' => $outstanding, 'generalReceipts' => (int) $generalReceipts, 'cash' => $cash, 'spent' => $spent, 'net' => $cash - $spent,
            'orders' => (clone $completed)->whereNull('paid_at')->whereNull('receipt_voided_at')->withDisplayIdentity()->withReceiptLedger()->orderBy($receiptColumn, $receiptDirection)->orderByDesc('id')->paginate(10, ['*'], 'orders_page')->withQueryString(),
            'reversedOrders' => (clone $orders)->where('status', OrderStatus::Delivered)->where('receipt_voided_at', '<=', $cutoff)->whereDoesntHave('receiptReversals')->withDisplayIdentity()->withReceiptLedger()->latest('receipt_voided_at')->orderByDesc('id')->paginate(10, ['*'], 'reversed_page')->withQueryString(),
            'recordedOrders' => (clone $orders)->where('status', OrderStatus::Delivered)->whereNull('receipt_voided_at')->whereBetween('paid_at', $period->utcRange())->withDisplayIdentity()->withReceiptLedger()->latest('delivered_at')->orderByDesc('id')->paginate(10, ['*'], 'recorded_page')->withQueryString(),
            'expenses' => $expenses->with(['user:id,slug,name', 'pendingSettlement.account'])->latest('spent_on')->orderByDesc('id')->paginate(10, ['*'], 'expenses_page')->withQueryString(),
            'payments' => $payments->with(['order' => fn ($q) => $q->select(['id', 'slug', 'reference', 'store_name', 'customer_id', 'created_by'])->withDisplayIdentity(), 'user:id,slug,name', 'originalEntry:id,slug,created_at'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'payments_page')->withQueryString(),
        ]);
    }

    private function searchOrder(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $match) => $match->where('reference', 'like', $term)
            ->orWhere('store_name', 'like', $term)->orWhere('recipient_name', 'like', $term)
            ->orWhere('pickup_address', 'like', $term)->orWhere('pickup_city', 'like', $term)
            ->orWhere('delivery_address', 'like', $term)->orWhere('delivery_city', 'like', $term)
            ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $term)->orWhere('email', 'like', $term)));
    }

    private function searchPending(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $match) => $match->where('subject', 'like', $term)
            ->orWhere('description', 'like', $term)->orWhere('notes', 'like', $term)
            ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $term)->orWhere('email', 'like', $term))
            ->orWhereHas('order', fn (Builder $order) => $this->searchOrder($order, $term)));
    }
}
