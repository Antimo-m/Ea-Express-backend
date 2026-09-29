<?php

namespace App\Http\Controllers;

use App\Actions\NotifyAccountingParticipants;
use App\Actions\RecordEconomicAudit;
use App\Http\Requests\PendingAccountRequest;
use App\Http\Requests\PendingAccountUpdateRequest;
use App\Http\Requests\PendingSettlementRequest;
use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\Support\AccountingPeriod;
use App\Support\Money;
use App\Support\ShippingEconomics;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PendingAccountController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validate(['id' => ['nullable', 'integer', 'min:1'], 'direction' => ['nullable', 'in:incoming,outgoing'], 'state' => ['nullable', 'in:open,partially_paid,paid,cancelled'], 'q' => ['nullable', 'string', 'max:150'], 'sort' => ['nullable', 'in:recent,due,remaining']]);
        $query = PendingAccount::query()->when($data['id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($search) => $search->where('subject', 'like', '%'.$term.'%')->orWhere('description', 'like', '%'.$term.'%')->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$term.'%'))))
            ->when($data['state'] ?? null, fn ($q, $state) => $q->where('state', $state));
        $totals = (clone $query)->whereIn('state', ['open', 'partially_paid'])->selectRaw('direction, SUM(amount_cents - settled_cents) AS remaining')->groupBy('direction')->pluck('remaining', 'direction');
        $list = (clone $query)->when($data['direction'] ?? null, fn ($q, $direction) => $q->where('direction', $direction))
            ->with(['customer:id,name', 'order:id,reference,shipping_type', 'settlements' => fn ($q) => $q->with('user:id,name')->latest()->orderByDesc('id')]);
        match ($data['sort'] ?? 'recent') {
            'due' => $list->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')->orderBy('due_on'),
            'remaining' => $list->orderByRaw('(amount_cents - settled_cents) DESC'),
            default => $list->latest(),
        };
        $accounts = $list->orderByDesc('id')->paginate(20)->withQueryString();
        $customers = User::where('role', UserRole::Customer)->orderBy('name')->get(['id', 'name']);

        return view('pending.index', compact('accounts', 'totals', 'customers'));
    }

    public function store(PendingAccountRequest $request): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validated();
        $cents = Money::cents($data['amount']);
        abort_unless($cents > 0, 422, 'Importo maggiore di zero richiesto.');
        unset($data['amount']);
        DB::transaction(function () use ($data, $cents, $request): void {
            $period = AccountingPeriod::lock();
            $period->assertOpen($data['occurred_on']);
            if (! empty($data['order_id'])) {
                $order = Order::query()->lockForUpdate()->findOrFail($data['order_id']);
                $remaining = $order->price_cents - (int) PaymentEntry::where('order_id', $order->id)->sum('amount_cents');
                abort_unless($data['direction'] === 'incoming' && $order->status === OrderStatus::Delivered && $order->price_cents !== null && ! $order->paid_at && ! $order->receipt_voided_at && $remaining === $cents, 422, 'Per una spedizione usa il residuo esatto di una consegna non saldata, in entrata.');
                abort_if(PendingAccount::where('order_id', $order->id)->exists(), 409, 'Esiste già un sospeso per questa spedizione.');
                abort_if(! empty($data['customer_id']) && (int) $data['customer_id'] !== $order->customer_id, 422, 'Il cliente non corrisponde alla spedizione.');
                $data['customer_id'] = $order->customer_id;
            }
            $account = PendingAccount::create([...$data, 'amount_cents' => $cents, 'created_by' => $request->user()->id]);
            app(RecordEconomicAudit::class)->handle($request->user(), $account, 'pending.created', null, $account->toArray());
        }, 3);

        return $this->response($request, 'Sospeso registrato.');
    }

    public function update(PendingAccountUpdateRequest $request, PendingAccount $account): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validated();
        DB::transaction(function () use ($request, $account, $data): void {
            $period = AccountingPeriod::lock();
            $locked = PendingAccount::query()->when($request->filled('id'), fn ($q) => $q->whereKey($request->integer('id')))->lockForUpdate()->findOrFail($account->id);
            abort_unless($locked->version === (int) $data['version'] && in_array($locked->state, ['open', 'partially_paid']), 409, 'Sospeso aggiornato o chiuso.');
            $period->assertOpen($locked->occurred_on);
            $before = $locked->toArray();
            if ($data['action'] === 'cancel') {
                abort_if($locked->settled_cents > 0, 422, 'Un sospeso con pagamenti conserva i movimenti: non può essere annullato.');
                $locked->state = 'cancelled';
            } else {
                if (isset($data['amount'])) {
                    $amount = Money::cents($data['amount']);
                    abort_unless($amount > $locked->settled_cents && (! $locked->order_id || $amount === $locked->amount_cents), 422, 'L’importo deve superare quanto già saldato. Per gli ordini resta il prezzo concordato.');
                    $locked->amount_cents = $amount;
                }
                $locked->fill(collect($data)->only(['subject', 'description', 'due_on', 'notes'])->all());
            }
            $locked->version++;
            $locked->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $locked, 'pending.'.$data['action'], $before, $locked->toArray());
        }, 3);

        return $this->response($request, 'Sospeso aggiornato.');
    }

    public function settle(PendingSettlementRequest $request, PendingAccount $account): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->role === UserRole::Admin, 403);
        $data = $request->validated();
        $data['note'] = '';
        $data['method'] = 'cash';
        $cents = Money::cents($data['amount']);
        DB::transaction(function () use ($request, $account, $data, $cents): void {
            $period = AccountingPeriod::lock();
            $period->assertOpen(now());
            // Use the same lock order as direct receipts: order, then pending account.
            $order = $account->order_id ? Order::query()->lockForUpdate()->findOrFail($account->order_id) : null;
            $locked = PendingAccount::query()->lockForUpdate()->findOrFail($account->id);
            $existing = $locked->settlements()->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless($existing->amount_cents === $cents && $existing->method === $data['method'] && (! $order || PaymentEntry::where('pending_settlement_id', $existing->id)->value('ea_amount_cents') === app(ShippingEconomics::class)->retainedAmount($order, $cents, $data['ea_amount'] ?? null)) && $existing->note === $data['note'], 409, 'Chiave già utilizzata con dati diversi.');

                return;
            }
            abort_unless($locked->version === (int) $data['version'] && in_array($locked->state, ['open', 'partially_paid']), 409, 'Sospeso aggiornato o chiuso.');
            abort_unless($cents > 0 && $cents <= $locked->amount_cents - $locked->settled_cents, 422, 'Importo non valido o superiore al residuo.');
            $before = $locked->toArray();
            $settlement = $locked->settlements()->create(['user_id' => $request->user()->id, 'amount_cents' => $cents, 'method' => $data['method'], 'note' => $data['note'], 'submission_key' => $data['submission_key']]);
            if ($order) {
                abort_unless($order->status === OrderStatus::Delivered && ! $order->paid_at && ! $order->receipt_voided_at, 409, 'La spedizione non è saldabile.');
                $entry = new PaymentEntry;
                $entry->order_id = $order->id;
                $entry->user_id = $request->user()->id;
                $entry->amount_cents = $cents;
                $entry->ea_amount_cents = app(ShippingEconomics::class)->retainedAmount($order, $cents, $data['ea_amount'] ?? null);
                $entry->method = $data['method'];
                $entry->note = $data['note'];
                $entry->pending_settlement_id = $settlement->id;
                $entry->save();
                app(RecordEconomicAudit::class)->handle($request->user(), $entry, 'payment.received', null, $entry->toArray());
                $paid = (int) PaymentEntry::where('order_id', $order->id)->sum('amount_cents');
                abort_if($paid > $order->price_cents, 409, 'Il totale supererebbe il prezzo concordato.');
                if ($paid === $order->price_cents) {
                    $order->paid_at = now();
                    $order->paid_by = $request->user()->id;
                }
                $order->version++;
                $order->save();
                app(NotifyAccountingParticipants::class)->handle($order, 'Pagamento registrato: '.Money::format($cents), $request->user()->id);
            } elseif ($locked->direction === 'outgoing') {
                $expense = new Expense(['description' => mb_substr($locked->subject.' — '.$locked->description, 0, 200), 'spent_on' => now('Europe/Rome')->toDateString()]);
                $expense->user_id = $request->user()->id;
                $expense->amount_cents = $cents;
                $expense->pending_settlement_id = $settlement->id;
                $expense->save();
                app(RecordEconomicAudit::class)->handle($request->user(), $expense, 'expense.created', null, $expense->toArray());
            }
            app(RecordEconomicAudit::class)->handle($request->user(), $settlement, 'settlement.created', null, $settlement->toArray());
            $locked->refreshSettlementState();
            $locked->version++;
            $locked->save();
            app(RecordEconomicAudit::class)->handle($request->user(), $locked, 'pending.settled', $before, [...$locked->toArray(), 'settlement_id' => $settlement->id]);
        }, 3);

        return $this->response($request, 'Pagamento registrato una sola volta nel bilancio.');
    }

    /** @return array<string,array<mixed>> */
    private function response(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['message' => $message, 'redirect' => route('pending.index')]) : redirect()->route('pending.index')->with('status', $message);
    }
}
