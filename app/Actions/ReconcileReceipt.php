<?php

namespace App\Actions;

use App\Models\EconomicAudit;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PendingAccount;
use App\Models\User;
use App\OrderStatus;
use App\Support\AccountingPeriod;
use App\Support\OrderPrice;
use App\UserRole;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconcileReceipt
{
    public function __construct(private RecordEconomicAudit $audit, private NotifyAccountingParticipants $notify, private OrderPrice $price) {}

    /** @param array{version: int|string, resolution: string, note: string, entry_slug?: string} $data */
    public function handle(User $actor, Order $order, array $data): string
    {
        return DB::transaction(function () use ($actor, $order, $data): string {
            AccountingPeriod::lock()->assertOpen(now());
            $actor = User::lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->is_active && $actor->role === UserRole::Admin, 403);
            $order = Order::financialFor($actor)->lockForUpdate()->findOrFail($order->id);
            if (in_array($data['resolution'], ['refund_paid', 'refund_unpaid'])) {
                return $this->refund($actor, $order, $data);
            }
            abort_unless($order->status === OrderStatus::Delivered && ! $order->receipt_voided_at && $order->version === (int) $data['version'], 409, 'Stato cambiato o storno ancora attivo. Verifica nuovamente lo storico.');
            $pending = PendingAccount::where('order_id', $order->id)->lockForUpdate()->first();
            abort_if($data['resolution'] !== 'links' && $pending && $pending->state !== 'cancelled', 409, 'Questa spedizione ha un sospeso attivo: verifica i suoi pagamenti prima di riconciliare lo stato dell’incasso.');
            $payments = $order->payments()->lockForUpdate()->orderBy('created_at')->orderBy('id')->get();
            $net = (int) $payments->sum('amount_cents');
            $cash = (int) $payments->sum(fn (PaymentEntry $entry): int => $entry->cash_amount_cents ?? $entry->amount_cents);
            $price = (int) $order->price_cents;
            abort_unless($price > 0 && $net >= 0 && $cash >= 0 && $net <= $price && $cash <= $price, 409, 'Importi non coerenti: occorre una verifica documentale dei movimenti.');
            $links = $this->links($payments);
            $before = $order->only(['paid_at', 'paid_by', 'version']);
            $resolution = $data['resolution'];
            if ($resolution === 'partial') {
                abort_unless($order->paid_at && $net === $cash && $cash < $price && $links === [], 409, 'La posizione non corrisponde a un incasso parziale verificabile.');
                $order->paid_at = null;
                $order->paid_by = null;
                $message = 'Stato riconciliato con i movimenti esistenti. Nessun incasso creato; resta da registrare soltanto il residuo effettivo.';
            } elseif ($resolution === 'links') {
                abort_unless($order->paid_at && $net === $price && $cash === $price && $links !== [], 409, 'Il saldo non è già integralmente registrato. Verifica se il denaro è stato restituito.');
                $message = 'Collegamenti storici riconciliati. Importi, date e saldo conservati; nessun nuovo incasso.';
            } else {
                $last = $links === [] ? null : $payments->firstWhere('id', array_key_last($links));
                abort_unless($resolution === 'refunded' && ! $order->paid_at && $net === 0 && $cash === 0 && $last && -$last->amount_cents === $price, 409, 'Il ripristino storico non è riconciliabile con questa attestazione.');
                abort_unless(EconomicAudit::where('entity_type', 'orders')->where('entity_id', $order->id)->where('action', 'receipt.restore')->where('created_at', '>=', $last->created_at)->exists(), 409, 'Manca la traccia del ripristino storico: verifica i documenti prima di correggere.');
                $this->price->assertApproved($order);
                $this->entry($actor, $order, 'restoration', 0, 0, 0, $data['note'], $last);
                $message = 'Ripristino riconciliato con il rimborso storico dichiarato. Nessun ricavo o denaro aggiunto; il nuovo incasso resta da registrare soltanto quando ricevuto.';
            }
            foreach ($links as $entryId => $originalId) {
                $entry = $payments->firstWhere('id', $entryId);
                $entryBefore = $entry->only(['original_entry_id', 'kind']);
                $entry->original_entry_id = $originalId;
                $entry->kind = 'reversal';
                $entry->save();
                $this->audit->handle($actor, $entry, 'payment.reconciled', $entryBefore, [...$entry->only(['original_entry_id', 'kind']), 'reason' => $data['note']]);
            }
            $order->version++;
            $order->save();
            $this->audit->handle($actor, $order, 'receipt.reconciled', $before, [...$order->only(['paid_at', 'paid_by', 'version']), 'resolution' => $resolution, 'reason' => $data['note'], 'verified_net_cents' => $net, 'verified_cash_cents' => $cash]);
            $this->notify->handle($order, $message, $actor->id);

            return $message;
        }, 3);
    }

    /** @param array{version: int|string, resolution: string, note: string, entry_slug: string} $data */
    private function refund(User $actor, Order $order, array $data): string
    {
        abort_unless($order->version === (int) $data['version'], 409, 'Ordine aggiornato: ricarica lo storico.');
        $entry = $order->payments()->where('slug', $data['entry_slug'])->where('kind', 'refund')->lockForUpdate()->firstOrFail();
        abort_if($entry->audits()->where(fn ($q) => $q->whereIn('action', ['payment.refund_verified', 'payment.refund_disputed'])->orWhere('after->cash_confirmation', 'explicit_payment'))->exists(), 409, 'Rimborso già verificato.');
        abort_unless($entry->cash_amount_cents < 0, 409, 'Importo storico non riconciliabile.');
        $before = $order->only(['version']);
        if ($data['resolution'] === 'refund_unpaid') {
            $reversal = $entry->reversal()->lockForUpdate()->first();
            abort_unless($order->receipt_voided_at && $reversal && ! $reversal->restored_at, 409, 'La rettifica richiede uno storno ancora attivo. Verifica i cicli successivi prima di compensare.');
            $payments = $order->payments()->lockForUpdate()->get();
            $cash = (int) $payments->sum(fn (PaymentEntry $payment): int => $payment->cash_amount_cents ?? $payment->amount_cents);
            abort_unless((int) $payments->sum('amount_cents') === 0 && $cash >= 0 && $cash <= $order->price_cents && $entry->amount_cents === 0, 409, 'Movimenti non coerenti: verifica l’intero ciclo prima di compensare la cassa.');
            abort_unless($cash - $entry->cash_amount_cents <= $order->price_cents, 409, 'La compensazione supererebbe il denaro ricevuto.');
            $compensation = new PaymentEntry;
            $compensation->forceFill(['order_id' => $order->id, 'user_id' => $actor->id, 'kind' => 'cash_reconciliation',
                'amount_cents' => 0, 'ea_amount_cents' => 0, 'cash_amount_cents' => -$entry->cash_amount_cents,
                'original_entry_id' => $entry->id, 'receipt_reversal_id' => $reversal->id,
                'operation_key' => 'refund-disputed:'.$entry->slug, 'method' => $entry->method, 'note' => $data['note']])->save();
            $this->audit->handle($actor, $compensation, 'payment.reconciled', null, $compensation->toArray());
        }
        $this->audit->handle($actor, $entry, $data['resolution'] === 'refund_paid' ? 'payment.refund_verified' : 'payment.refund_disputed', null, ['reason' => $data['note'], 'resolution' => $data['resolution']]);
        $order->version++;
        $order->save();
        $this->audit->handle($actor, $order, 'receipt.reconciled', $before, ['version' => $order->version, 'resolution' => $data['resolution'], 'reason' => $data['note']]);

        $message = 'Rimborso storico riconciliato con attestazione documentata. Importi originali conservati; eventuale compensazione registrata nel periodo aperto.';
        $this->notify->handle($order, $message, $actor->id);

        return $message;
    }

    /** @param Collection<int, PaymentEntry> $payments @return array<int, int> */
    private function links(Collection $payments): array
    {
        $available = [];
        $links = [];
        foreach ($payments as $entry) {
            if ($entry->amount_cents > 0) {
                $available[$entry->id] = $entry->amount_cents;
            } elseif ($entry->amount_cents < 0) {
                $originalId = $entry->original_entry_id;
                if (! $originalId) {
                    $candidates = array_keys(array_filter($available, fn (int $amount): bool => $amount === -$entry->amount_cents));
                    abort_unless(count($candidates) === 1, 409, 'Collegamento storico ambiguo: confronta le ricevute prima di riconciliare.');
                    $originalId = $candidates[0];
                    $links[$entry->id] = $originalId;
                }
                abort_unless(isset($available[$originalId]) && $available[$originalId] >= -$entry->amount_cents, 409, 'Storno privo di un incasso originale disponibile. Nessun dato modificato.');
                $available[$originalId] += $entry->amount_cents;
            }
        }

        return $links;
    }

    private function entry(User $actor, Order $order, string $kind, int $amount, int $retained, int $cash, string $reason, PaymentEntry $original): void
    {
        $entry = new PaymentEntry;
        $entry->order_id = $order->id;
        $entry->user_id = $actor->id;
        $entry->kind = $kind;
        $entry->amount_cents = $amount;
        $entry->ea_amount_cents = $retained;
        $entry->cash_amount_cents = $cash;
        $entry->original_entry_id = $original->id;
        $entry->operation_key = 'reconcile:'.$kind.':'.$order->slug.':'.$order->version;
        $entry->method = $original->method;
        $entry->note = $reason;
        $entry->save();
        $this->audit->handle($actor, $entry, 'payment.reconciled', null, $entry->toArray());
    }
}
