<x-app-layout title="Sospesi">
    <header class="page-heading"><div><span class="eyebrow">CONTROLLO DEI SALDI</span><h1>Sospesi</h1><p>Crediti e debiti, con ogni pagamento al suo posto.</p></div><x-ui.icon-button action="add" label="Registra sospeso" data-dialog-open="pending-create" aria-controls="pending-create" aria-haspopup="dialog" /></header>
    <x-ui.filters :reset="route('pending.index')">
        <x-slot:search><x-ui.field name="q" label="Cerca sospeso" :value="request('q')" placeholder="Cerca soggetto, cliente, descrizione…" /></x-slot:search>
        <x-ui.filter-select name="state" label="Stato" :options="[''=>'Tutti','open'=>'Non pagato','partially_paid'=>'Parziale','paid'=>'Saldato','cancelled'=>'Annullato']" />
        <x-ui.searchable-select name="customer_id" id="pending-customer" label="Cliente" :options="$customers" :value="request('customer_id')" />
        <x-slot:secondary><x-ui.filter-select name="direction" label="Direzione" :options="[''=>'Tutte','incoming'=>'In entrata','outgoing'=>'In uscita']" size="md" /><x-ui.filter-select name="sort" label="Ordina per" default="recent" :options="['recent'=>'Più recenti','due'=>'Scadenza','remaining'=>'Residuo maggiore']" size="md" /></x-slot:secondary>
        @foreach(['slug'] as $selection)@if(request($selection))<input type="hidden" name="{{ $selection }}" value="{{ request($selection) }}" data-filter-label="{{ $selection === 'direction' ? 'Direzione' : 'Sospeso' }}">@endif @endforeach
    </x-ui.filters>
    <section class="pending-metrics" data-workspace-region="pending-metrics" aria-label="Residui dei sospesi filtrati">
        <x-ui.metric label="Da incassare" :value="\App\Support\Money::format((int)($totals['incoming'] ?? 0))" icon="arrow-down-left" tone="positive" note="Crediti aperti e parziali" />
        <x-ui.metric label="Da pagare" :value="\App\Support\Money::format((int)($totals['outgoing'] ?? 0))" icon="arrow-up-right" tone="negative" note="Debiti aperti e parziali" />
    </section>


    <div data-workspace-region="pending-accounts">
    <p class="data-caption">{{ $accounts->total() }} sospesi · I riepiloghi rispettano ricerca e stato e comprendono entrambe le direzioni.</p>
    <div class="pending-accounts">
        @forelse($accounts as $account)
            <x-ui.data-panel :title="$account->subject" :description="$account->description" class="pending-account-panel">
                <x-slot:actions><x-ui.pending-status :account="$account" /></x-slot:actions>
                <div class="pending-account-owner">
                    <div>
                        <strong>{{ $account->customer?->name ?? 'Cliente non associato' }}</strong>
                        @if($account->customer)
                            <span>{{ $account->customer->email }}</span>
                            @if($account->customer->business_type)<span>{{ $account->customer->business_type }}</span>@endif
                            @if($account->customer->business_description)<span>{{ $account->customer->business_description }}</span>@endif
                            @unless($account->customer->is_active)<span>Account disattivato</span>@endunless
                        @else
                            <span>Visibile solo all’amministratore</span>
                        @endif
                    </div>
                    @unless($account->customer_id)
                        <button class="btn btn-sm btn-outline-primary" data-dialog-open="pending-assign-{{ $account->slug }}" aria-controls="pending-assign-{{ $account->slug }}" aria-haspopup="dialog">Associa cliente</button>
                    @endunless
                </div>
                <dl class="facts pending-account-facts">
                    <div><dt>Importo</dt><dd>{{ \App\Support\Money::format($account->amount_cents) }}</dd></div>
                    <div><dt>Già saldato</dt><dd>{{ \App\Support\Money::format($account->settled_cents) }}</dd></div>
                    <div><dt>Residuo</dt><dd>{{ $account->state === 'cancelled' ? 'Annullato' : \App\Support\Money::format($account->amount_cents - $account->settled_cents) }}</dd></div>
                    <div><dt>Direzione per EA-Express</dt><dd>{{ $account->direction === 'incoming' ? 'In entrata · il cliente deve pagare EA-Express' : 'In uscita · EA-Express deve pagare il cliente' }}</dd></div>
                    <div><dt>Data</dt><dd>{{ $account->occurred_on->format('d/m/Y') }}</dd></div>
                    <div><dt>Scadenza</dt><dd>{{ $account->due_on?->format('d/m/Y') ?? 'Senza scadenza' }}</dd></div>
                </dl>
                @if($account->order || $account->notes)
                    <div class="pending-account-notes">
                        @if($account->order)<a href="{{ route('orders.show', $account->order) }}">Ordine: {{ $account->order->reference }}</a>@endif
                        @if($account->notes)<p>{{ $account->notes }}</p>@endif
                    </div>
                @endif
                <x-slot:footer>
                    <div class="pending-account-footer">
                        <div class="row-actions">
                            @if(in_array($account->state, ['open', 'partially_paid']))
                                <x-ui.icon-button action="receive" label="Registra pagamento" :data-dialog-open="'pending-settle-'.$account->slug" :aria-controls="'pending-settle-'.$account->slug" aria-haspopup="dialog" />
                                <x-ui.icon-button action="edit" label="Modifica sospeso" :data-dialog-open="'pending-edit-'.$account->slug" :aria-controls="'pending-edit-'.$account->slug" aria-haspopup="dialog" />
                            @endif
                            <x-ui.icon-button action="history" label="Storico pagamenti" :data-dialog-open="'pending-history-'.$account->slug" :aria-controls="'pending-history-'.$account->slug" aria-haspopup="dialog" />
                            <a class="audit-link" href="{{ route('audits.index', ['type' => 'pending_accounts', 'slug' => $account->slug]) }}">Cronologia</a>
                        </div>
                        <span class="register-reference">{{ $account->slug }}</span>
                    </div>
                </x-slot:footer>
            </x-ui.data-panel>
        @empty
            <div class="empty-state"><x-ui.icon name="check2-circle" /><p>Nessun sospeso per i filtri selezionati.</p></div>
        @endforelse
    </div>
    {{ $accounts->links('components.ui.pagination') }}
    @include('pending.dialogs')
    </div>
</x-app-layout>
