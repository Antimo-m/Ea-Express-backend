<x-ui.data-panel :title="$ledgerCategories[$ledger]" :count="$registerEntries->total()" description="Movimenti del periodo selezionato. Apri una registrazione per vedere importo, effetto economico, cassa e storico.">
    <div class="money-register" data-money-register="{{ $ledger }}">
        <div class="money-register-heading" aria-hidden="true"><span>Data</span><span>Causale</span><span>Cliente / riferimento</span><span>Importo</span><span>Stato</span><span>Dettagli</span></div>
        @forelse($registerEntries as $movement)
            <details class="money-row" data-cash-movement="{{ $movement['slug'] }}">
                <summary>
                    <time datetime="{{ $movement['date']->toIso8601String() }}">{{ $movement['date']->format('d/m/Y') }}</time>
                    <span class="money-row-cause"><strong>{{ $movement['type'] }}</strong><span>{{ $movement['description'] }}</span></span>
                    <span class="money-row-party">{{ $movement['customer'] ?? 'Attività generale' }}</span>
                    <strong class="money-row-amount"><x-ui.signed-money :amount="$movement['amount']" /></strong>
                    <span class="money-row-state">{{ $movement['state'] }}</span><span class="money-row-expand" aria-hidden="true">⌄</span>
                </summary>
                <div class="money-row-detail">
                    <dl><div><dt>Tipo di operazione</dt><dd>{{ $movement['type'] }}</dd></div><div><dt>Data</dt><dd>{{ $movement['date']->format('d/m/Y H:i') }}</dd></div>
                        <div><dt>{{ $movement['cash'] > 0 ? 'Importo ricevuto' : 'Effetto sulla cassa' }}</dt><dd><x-ui.signed-money :amount="$movement['cash']" /></dd></div>
                        <div><dt>Effetto sul risultato economico</dt><dd><x-ui.signed-money :amount="$movement['economic']" /></dd></div>
                        <div><dt>Cliente / riferimento</dt><dd>{{ $movement['customer'] ?? 'Attività generale' }}</dd></div><div><dt>Operatore</dt><dd>{{ $movement['operator'] ?? 'Non disponibile' }}</dd></div>
                        <div><dt>Stato</dt><dd>{{ $movement['state'] }}</dd></div>
                        @if($movement['reason'])<div class="money-detail-wide"><dt>Motivo della differenza</dt><dd>{{ $movement['reason'] }}</dd></div>@endif
                        @if($movement['note'])<div class="money-detail-wide"><dt>Note</dt><dd>{{ $movement['note'] }}</dd></div>@endif
                    </dl>
                    <div class="register-actions">
                        @if($movement['order'])<a class="btn btn-outline-primary btn-sm" href="{{ route('orders.show', $movement['order']) }}">Apri ordine</a>@endif
                        @if(auth()->user()->role === \App\UserRole::Admin)
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('audits.index', ['type' => $movement['audit_type'], 'slug' => $movement['slug']]) }}">Audit del movimento</a>
                            @if($movement['original'])<a class="btn btn-outline-secondary btn-sm" href="{{ route('audits.index', ['type' => 'payment_entries', 'slug' => $movement['original']->slug]) }}">Movimento originale</a>@endif
                            @if($movement['account'])<a class="btn btn-outline-secondary btn-sm" href="{{ route('pending.index', ['slug' => $movement['account']->slug]) }}">Posizione e pagamenti</a>@endif
                        @endif
                        @php($entry = $movement['model'])
                        @if($movement['source'] === 'payment' && auth()->user()->role === \App\UserRole::Admin)
                            @php($order = $entry->order)
                            @if(in_array($entry->kind, ['receipt', 'recollection']) || $entry->kind === null)@include('balance.receipt-actions')@endif
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('audits.index', ['type' => 'orders', 'slug' => $order->slug]) }}">Storico incasso, storno e ripristino</a>
                            @if($entry->kind === 'refund' && ! $entry->audits->contains(fn ($audit) => in_array($audit->action, ['payment.refund_verified', 'payment.refund_disputed']) || ($audit->after['cash_confirmation'] ?? null) === 'explicit_payment'))<button type="button" class="btn btn-outline-secondary" data-dialog-open="verify-refund-{{ $entry->slug }}" aria-controls="verify-refund-{{ $entry->slug }}" aria-haspopup="dialog">Riconcilia rimborso storico</button>@endif
                        @endif
                        @if($movement['source'] === 'expense' && ! $entry->voided_at && ! $entry->pending_settlement_id)
                            <x-ui.icon-button action="edit" label="Modifica spesa" :data-dialog-open="'expense-edit-'.$entry->slug" :aria-controls="'expense-edit-'.$entry->slug" aria-haspopup="dialog" />
                            <form method="post" action="{{ route('expenses.destroy', $entry) }}" data-financial-form data-confirm="Annulla spesa" data-confirm-name="{{ $entry->description }}">@csrf @method('delete')<x-ui.icon-button action="delete" label="Annulla registrazione" type="submit" /></form>
                        @elseif($movement['source'] === 'manual' && ! $entry->voided_at)
                            <x-ui.icon-button action="edit" label="Modifica movimento" :data-dialog-open="'cash-edit-'.$entry->slug" :aria-controls="'cash-edit-'.$entry->slug" aria-haspopup="dialog" />
                            <form method="post" action="{{ route('movements.destroy', $entry) }}" data-financial-form data-confirm="Annulla movimento" data-confirm-name="{{ $entry->description }}">@csrf @method('delete')<input type="hidden" name="version" value="{{ $entry->version }}"><x-ui.icon-button action="delete" label="Annulla movimento" type="submit" /></form>
                        @endif
                    </div>
                </div>
            </details>
        @empty<p class="register-empty">Nessun movimento in questa categoria nel periodo.</p>@endforelse
    </div>
    {{ $registerEntries->links('components.ui.pagination', ['compact' => true]) }}
