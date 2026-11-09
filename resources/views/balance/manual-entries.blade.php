        <div class="register-table-wrap finance-desktop-register"><table class="table register-table accounting-table" data-table-static><thead><tr><th scope="col">Voce</th><th scope="col">Data e operatore</th><th scope="col" class="text-end">Importo</th><th scope="col">Stato</th><th scope="col">Operazioni</th></tr></thead><tbody>@forelse($entries as $entry)<tr><td><strong class="register-primary">{{ \App\Models\FinancialMovement::Kinds[$entry->kind] }}</strong><span class="register-note">{{ $entry->description }}</span>@if($entry->customer)<small class="register-note">{{ $entry->customer->name }}</small>@endif @if($entry->order)<a class="register-reference" href="{{ route('orders.show', $entry->order) }}">{{ $entry->order->reference }}</a>@endif @if($entry->notes)<small class="register-note">{{ $entry->notes }}</small>@endif</td><td>{{ $entry->occurred_on->format('d/m/Y') }}<small class="register-note">{{ $entry->user->name }}</small></td><td class="text-end"><x-ui.signed-money :amount="$entry->amount_cents" /></td><td><span class="status-pill {{ $entry->voided_at ? 'tone-neutral' : 'tone-green' }}">{{ $entry->voided_at ? 'Annullata' : 'Registrata' }}</span></td><td><div class="register-actions">@unless($entry->voided_at)
            <x-ui.icon-button action="edit" label="Modifica movimento" :data-dialog-open="'cash-edit-'.$entry->slug" :aria-controls="'cash-edit-'.$entry->slug" aria-haspopup="dialog" />
            <form method="post" action="{{ route('movements.destroy', $entry) }}" data-financial-form data-confirm="Annulla movimento" data-confirm-name="{{ $entry->description }}">@csrf @method('delete')<input type="hidden" name="version" value="{{ $entry->version }}"><x-ui.icon-button action="delete" label="Annulla movimento" type="submit" /></form>
            @endunless <x-ui.icon-button action="history" label="Storico del movimento" :href="route('audits.index', ['type' => 'financial_movements', 'slug' => $entry->slug])" /></div></td></tr>@empty<tr><td colspan="5" class="register-empty">Nessuna registrazione nel periodo.</td></tr>@endforelse</tbody></table></div>
        <div class="finance-mobile-list" aria-label="Registro di cassa">
@forelse($entries as $entry)
    <details class="cash-movement" data-cash-movement="{{ $entry->slug }}">
        <summary><span class="cash-movement-identity"><strong>{{ \App\Models\FinancialMovement::Kinds[$entry->kind] }}</strong><span>{{ $entry->description }}</span><time datetime="{{ $entry->occurred_on->toDateString() }}">{{ $entry->occurred_on->format('d/m/Y') }}</time></span><span class="cash-movement-value"><x-ui.signed-money :amount="$entry->amount_cents" /><small>{{ $entry->voided_at ? 'Annullato' : ($entry->amount_cents < 0 ? 'Uscita' : 'Entrata') }}</small></span></summary>
        <div class="cash-movement-detail"><dl>
            <div><dt>Tipo di operazione</dt><dd>{{ \App\Models\FinancialMovement::Kinds[$entry->kind] }}</dd></div>
            <div><dt>Cliente o soggetto</dt><dd>{{ $entry->customer?->name ?? 'Movimento generale' }}</dd></div>
            <div><dt>Operatore</dt><dd>{{ $entry->user->name }}</dd></div>
            <div><dt>Stato</dt><dd>{{ $entry->voided_at ? 'Annullato' : 'Registrato' }}</dd></div>
            @if($entry->order)<div><dt>Ordine</dt><dd><a href="{{ route('orders.show', $entry->order) }}">{{ $entry->order->reference }}</a></dd></div>@endif
            @if($entry->notes)<div><dt>Note</dt><dd>{{ $entry->notes }}</dd></div>@endif
        </dl><div class="register-actions">
            @unless($entry->voided_at)
                <x-ui.icon-button action="edit" label="Modifica movimento" :data-dialog-open="'cash-edit-'.$entry->slug" :aria-controls="'cash-edit-'.$entry->slug" aria-haspopup="dialog" />
                <form method="post" action="{{ route('movements.destroy', $entry) }}" data-financial-form data-confirm="Annulla movimento" data-confirm-name="{{ $entry->description }}">@csrf @method('delete')<input type="hidden" name="version" value="{{ $entry->version }}"><x-ui.icon-button action="delete" label="Annulla movimento" type="submit" /></form>
            @endunless
            <x-ui.icon-button action="history" label="Storico del movimento" :href="route('audits.index', ['type' => 'financial_movements', 'slug' => $entry->slug])" />
        </div></div>
    </details>
@empty<p class="register-empty">Nessuna registrazione nel periodo.</p>@endforelse
</div>
@foreach($entries as $entry) @unless($entry->voided_at)
            <x-ui.modal :id="'cash-edit-'.$entry->slug" title="Modifica movimento" :description="\App\Models\FinancialMovement::Kinds[$entry->kind]">
            @if($entry->kind === 'cash')<form method="post" action="{{ route('movements.update', $entry) }}" data-financial-form class="form-stack">@csrf @method('patch')<input type="hidden" name="kind" value="cash"><input type="hidden" name="version" value="{{ $entry->version }}"><x-ui.field name="amount" :id="'cash-amount-'.$entry->slug" label="Importo contanti (€)" :value="number_format($entry->amount_cents / 100, 2, '.', '')" inputmode="decimal" required /><button class="btn btn-success" type="submit">Salva modifica</button></form>
            @else @include('balance.movement-form', ['movement' => $entry]) @endif
            </x-ui.modal>
@endunless @endforeach
{{ $entries->links('components.ui.pagination', ['compact' => true]) }}
