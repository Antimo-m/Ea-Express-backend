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
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100'], 'month' => ['nullable', 'date_format:Y-m'], 'customer_id' => ['nullable', 'uuid', $customerReference], 'customer_ids' => ['sometimes', 'array', 'list', 'max:200'], 'customer_ids.*' => ['required', 'uuid', $customerReference], 'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'], 'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'], 'ledger' => ['nullable', 'in:payments,expenses,cash,recorded,reversed,pending'], 'receipt_sort' => ['nullable', 'in:recent,oldest,amount_high,amount_low'], 'period' => ['nullable', 'in:today,week,month,year,custom'], 'q' => ['nullable', 'string', 'max:150']]);
        $ledger = $data['ledger'] ?? collect(['recorded_page' => 'recorded', 'reversed_page' => 'reversed', 'reversals_page' => 'reversed', 'legacy_reversals_page' => 'reversed', 'expenses_page' => 'expenses', 'cash_page' => 'cash', 'pending_page' => 'pending'])->first(fn (string $view, string $page): bool => $request->has($page)) ?? 'payments';
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
        $reversalTotal = (int) ReceiptReversal::whereNull('restored_at')->where('created_at', '<=', $period->utcRange()[1])
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
        $pendingQuery = PendingAccount::query()->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereHas('order', fn ($o) => $o->financialFor($request->user())))->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds))->whereDate('occurred_on', '>=', $start->toDateString())->whereDate('occurred_on', '<=', $end->toDateString())->when($search !== '', fn (Builder $q) => $this->searchPending($q, $term));
        $openPending = (clone $pendingQuery)->whereIn('state', ['open', 'partially_paid']);
        $pendingIncoming = (int) (clone $openPending)->where('direction', 'incoming')->selectRaw('COALESCE(SUM(amount_cents-settled_cents),0) AS cents')->value('cents');
        $pendingOutgoing = (int) (clone $openPending)->where('direction', 'outgoing')->selectRaw('COALESCE(SUM(amount_cents-settled_cents),0) AS cents')->value('cents');
        $pendingImpact = $pendingIncoming - $pendingOutgoing;
        $cashBalance = $cash - $spent + (int) $manualTotals->sum();
        $manualSections = [];
        if ($ledger === 'cash') {
            foreach (FinancialMovement::Kinds as $kind => $label) {
                $manualSections[$kind] = ['label' => $label, 'entries' => (clone $manual)->where('kind', $kind)->with(['user:id,slug,name', 'customer:id,slug,name', 'order:id,slug,reference'])->latest('occurred_on')->orderByDesc('id')->paginate(10, ['*'], $kind === 'cash' ? 'cash_page' : 'movement_'.$kind.'_page')->withQueryString()];
            }
        }
        $paymentSections = [];
        if ($ledger === 'payments') {
            $paymentKinds = ['receipts' => ['label' => 'Incassi delle spedizioni', 'kinds' => ['receipt', 'recollection']], 'corrections' => ['label' => 'Storni e ripristini contabili', 'kinds' => ['reversal', 'restoration']], 'refunds' => ['label' => 'Rimborsi pagati', 'kinds' => ['refund']], 'other' => ['label' => 'Riconciliazioni e altre registrazioni', 'kinds' => []]];
            foreach ($paymentKinds as $category => $section) {
                $entries = (clone $payments)->where(function (Builder $query) use ($category, $section): void {
                    if ($category === 'other') {
                        $query->whereNotNull('kind')->whereNotIn('kind', ['receipt', 'recollection', 'reversal', 'restoration', 'refund']);
                    } else {
                        $query->whereIn('kind', $section['kinds']);
                        if (in_array($category, ['receipts', 'corrections'])) {
                            $query->orWhere(fn (Builder $legacy) => $legacy->whereNull('kind')->where('amount_cents', $category === 'receipts' ? '>=' : '<', 0));
                        }
                    }
                })->with(['order:id,slug,reference', 'user:id,slug,name', 'originalEntry:id,slug,created_at'])->latest()->orderByDesc('id')->paginate(10, ['*'], $category === 'receipts' ? 'payments_page' : 'payment_'.$category.'_page')->withQueryString();
                $paymentSections[$category] = ['label' => $section['label'], 'entries' => $entries];
            }
        }
        $customers = User::where('role', UserRole::Customer)->when($request->user()->role !== UserRole::Admin, fn ($q) => $q->whereIn('id', (clone $orders)->select('customer_id')))->orderBy('name')->get(['id', 'slug', 'name', 'email']);

        return view('balance.index', [
            'restorationTotal' => $restorationTotal, 'refundTotal' => $refundTotal,
            'legacyReversals' => (clone $payments)->where('amount_cents', '<', 0)->whereNull('original_entry_id')->with(['order' => fn ($q) => $q->withDisplayIdentity(), 'user:id,slug,name'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'legacy_reversals_page')->withQueryString(),
            'reversalEntries' => ReceiptReversal::whereHas('order', fn ($q) => $q->financialFor($request->user())->when($customerIds, fn ($q) => $q->whereIn('customer_id', $customerIds)))->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $match) => $match->where('reason', 'like', $term)->orWhere('restore_reason', 'like', $term)->orWhereHas('order', fn (Builder $order) => $this->searchOrder($order, $term))))->where(fn ($q) => $q->whereBetween('created_at', $period->utcRange())->orWhereBetween('restored_at', $period->utcRange())->orWhereHas('payments', fn ($entries) => $entries->where('kind', 'refund')->whereBetween('created_at', $period->utcRange())))->with(['order' => fn ($q) => $q->withDisplayIdentity()->withReceiptLedger(), 'user:id,slug,name', 'restoredBy:id,slug,name', 'payments.originalEntry'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'reversals_page')->withQueryString(),
            'ledger' => $ledger, 'receiptSort' => $receiptSort, 'search' => $search, 'cashBalance' => $cashBalance, 'manualSections' => $manualSections, 'paymentSections' => $paymentSections,
            'cashMovementBalance' => $cashAdditions, 'operatingResult' => $operatingNet, 'finalNet' => $operatingNet + $pendingImpact + $cashAdditions,
            'deliveredCount' => (clone $orders)->where('status', OrderStatus::Delivered)->whereBetween('delivered_at', $period->utcRange())->count(),
            'pendingEntries' => $ledger === 'pending' ? (clone $pendingQuery)->with(['customer:id,slug,name', 'order:id,slug,reference'])->latest('occurred_on')->orderByDesc('id')->paginate(10, ['*'], 'pending_page')->withQueryString() : null,
            'selectedCustomerSlugs' => $selectedCustomerSlugs, 'reversalTotal' => $reversalTotal, 'registeredIncome' => $registeredIncome,
            'reversedCount' => (clone $reversed)->count(),
            'manualEntries' => $ledger === 'cash' ? (clone $manual)->with(['user:id,slug,name', 'customer:id,slug,name', 'order:id,slug,reference'])->latest('occurred_on')->orderByDesc('id')->paginate(10, ['*'], 'cash_page')->withQueryString() : null,
            'grossIncome' => $grossIncome, 'totalExpenses' => $totalExpenses, 'cashAdditions' => $cashAdditions,
            'receiptEconomics' => $receiptEconomics, 'shippingEconomics' => app(ShippingEconomics::class)->summarize($completed), 'period' => $period, 'customers' => $customers, 'extraIncome' => $extraIncome, 'extraExpenses' => $extraExpenses, 'adjustments' => $adjustments, 'operatingNet' => $operatingNet, 'pendingIncoming' => $pendingIncoming, 'pendingOutgoing' => $pendingOutgoing, 'pendingImpact' => $pendingImpact,
            'pendingCount' => (clone $openPending)->count(),
            'generalEntries' => $generalQuery->with(['account', 'user'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'general_page')->withQueryString(), 'summaries' => $summaries, 'earned' => (int) (clone $completed)->sum('price_cents'), 'completedCount' => (clone $completed)->count(),
            'cancelledCount' => (clone $orders)->where('status', OrderStatus::Cancelled)->whereBetween('updated_at', $period->utcRange())->count(),
            'outstanding' => $outstanding, 'generalReceipts' => (int) $generalReceipts, 'cash' => $cash, 'spent' => $spent, 'net' => $cash - $spent,
            'orders' => (clone $completed)->whereNull('paid_at')->whereNull('receipt_voided_at')->withDisplayIdentity()->withReceiptLedger()->orderBy($receiptColumn, $receiptDirection)->orderByDesc('id')->paginate(10, ['*'], 'orders_page')->withQueryString(),
            'reversedOrders' => (clone $reversed)->whereDoesntHave('receiptReversals')->withDisplayIdentity()->withReceiptLedger()->latest('receipt_voided_at')->orderByDesc('id')->paginate(10, ['*'], 'reversed_page')->withQueryString(),
            'recordedOrders' => (clone $orders)->where('status', OrderStatus::Delivered)->whereNull('receipt_voided_at')->whereBetween('paid_at', $period->utcRange())->withDisplayIdentity()->withReceiptLedger()->latest('delivered_at')->orderByDesc('id')->paginate(10, ['*'], 'recorded_page')->withQueryString(),
            'expenses' => $expenses->with(['user:id,slug,name', 'pendingSettlement.account'])->latest('spent_on')->orderByDesc('id')->paginate(10, ['*'], 'expenses_page')->withQueryString(),
            'payments' => $payments->with(['order:id,slug,reference', 'user:id,slug,name', 'originalEntry:id,slug,created_at'])->latest()->orderByDesc('id')->paginate(10, ['*'], 'payments_page')->withQueryString(),
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