</x-ui.data-panel>
@foreach($registerEntries as $movement)
    @php($entry = $movement['model'])
    @if($movement['source'] === 'payment')@php($order = $entry->order)@include('balance.receipt-dialogs')@endif
    @if($movement['source'] === 'payment' && $entry->kind === 'refund' && auth()->user()->role === \App\UserRole::Admin)
    <x-ui.modal :id="'verify-refund-'.$entry->slug" title="Riconcilia rimborso storico" :description="$entry->order->reference"><p>Confronta la prova del pagamento. L’importo originale rimane nello storico. Se il denaro non è stato restituito, una compensazione nel periodo aperto riapre l’obbligo di rimborso.</p><form method="post" action="{{ route('payments.store', $entry->order) }}" data-financial-form class="form-stack">@csrf<input type="hidden" name="action" value="reconcile"><input type="hidden" name="version" value="{{ $entry->order->version }}"><input type="hidden" name="entry_slug" value="{{ $entry->slug }}"><label for="refund-resolution-{{ $entry->slug }}">Esito documentale</label><select name="resolution" id="refund-resolution-{{ $entry->slug }}" class="form-select" required><option value="">Seleziona dopo la verifica</option><option value="refund_paid">Rimborso effettivamente pagato</option>@if($entry->order->receipt_voided_at)<option value="refund_unpaid">Denaro ancora in azienda, rimborso non pagato</option>@endif</select><x-ui.field name="note" :id="'refund-proof-'.$entry->slug" label="Prova verificata e motivo" maxlength="500" required /><label class="form-check"><input type="checkbox" name="confirmed" value="1" required> Ho verificato la situazione effettiva del denaro.</label><x-ui.icon-button action="confirm" type="submit" label="Conferma riconciliazione" /></form></x-ui.modal>
    @endif
    @if($movement['source'] === 'expense' && ! $entry->voided_at && ! $entry->pending_settlement_id)
        <x-ui.modal :id="'expense-edit-'.$entry->slug" title="Modifica spesa" :description="$entry->description"><form method="post" action="{{ route('expenses.update', $entry) }}" data-financial-form class="form-stack">@csrf @method('patch')<input type="hidden" name="version" value="{{ $entry->version }}"><x-ui.field name="description" :id="'expense-description-'.$entry->slug" label="Descrizione" :value="$entry->description" required /><x-ui.field name="amount" :id="'expense-amount-'.$entry->slug" label="Importo (€)" :value="number_format($entry->amount_cents / 100, 2, '.', '')" inputmode="decimal" required /><x-ui.field name="spent_on" :id="'expense-date-'.$entry->slug" label="Data" type="date" :value="$entry->spent_on->toDateString()" required /><x-ui.icon-button action="receive" label="Salva modifica" type="submit" text /></form></x-ui.modal>
    @elseif($movement['source'] === 'manual' && ! $entry->voided_at)
        <x-ui.modal :id="'cash-edit-'.$entry->slug" title="Modifica movimento" :description="\App\Models\FinancialMovement::Kinds[$entry->kind]">@include('balance.movement-form', ['movement' => $entry])</x-ui.modal>
    @endif
@endforeach
