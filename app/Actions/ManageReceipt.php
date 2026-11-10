<?php

namespace App\Actions;

use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\ReceiptReversal;
use App\Models\User;
use App\OrderStatus;
use App\Support\AccountingPeriod;
use App\Support\Money;
use App\Support\OrderPrice;
use App\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageReceipt
{
    public function __construct(private RecordEconomicAudit $audit, private NotifyAccountingParticipants $notify, private OrderPrice $price) {}

    /** @param array{action: string, version: int|string, note?: string, received_amount?: string} $data */
    public function handle(User $actor, Order $order, array $data): string
    {
        return DB::transaction(function () use ($actor, $order, $data): string {
            AccountingPeriod::lock()->assertOpen(now());
            $actor = User::lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->is_active && $actor->role === UserRole::Admin, 403);
            $order = Order::financialFor($actor)->lockForUpdate()->findOrFail($order->id);
            $pending = PendingAccount::where('order_id', $order->id)->lockForUpdate()->first();
            $action = $data['action'];
            if ($order->status !== OrderStatus::Delivered || $order->version !== (int) $data['version'] || (in_array($action, ['restore', 'refund']) ? ! $order->receipt_voided_at : $order->receipt_voided_at !== null)) {
                throw ValidationException::withMessages(['action' => 'Operazione non disponibile. Ricarica il bilancio per controllare lo stato dell’incasso.']);
            }
            $payments = $order->payments()->lockForUpdate()->orderBy('id')->get();
            abort_if($order->payments()->where('kind', 'refund')->whereDoesntHave('audits', fn ($audit) => $audit->where(fn ($q) => $q->whereIn('action', ['payment.refund_verified', 'payment.refund_disputed'])->orWhere('after->cash_confirmation', 'explicit_payment')))->exists(), 409, 'Rimborso storico senza attestazione separata: riconcilia la situazione effettiva del denaro prima di proseguire.');
            abort_if($payments->contains(fn ($entry) => $entry->amount_cents < 0 && ! $entry->original_entry_id), 409, 'Storni storici senza collegamento: occorre riconciliare i movimenti prima di operare.');
            $net = (int) $payments->sum('amount_cents');
            $cash = (int) $payments->sum(fn ($entry) => $entry->cash_amount_cents ?? $entry->amount_cents);
            abort_if($net < 0 || $cash < 0 || $net > (int) $order->price_cents || $cash > (int) $order->price_cents, 409, 'Movimenti non coerenti: verifica lo storico prima di proseguire.');
            $before = $order->only(['paid_at', 'paid_by', 'receipt_voided_at', 'receipt_voided_by', 'receipt_void_reason', 'version']);
            $reason = $data['note'] ?? '';
            if ($action === 'receive') {
                abort_if($pending && $pending->state !== 'cancelled', 409, 'Registra i pagamenti nella sezione Sospesi per questa spedizione.');
                $this->price->assertApproved($order);
                abort_if($order->paid_at || $order->price_cents === null, 422, 'Incasso già registrato o tariffa mancante.');
                $remaining = $order->price_cents - $cash;
                $amount = $order->shipping_type === 'external' ? Money::cents($data['received_amount']) : $remaining;
                if ($amount <= 0 || $amount !== $remaining) {
                    throw ValidationException::withMessages(['received_amount' => 'L’incasso diretto deve saldare esattamente il residuo con un importo positivo. Per i pagamenti parziali usa la sezione Sospesi.']);
                }
                $verifiedResidual = $net > 0 && EconomicAudit::where('entity_type', 'orders')->where('entity_id', $order->id)->where('action', 'receipt.reconciled')->whereIn('after->resolution', ['partial', 'refunded'])->exists();
                abort_if($net > 0 && ! $payments->contains('kind', 'refund') && ! $verifiedResidual, 409, 'Movimenti già presenti: verifica il residuo nei Sospesi.');
                $economic = $order->price_cents - $net;
                $refundedReversal = $order->receiptReversals()->whereNotNull('restored_at')->whereHas('payments', fn ($q) => $q->where('kind', 'refund'))->latest('id')->first();
                $this->entry($actor, $order, $economic === 0 ? 'recollection' : 'receipt', $economic, $economic, $amount, '', 'receive:'.$order->slug.':'.$order->version, $refundedReversal, $refundedReversal?->payments()->where('kind', 'refund')->latest('id')->first());
                $order->paid_at = now();
                $order->paid_by = $actor->id;
                $message = $refundedReversal ? 'Nuovo incasso effettivo registrato dopo il rimborso, senza duplicare il ricavo.' : 'Movimento registrato nel bilancio.';
            } elseif ($action === 'reverse') {
                abort_if(ReceiptReversal::where('order_id', $order->id)->whereNull('restored_at')->exists(), 409, 'Esiste già uno storno aperto.');
                abort_if($order->paid_at && $net !== $order->price_cents, 409, 'L’incasso segnato come saldato non coincide con i movimenti: riconcilia lo storico.');
                $reversal = new ReceiptReversal;
                $reversal->order_id = $order->id;
                $reversal->user_id = $actor->id;
                $reversal->order_version = $order->version;
                $reversal->original_amount_cents = $order->price_cents ?? 0;
                $reversal->collected_cents = $cash;
                $reversal->corrected_cents = $net;
                $reversal->original_paid_at = $order->paid_at;
                $reversal->original_paid_by = $order->paid_by;
                $reversal->pending_state = $pending?->state;
                $reversal->reason = $reason;
                $reversal->save();
                $corrected = 0;
                foreach ($payments->where('amount_cents', '>', 0) as $original) {
                    $children = $payments->where('original_entry_id', $original->id)->where('kind', 'reversal');
                    $remaining = $original->amount_cents + $children->sum('amount_cents');
                    if ($remaining <= 0) {
                        continue;
                    }
                    $retained = ($original->ea_amount_cents ?? $original->amount_cents) + $children->sum('ea_amount_cents');
                    $this->entry($actor, $order, 'reversal', -$remaining, -$retained, 0, $reason, 'reverse:'.$reversal->slug.':'.$original->slug, $reversal, $original);
                    $corrected += $remaining;
                }
                abort_unless($corrected === $net, 409, 'Collegamenti contabili non coerenti: nessuna rettifica applicata.');
                if ($pending && $pending->state !== 'cancelled') {
                    $this->pendingState($actor, $pending, 'cancelled', $reason);
                }
                $order->receipt_voided_at = $reversal->created_at;
                $order->receipt_voided_by = $actor->id;
                $order->receipt_void_reason = $reason;
                $order->paid_at = null;
                $order->paid_by = null;
                $message = $cash > 0 ? 'Storno contabile registrato. Il denaro resta in cassa: conferma separatamente il rimborso soltanto quando pagato.' : 'Storno registrato senza movimenti di cassa.';
                $this->audit->handle($actor, $reversal, 'receipt.reversal_created', null, $reversal->toArray());
            } else {
                $reversal = ReceiptReversal::where('order_id', $order->id)->whereNull('restored_at')->lockForUpdate()->first();
                abort_unless($reversal && $net === 0, 409, 'Storno storico o movimenti non coerenti: riconcilia i collegamenti prima di proseguire.');
                $negative = $payments->where('receipt_reversal_id', $reversal->id)->where('kind', 'reversal');
                abort_unless(-$negative->sum('amount_cents') === $reversal->corrected_cents, 409, 'Rettifica non coerente con i movimenti collegati.');
                if ($action === 'refund') {
                    abort_unless($reversal->collected_cents > 0, 422, 'Nessun incasso effettivo da rimborsare.');
                    $paidRefund = -(int) $payments->where('receipt_reversal_id', $reversal->id)->whereIn('kind', ['refund', 'cash_reconciliation'])->sum('cash_amount_cents');
                    abort_if($paidRefund >= $reversal->collected_cents, 409, 'Rimborso già registrato.');
                    $amount = min($cash, $reversal->collected_cents - $paidRefund);
                    abort_unless($amount > 0, 422, 'Nessun incasso effettivo da rimborsare.');
                    $this->entry($actor, $order, 'refund', 0, 0, -$amount, $reason, 'refund:'.$reversal->slug.':'.$order->version, $reversal);
                    $message = 'Rimborso pagato registrato nella cassa. Il netto contabile non viene sottratto una seconda volta.';
                } else {
                    $this->price->assertApproved($order);
                    abort_if($cash > 0, 409, 'Registra prima il rimborso dello storno: il ripristino non deve creare un nuovo incasso.');
                    foreach ($negative as $entry) {
                        $this->entry($actor, $order, 'restoration', 0, 0, 0, $reason, 'restore:'.$entry->slug, $reversal, $entry);
                    }
                    $reversalBefore = $reversal->toArray();
                    $reversal->restored_at = now();
                    $reversal->restored_by = $actor->id;
                    $reversal->restore_reason = $reason;
                    $reversal->save();
                    $this->audit->handle($actor, $reversal, 'receipt.reversal_restored', $reversalBefore, $reversal->toArray());
                    $order->receipt_voided_at = null;
                    $order->receipt_voided_by = null;
                    $order->receipt_void_reason = null;
                    $order->paid_at = null;
                    $order->paid_by = null;
                    $message = 'Storno ripristinato. L’incasso torna da registrare; il totale lordo resta invariato fino a un nuovo pagamento effettivo.';
                }
            }
            $order->version++;
            $order->save();
            $this->audit->handle($actor, $order, 'receipt.'.$action, $before, [...$order->only(array_keys($before)), 'reason' => $reason]);
            $this->notify->handle($order, $message, $actor->id);

            return $message;
        }, 3);
    }

    private function entry(User $actor, Order $order, string $kind, int $amount, int $retained, int $cash, string $reason, string $operationKey, ?ReceiptReversal $reversal = null, ?PaymentEntry $original = null): PaymentEntry
    {
        $entry = new PaymentEntry;
        $entry->order_id = $order->id;
        $entry->user_id = $actor->id;
        $entry->kind = $kind;
        $entry->amount_cents = $amount;
        $entry->ea_amount_cents = $retained;
        $entry->cash_amount_cents = $cash;
        $entry->receipt_reversal_id = $reversal?->id;
        $entry->original_entry_id = $original?->id;
        $entry->operation_key = $operationKey;
        $entry->method = $original?->method ?? 'cash';
        $entry->note = $reason;
        $entry->save();
        $this->audit->handle($actor, $entry, match ($kind) {
            'reversal' => 'payment.reversed', 'restoration' => 'payment.restored', 'refund' => 'payment.refunded', default => 'payment.received'
        }, null, [...$entry->toArray(), ...($kind === 'refund' ? ['cash_confirmation' => 'explicit_payment'] : [])]);

        return $entry;
    }

    private function pendingState(User $actor, PendingAccount $pending, string $state, string $reason): void
    {
        $before = $pending->toArray();
        $pending->state = $state;
        $pending->version++;
        $pending->save();
        $this->audit->handle($actor, $pending, 'pending.receipt_'.$state, $before, [...$pending->toArray(), 'reason' => $reason]);
    }
}
