<?php

namespace App\Actions;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\PendingSettlement;
use App\Models\User;
use App\OrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectPendingSettlement
{
    public function __construct(private RecordEconomicAudit $audit, private NotifyOrderParticipants $notify) {}

    public function handle(User $actor, PendingAccount $account, PendingSettlement $settlement, int $amount, string $reason, int $version): void
    {
        DB::transaction(function () use ($actor, $account, $settlement, $amount, $reason, $version): void {
            $order = $account->order_id ? Order::lockForUpdate()->findOrFail($account->order_id) : null;
            $locked = PendingAccount::lockForUpdate()->findOrFail($account->id);
            $payment = $locked->settlements()->lockForUpdate()->findOrFail($settlement->id);
            abort_unless($locked->version === $version && $locked->state !== 'cancelled', 409, 'Il sospeso è stato aggiornato o annullato. Ricarica i dati.');
            $others = (int) $locked->settlements()->where('id', '!=', $payment->id)->sum('amount_cents');
            if ($amount < 1 || $amount + $others > $locked->amount_cents) {
                throw ValidationException::withMessages(['amount' => 'Inserisci un importo positivo che non superi il debito totale, considerando gli altri pagamenti.']);
            }
            $before = $locked->toArray();
            $paymentBefore = $payment->toArray();
            if ($order) {
                abort_unless($order->status === OrderStatus::Delivered && ! $order->receipt_voided_at, 409, 'La spedizione non consente la correzione del saldo.');
                $entry = PaymentEntry::where('pending_settlement_id', $payment->id)->lockForUpdate()->first();
                abort_unless($entry && $entry->order_id === $order->id && $entry->amount_cents === $payment->amount_cents, 409, 'Movimento di incasso mancante o non coerente.');
                $entryBefore = $entry->toArray();
                $entry->amount_cents = $amount;
                $entry->save();
                $total = (int) PaymentEntry::where('order_id', $order->id)->sum('amount_cents');
                abort_unless($order->price_cents !== null && $total >= 0 && $total <= $order->price_cents, 409, 'Il totale degli incassi non coincide con la tariffa della spedizione.');
                $orderBefore = $order->only(['paid_at', 'paid_by', 'version']);
                $paid = $total === $order->price_cents;
                $order->paid_at = $paid ? ($order->paid_at ?? $entry->created_at) : null;
                $order->paid_by = $paid ? ($order->paid_by ?? $actor->id) : null;
                $order->version++;
                $order->save();
                $this->audit->handle($actor, $entry, 'payment.corrected', $entryBefore, [...$entry->toArray(), 'reason' => $reason]);
                $this->audit->handle($actor, $order, 'receipt.corrected', $orderBefore, [...$order->only(array_keys($orderBefore)), 'reason' => $reason]);
                $this->notify->handle($order, 'Pagamento corretto; saldo della spedizione aggiornato', $actor->id);
            } elseif ($locked->direction === 'outgoing') {
                $expense = Expense::where('pending_settlement_id', $payment->id)->lockForUpdate()->first();
                abort_unless($expense && ! $expense->voided_at && $expense->amount_cents === $payment->amount_cents, 409, 'Spesa collegata mancante o non coerente.');
                $expenseBefore = $expense->toArray();
                $expense->amount_cents = $amount;
                $expense->version++;
                $expense->save();
                $this->audit->handle($actor, $expense, 'expense.corrected', $expenseBefore, [...$expense->toArray(), 'reason' => $reason]);
            }
            $payment->amount_cents = $amount;
            $payment->save();
            $locked->refreshSettlementState();
            $locked->version++;
            $locked->save();
            $this->audit->handle($actor, $payment, 'settlement.corrected', $paymentBefore, [...$payment->toArray(), 'reason' => $reason]);
            $this->audit->handle($actor, $locked, 'pending.corrected', $before, [...$locked->toArray(), 'settlement_id' => $payment->id, 'reason' => $reason]);
        }, 3);
    }
}
