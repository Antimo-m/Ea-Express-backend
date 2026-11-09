<x-ui.data-panel title="Storni e ripristini" :count="$reversalEntries->total()" description="Lo storno rettifica soltanto incassi già registrati. Il rimborso viene registrato insieme allo storno; ogni ripristino conserva lo storico. Stato aggiornato a oggi; ciascun movimento incide nel proprio periodo di registrazione.">
    <div class="register-table-wrap finance-desktop-register"><table class="table register-table accounting-table" data-table-static>
        <caption class="visually-hidden">Storni contabili per data di registrazione</caption>
        <thead><tr><th scope="col">Cliente e ordine</th><th scope="col">Data e operatore</th><th scope="col" class="text-end">Importi</th><th scope="col">Effetto e stato</th><th scope="col">Operazioni</th></tr></thead>
        <tbody>@forelse($reversalEntries as $reversal)
            @php($order = $reversal->order)
            <tr data-reversal="{{ $reversal->slug }}">
                <td><a class="register-primary" href="{{ route('orders.show', $order) }}">{{ $order->displayName() }}</a><span class="register-reference">{{ $order->reference }}</span><span class="register-note">{{ $reversal->reason }}</span>
                    @foreach($reversal->payments->where('kind', 'reversal') as $entry)
                        @if($entry->originalEntry)<a class="register-note" href="{{ route('audits.index', ['type' => 'payment_entries', 'slug' => $entry->originalEntry->slug]) }}">Incasso originale del {{ $entry->originalEntry->created_at->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ \App\Support\Money::format($entry->originalEntry->amount_cents) }}</a>@endif
                    @endforeach
                </td>
                <td><time datetime="{{ $reversal->created_at->toIso8601String() }}">{{ $reversal->created_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</time><small class="register-note">{{ $reversal->user->name }}</small></td>
                <td class="text-end register-amount">{{ \App\Support\Money::format(-$reversal->corrected_cents) }}<small>Originale: {{ \App\Support\Money::format($reversal->original_amount_cents) }}</small><small>Incassato: {{ \App\Support\Money::format($reversal->collected_cents) }}</small><small>Rimborsato: {{ \App\Support\Money::format(-$reversal->payments->where('kind', 'refund')->sum('cash_amount_cents')) }}</small></td>
                <td><span class="status-pill {{ $reversal->corrected_cents ? 'tone-danger' : 'tone-neutral' }}">{{ $reversal->corrected_cents ? 'Con rettifica contabile' : 'Senza effetto sul netto' }}</span>
                    @if($reversal->restored_at)<small class="register-note">Ripristinato il {{ $reversal->restored_at->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ $reversal->restoredBy?->name }}<br>{{ $reversal->restore_reason }}<br>Escluso dagli storni attivi; il pagamento richiede una registrazione separata.</small>@else<small class="register-note">Storno attivo · incasso sospeso</small>@endif
                </td>
                <td><div class="register-actions">@if(! $reversal->restored_at)@include('balance.receipt-actions')@else<x-ui.icon-button action="history" label="Storico dell’incasso" :href="route('audits.index', ['type' => 'orders', 'slug' => $order->slug])" />@endif</div></td>
            </tr>
        @empty<tr><td colspan="5" class="register-empty">Nessuno storno nel periodo.</td></tr>@endforelse</tbody>
    </table></div>
    <div class="finance-mobile-list" aria-label="Storni e ripristini">
        @forelse($reversalEntries as $reversal)
            @php($order = $reversal->order)
            <details class="cash-movement" data-cash-reversal="{{ $reversal->slug }}">
                <summary><span class="cash-movement-identity"><strong>{{ $reversal->restored_at ? 'Storno ripristinato' : 'Storno attivo' }}</strong><span>{{ $order->reference }} · {{ $order->displayName() }}</span><time datetime="{{ $reversal->created_at->toIso8601String() }}">{{ $reversal->created_at->timezone('Europe/Rome')->format('d/m/Y — H:i') }}</time></span><span class="cash-movement-value"><x-ui.signed-money :amount="-$reversal->corrected_cents" /><small>{{ $reversal->corrected_cents ? 'Rettifica contabile' : 'Senza effetto sul netto' }}</small></span></summary>
                <div class="cash-movement-detail"><dl>
                    <div><dt>Operatore</dt><dd>{{ $reversal->user->name }}</dd></div>
                    <div><dt>Motivo</dt><dd>{{ $reversal->reason }}</dd></div>
                    <div><dt>Importo originale</dt><dd>{{ \App\Support\Money::format($reversal->original_amount_cents) }}</dd></div>
                    <div><dt>Incassato</dt><dd>{{ \App\Support\Money::format($reversal->collected_cents) }}</dd></div>
                    <div><dt>Rimborso effettuato</dt><dd>{{ \App\Support\Money::format(-$reversal->payments->where('kind', 'refund')->sum('cash_amount_cents')) }}</dd></div>
                    <div><dt>Stato</dt><dd>{{ $reversal->restored_at ? 'Ripristinato il '.$reversal->restored_at->timezone('Europe/Rome')->format('d/m/Y H:i').' · '.$reversal->restoredBy?->name : 'Storno attivo · incasso sospeso' }}</dd></div>
                    @if($reversal->restore_reason)<div><dt>Motivo del ripristino</dt><dd>{{ $reversal->restore_reason }}</dd></div>@endif
                    @foreach($reversal->payments->where('kind', 'reversal') as $entry)
                        @if($entry->originalEntry)<div><dt>Movimento originale</dt><dd><a href="{{ route('audits.index', ['type' => 'payment_entries', 'slug' => $entry->originalEntry->slug]) }}">Incasso del {{ $entry->originalEntry->created_at->timezone('Europe/Rome')->format('d/m/Y H:i') }} · {{ \App\Support\Money::format($entry->originalEntry->amount_cents) }}</a></dd></div>@endif
                    @endforeach
                </dl><div class="register-actions"><a class="btn btn-outline-primary btn-sm" href="{{ route('orders.show', $order) }}">Apri ordine</a>@if(! $reversal->restored_at)@include('balance.receipt-actions')@else<x-ui.icon-button action="history" label="Storico dell’incasso" :href="route('audits.index', ['type' => 'orders', 'slug' => $order->slug])" />@endif</div></div>
            </details>
        @empty<p class="register-empty">Nessuno storno nel periodo.</p>@endforelse
    </div>
    @foreach($reversalEntries as $reversal)@php($order = $reversal->order)@include('balance.receipt-dialogs')@endforeach
    {{ $reversalEntries->links('components.ui.pagination', ['compact' => true]) }}
</x-ui.data-panel>
