<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\FinancialMovement;
use App\Models\PaymentEntry;
use App\Models\PendingSettlement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialRegister
{
    public function page(string $category, Builder $payments, Builder $expenses, Builder $manual, Builder $general, Carbon $referenceDate): LengthAwarePaginator
    {
        $sources = [];
        if (in_array($category, ['payments', 'refunds', 'history'])) {
            $query = clone $payments;
            match ($category) {
                'payments' => $query->whereRaw(ReceiptLedger::CashExpression.' > 0')->where(fn ($q) => $q->whereIn('kind', ['receipt', 'recollection'])->orWhereNull('kind')),
                'refunds' => $query->verifiedRefund()->where('cash_amount_cents', '<', 0),
                default => $query->where(fn ($q) => $q->whereNotIn('kind', ['receipt', 'recollection', 'refund'])->orWhere(fn ($q) => $q->whereNull('kind')->where('amount_cents', '<', 0))->orWhere(fn ($q) => $q->where('kind', 'refund')->whereDoesntHave('audits', fn ($audit) => $audit->where('action', 'payment.refund_verified')->orWhere('after->cash_confirmation', 'explicit_payment')))),
            };
            if ($category === 'history') {
                $query->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', 'reversal')->orWhereNull('receipt_reversal_id')->orWhereHas('reversal', fn ($q) => $q->whereNotNull('restored_at')->where('restored_at', '<=', $referenceDate->copy()->utc())));
            }
            $sources[] = $query->selectRaw("id, 'payment' AS source, created_at AS occurred_at")->toBase();
        }
        if ($category === 'payments') {
            $sources[] = (clone $general)->selectRaw("id, 'settlement' AS source, created_at AS occurred_at")->toBase();
        }
        if ($category === 'expenses') {
            $sources[] = (clone $expenses)->selectRaw("id, 'expense' AS source, spent_on AS occurred_at")->toBase();
        }
        $kinds = match ($category) {
            'payments' => ['income'], 'expenses' => ['extra_expense'], 'cash' => ['cash'],
            'history' => ['adjustment_in', 'adjustment_out'], default => [],
        };
        $sources[] = (clone $manual)->whereIn('kind', $kinds)->selectRaw("id, 'manual' AS source, occurred_on AS occurred_at")->toBase();
        $union = array_shift($sources);
        foreach ($sources as $source) {
            $union->unionAll($source);
        }
        $page = DB::query()->fromSub($union, 'register')->orderByDesc('occurred_at')->orderBy('source')->orderByDesc('id')
            ->paginate(10, ['*'], 'movements_page')->withQueryString();
        $ids = $page->getCollection()->groupBy('source')->map(fn ($rows) => $rows->pluck('id'));
        $models = [
            'payment' => PaymentEntry::whereIn('id', $ids['payment'] ?? [])->with(['order' => fn ($q) => $q->withDisplayIdentity()->withReceiptLedger()->with('payments'), 'user', 'originalEntry', 'reversal', 'audits'])->get()->keyBy('id'),
            'settlement' => PendingSettlement::whereIn('id', $ids['settlement'] ?? [])->with(['account.customer', 'user'])->get()->keyBy('id'),
            'expense' => Expense::whereIn('id', $ids['expense'] ?? [])->with(['pendingSettlement.account.customer', 'user'])->get()->keyBy('id'),
            'manual' => FinancialMovement::whereIn('id', $ids['manual'] ?? [])->with(['customer', 'order', 'user'])->get()->keyBy('id'),
        ];

        return $page->through(fn ($row): array => $this->present($models[$row->source][$row->id], $row->source));
    }

    /** @return array<string, mixed> */
    private function present(PaymentEntry|PendingSettlement|Expense|FinancialMovement $entry, string $source): array
    {
        $order = $source === 'payment' || $source === 'manual' ? $entry->order : null;
        $account = match ($source) {
            'settlement' => $entry->account, 'expense' => $entry->pendingSettlement?->account, default => null,
        };
        $cash = match ($source) {
            'payment' => $entry->cash_amount_cents ?? $entry->amount_cents,
            'expense' => $entry->voided_at ? 0 : -$entry->amount_cents,
            default => $entry->voided_at ? 0 : $entry->amount_cents,
        };
        $economic = match ($source) {
            'payment', 'settlement' => $entry->amount_cents,
            'expense' => $cash,
            default => $entry->kind === 'cash' ? 0 : $cash,
        };
        $disputedRefund = $source === 'payment' && $entry->kind === 'refund' && $entry->audits->contains('action', 'payment.refund_disputed');
        $reason = $disputedRefund ? 'Uscita storica non eseguita: compensazione documentata nel periodo aperto. La registrazione originale è conservata.' : null;
        if ($source === 'payment' && $entry->kind === 'recollection' && $economic === 0) {
            $reason = 'Effetto economico da verificare nello storico.';
            if ($entry->reversal && $entry->reversal->restored_at && $entry->order->payments->filter(fn ($previous): bool => $previous->id < $entry->id && $previous->created_at->lessThanOrEqualTo($entry->created_at))->sum('amount_cents') >= $entry->cash_amount_cents) {
                $reason = 'Ricavo già contabilizzato in un movimento precedente.';
            }
        }

        return [
            'slug' => $entry->slug, 'source' => $source, 'model' => $entry,
            'type' => match ($source) {
                'payment' => $disputedRefund ? 'Rimborso storico non eseguito · rettificato' : ($entry->kind === 'refund' && ! $entry->audits->contains(fn ($audit): bool => $audit->action === 'payment.refund_verified' || ($audit->after['cash_confirmation'] ?? null) === 'explicit_payment') ? 'Rimborso storico da verificare' : $entry->label()), 'settlement' => 'Incasso senza ordine', 'expense' => $account ? 'Pagamento debito' : 'Spesa operativa', default => FinancialMovement::Kinds[$entry->kind]
            },
            'description' => $order?->reference ?? $account?->subject ?? $entry->description,
            'customer' => $order?->displayName() ?? $account?->customer?->name ?? ($source === 'manual' ? $entry->customer?->name : null),
            'date' => match ($source) {
                'expense' => $entry->spent_on, 'manual' => $entry->occurred_on, default => $entry->created_at->copy()->timezone('Europe/Rome')
            },
            'amount' => match ($source) {
                'expense' => -$entry->amount_cents, 'manual', 'settlement' => $entry->amount_cents,
                default => $cash === 0 ? $economic : $cash,
            },
            'cash' => $cash, 'economic' => $economic, 'reason' => $reason,
            'operator' => $entry->user?->name, 'note' => $entry->note ?? $entry->notes,
            'state' => $disputedRefund ? 'Rettificato' : ($entry->voided_at ? 'Annullata' : 'Registrato'),
            'audit_type' => $entry->getTable(), 'order' => $order, 'account' => $account,
            'original' => $source === 'payment' ? $entry->originalEntry : null,
        ];
    }
}
