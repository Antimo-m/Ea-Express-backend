<x-app-layout :title="$selectedState === 'paid' ? 'Archivio Sospesi' : 'Sospesi'">
    <header class="page-heading pending-heading"><div><span class="eyebrow">CONTROLLO DEI SALDI</span><h1>{{ $selectedState === 'paid' ? 'Archivio Sospesi' : 'Sospesi' }}</h1><p>{{ $selectedState === 'paid' ? 'Posizioni completamente saldate, con lo storico dei pagamenti conservato.' : 'Crediti e debiti da saldare, con ogni pagamento al suo posto.' }}</p></div><div class="row-actions">
        <nav class="pending-switch" aria-label="Vista dei sospesi">
            <a @class(['btn', 'is-active' => $selectedState !== 'paid']) href="{{ route('pending.index', [...request()->only(['q', 'customer_id', 'direction', 'sort']), 'state' => 'outstanding']) }}" @if($selectedState !== 'paid') aria-current="page" @endif><x-ui.icon name="hourglass-split" />Da saldare</a>
            <a @class(['btn', 'is-active' => $selectedState === 'paid']) href="{{ route('pending.index', [...request()->only(['q', 'customer_id', 'direction', 'sort']), 'state' => 'paid']) }}" @if($selectedState === 'paid') aria-current="page" @endif><x-ui.icon name="archive" />Archivio Sospesi</a>
        </nav>
        <x-ui.icon-button action="add" label="Registra sospeso" data-dialog-open="pending-create" aria-controls="pending-create" aria-haspopup="dialog" /></div></header>
    <x-ui.filters :reset="route('pending.index')">
        <x-slot:search><x-ui.field name="q" label="Cerca sospeso" :value="request('q')" placeholder="Cerca soggetto, cliente, descrizione…" /></x-slot:search>
        <x-ui.filter-select name="state" label="Stato" default="outstanding" :value="$selectedState" :options="['outstanding'=>'Da saldare','partially_paid'=>'Parziali','paid'=>'Saldati','all'=>'Tutti','open'=>'Aperti senza pagamenti','cancelled'=>'Annullati']" />
        <x-ui.searchable-select name="customer_id" id="pending-customer" label="Cliente" :options="$customers" :value="request('customer_id')" />
        <x-slot:secondary><x-ui.filter-select name="direction" label="Direzione" :options="[''=>'Tutte','incoming'=>'In entrata','outgoing'=>'In uscita']" size="md" /><x-ui.filter-select name="sort" label="Ordina per" default="recent" :options="['recent'=>'Più recenti','due'=>'Scadenza','remaining'=>'Residuo maggiore']" size="md" /></x-slot:secondary>
        @foreach(['slug'] as $selection)@if(request($selection))<input type="hidden" name="{{ $selection }}" value="{{ request($selection) }}" data-filter-label="{{ $selection === 'direction' ? 'Direzione' : 'Sospeso' }}">@endif @endforeach
    </x-ui.filters>
    <section class="pending-metrics" data-workspace-region="pending-metrics" aria-label="Residui dei sospesi filtrati">
        <x-ui.metric label="Da incassare" :value="\App\Support\Money::format((int)($totals['incoming'] ?? 0))" icon="arrow-down-left" tone="positive" note="Crediti aperti e parziali" />
        <x-ui.metric label="Da pagare" :value="\App\Support\Money::format((int)($totals['outgoing'] ?? 0))" icon="arrow-up-right" tone="negative" note="Debiti aperti e parziali" />
    </section>


    <div data-workspace-region="pending-accounts">
    <p class="data-caption pending-caption"><span class="count-badge">{{ $accounts->total() }}</span> sospesi · I riepiloghi rispettano ricerca e stato e comprendono entrambe le direzioni.</p>
    <div class="pending-accounts">
        @forelse($accounts as $account)
            @php($remaining = $account->amount_cents - $account->settled_cents)
            @php($settledPercent = $account->amount_cents > 0 ? (int) round(min(100, max(0, $account->settled_cents * 100 / $account->amount_cents))) : 0)
            @php($isOverdue = $account->due_on && in_array($account->state, ['open', 'partially_paid'], true) && $account->due_on->lt(now('Europe/Rome')->startOfDay()))
            <section @class(['data-panel', 'pending-account-panel', 'pending-account-panel--'.$account->direction]) data-pending-state="{{ $account->state }}">
                <header class="data-panel-heading pending-account-heading">
                    <span class="pending-direction-icon" aria-hidden="true"><x-ui.icon :name="$account->direction === 'incoming' ? 'arrow-down-left' : 'arrow-up-right'" /></span>
                    <div><h2>{{ $account->subject }}</h2>@if($account->description)<p>{{ $account->description }}</p>@endif</div>
                    <div class="data-panel-actions"><x-ui.pending-status :account="$account" /></div>
                </header>
                <div class="pending-account-amounts">
                    <div class="pending-account-remaining"><span>{{ $account->state === 'cancelled' ? 'Stato' : 'Residuo' }}</span><strong>{{ $account->state === 'cancelled' ? 'Annullato' : \App\Support\Money::format($remaining) }}</strong></div>
                    <dl class="facts pending-account-facts">
                        <div><dt>Importo</dt><dd>{{ \App\Support\Money::format($account->amount_cents) }}</dd></div>
                        <div><dt>Già saldato</dt><dd>{{ \App\Support\Money::format($account->settled_cents) }}</dd></div>
                    </dl>
                    @if($account->state !== 'cancelled')
                        <div class="pending-progress" role="img" aria-label="Saldato il {{ $settledPercent }}% dell’importo"><span style="--settled: {{ $settledPercent }}%"></span></div>
                    @endif
                </div>
                <p class="pending-direction-note"><x-ui.icon :name="$account->direction === 'incoming' ? 'arrow-down-left-circle' : 'arrow-up-right-circle'" />{{ $account->direction === 'incoming' ? 'In entrata · il cliente deve pagare EA-Express' : 'In uscita · EA-Express deve pagare il cliente' }}</p>
                <div class="pending-account-owner">
                    <span class="pending-owner-avatar" aria-hidden="true">@if($account->customer){{ mb_strtoupper(mb_substr($account->customer->name, 0, 1)) }}@else<x-ui.icon name="person-dash" />@endif</span>
                    <div>
                        <strong>{{ $account->customer?->name ?? 'Cliente non associato' }}</strong>
                        @if($account->customer)
                            <span>{{ $account->customer->email }}</span>
                            @if($account->customer->business_type || $account->customer->business_description || ! $account->customer->is_active)
                                <span class="pending-owner-tags">
                                    @if($account->customer->business_type)<span>{{ $account->customer->business_type }}</span>@endif
                                    @if($account->customer->business_description)<span>{{ $account->customer->business_description }}</span>@endif
                                    @unless($account->customer->is_active)<span class="is-off">Account disattivato</span>@endunless
                                </span>
                            @endif
                        @else
                            <span>Visibile solo all’amministratore</span>
                        @endif
                    </div>
                    @unless($account->customer_id)
                        <button class="btn btn-sm btn-outline-primary" data-dialog-open="pending-assign-{{ $account->slug }}" aria-controls="pending-assign-{{ $account->slug }}" aria-haspopup="dialog">Associa cliente</button>
                    @endunless
                </div>
                <ul class="pending-account-dates" aria-label="Date">
                    <li><x-ui.icon name="calendar3" /><span>Data</span><strong>{{ $account->occurred_on->format('d/m/Y') }}</strong></li>
                    <li @class(['is-overdue' => $isOverdue])><x-ui.icon :name="$isOverdue ? 'exclamation-triangle' : 'calendar-event'" /><span>Scadenza</span><strong>{{ $account->due_on?->format('d/m/Y') ?? 'Senza scadenza' }}</strong>@if($isOverdue)<em>Scaduto</em>@endif</li>
                </ul>
                @if($account->order || $account->notes)
                    <div class="pending-account-notes">
                        @if($account->order)<a href="{{ route('orders.show', $account->order) }}"><x-ui.icon name="box-seam" />Ordine: {{ $account->order->reference }}</a>@endif
                        @if($account->notes)<p><x-ui.icon name="sticky" />{{ $account->notes }}</p>@endif
                    </div>
                @endif
                <footer class="data-panel-footer">
                    <div class="pending-account-footer">
                        <div class="row-actions pending-actions-main">
                            @if(in_array($account->state, ['open', 'partially_paid']))
                                <x-ui.icon-button action="receive" label="Registra pagamento" :data-dialog-open="'pending-settle-'.$account->slug" :aria-controls="'pending-settle-'.$account->slug" aria-haspopup="dialog" />
                            @endif
                            <span class="register-reference" title="{{ $account->slug }}">{{ $account->slug }}</span>
                        </div>
                        <div class="row-actions pending-actions-side">
                            <x-ui.icon-button action="history" label="Storico pagamenti" :data-dialog-open="'pending-history-'.$account->slug" :aria-controls="'pending-history-'.$account->slug" aria-haspopup="dialog" />
                            @if(in_array($account->state, ['open', 'partially_paid']))
                                <x-ui.icon-button action="edit" label="Modifica sospeso" :data-dialog-open="'pending-edit-'.$account->slug" :aria-controls="'pending-edit-'.$account->slug" aria-haspopup="dialog" />
                            @endif
                            <x-ui.icon-button action="open" icon="journal-text" label="Cronologia" :href="route('audits.index', ['type' => 'pending_accounts', 'slug' => $account->slug])" class="pending-audit-link" />
                        </div>
                    </div>
                </footer>
            </section>
        @empty
            <div class="empty-state pending-empty"><x-ui.icon name="check2-circle" /><p>Nessun sospeso per i filtri selezionati.</p></div>
        @endforelse
    </div>
    {{ $accounts->links('components.ui.pagination') }}
    @include('pending.dialogs')
    </div>
</x-app-layout>
