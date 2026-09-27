<x-app-layout title="Resoconti">
    <header class="page-heading"><div><span class="eyebrow">LA TUA ATTIVITÀ, IN NUMERI</span><h1>Resoconti</h1><p>{{ $period->start->locale('it')->translatedFormat('F Y') }} · {{ auth()->user()->role === \App\UserRole::Admin ? 'Tutta l’attività' : 'Le richieste create, rifiutate o assegnate a te' }}</p></div><span class="status-pill tone-blue"><x-ui.icon name="calendar3" /> Vista mensile</span></header>
    <x-ui.filters compact :reset="route('reports.index')"><x-ui.field name="month" label="Mese di riferimento" type="month" :value="$period->start->format('Y-m')" required /></x-ui.filters>
    <section class="report-kpis" aria-label="Indicatori mensili">
        <article class="surface report-kpi"><span>Richieste ricevute <x-ui.icon name="inbox" /></span><strong>{{ $received }}</strong><small>{{ $growth === null ? 'Nessuna base di confronto' : ($growth > 0 ? '+' : '').number_format($growth, 1, ',', '.').'%' }} · mese precedente intero: {{ $previous }}</small></article>
        <article class="surface report-kpi"><span>Consegne completate <x-ui.icon name="truck" /></span><strong>{{ $delivered }}</strong><small>In base alla data effettiva di consegna</small></article>
        <article class="surface report-kpi"><span>Incassi clienti <x-ui.icon name="cash-coin" /></span><strong>{{ \App\Support\Money::format($cash) }}</strong><small>Inclusi sospesi, al netto degli storni</small></article>
        <article class="surface report-kpi"><span>Saldo di cassa operativo <x-ui.icon name="wallet2" /></span><strong>{{ \App\Support\Money::format($operatingNet) }}</strong><small>Incassi − quote vettori − spese + saldo movimenti amministrativi</small></article>
    </section>
    <p class="small text-secondary">Richieste regionali: {{ $shippingCounts['regional'] ?? 0 }} · Fuori regione: {{ $shippingCounts['external'] ?? 0 }}</p><div class="report-chart-grid">
        <x-reports.trend id="orders-trend" title="Andamento delle richieste" description="Richieste create ogni giorno del mese, in ora italiana." :points="$trend" />
        <x-reports.trend id="cash-trend" kind="cash" title="Entrate e uscite" description="Quote EA-Express dei pagamenti e storni alla data di registrazione; spese e movimenti amministrativi alla data contabile." :points="$trend" />
    </div>
    <section class="surface report-totals"><div><span>Entrate del mese</span><x-ui.signed-money :amount="$incomingTotal" /></div><div><span>Uscite e storni</span><x-ui.signed-money :amount="-$outgoingTotal" /></div><div><span>Spese valide</span><strong>{{ \App\Support\Money::format($spent) }}</strong></div><div><span>Tariffe maturate</span><strong>{{ \App\Support\Money::format($earned) }}</strong><small>Non si sommano agli incassi</small></div></section>
    <div class="report-detail-grid">
        <section class="surface workspace-section"><header class="section-heading"><div><span class="eyebrow">DISTRIBUZIONE</span><h2>Stato delle richieste</h2></div><span class="count-badge">{{ $accepted }} accettate</span></header><p class="small text-secondary">Stato attuale delle richieste create nel mese; gli esiti possono ancora cambiare.</p>
            @foreach(\App\OrderStatus::cases() as $state)
                @if(($states[$state->value] ?? 0) > 0)<div class="distribution-row"><div><span>{{ $state->label() }}</span><strong>{{ $states[$state->value] }}</strong></div><meter min="0" max="{{ max($received,1) }}" value="{{ $states[$state->value] }}" aria-label="{{ $state->label() }}: {{ $states[$state->value] }} su {{ $received }}"></meter></div>@endif
            @endforeach
            @if(!$received)<p class="empty-note">Nessuna richiesta nel mese.</p>@endif
        </section>
        <section class="surface workspace-section"><header class="section-heading"><div><span class="eyebrow">RETE DI CONSEGNA</span><h2>Zone più richieste</h2></div><x-ui.icon name="geo-alt" /></header>
            @forelse($zones as $zone)<div class="distribution-row"><div><span>{{ $zone->delivery_city }}</span><strong>{{ $zone->total }}</strong></div><meter min="0" max="{{ max($received,1) }}" value="{{ $zone->total }}" aria-label="{{ $zone->delivery_city }}: {{ $zone->total }} richieste"></meter></div>@empty<p class="empty-note">Nessuna destinazione nel mese.</p>@endforelse
        </section>
        <section class="surface workspace-section"><header class="section-heading"><div><span class="eyebrow">ACCOUNT CLIENTI</span><h2>Clienti più attivi</h2></div><x-ui.icon name="people" /></header><p class="small text-secondary">Richieste create nel mese, raggruppate per ID account.</p>
            @forelse($topCustomers as $customer)<a class="rank-row" href="{{ route('stores.index',['customer_id'=>$customer->customer_id,'period'=>'custom','from'=>$period->start->toDateString(),'to'=>$period->end->toDateString()]) }}"><span class="rank-number">{{ $loop->iteration }}</span><span>{{ $customer->customer?->name ?? 'Account non disponibile' }}</span><strong>{{ $customer->shipments }}</strong><x-ui.icon name="arrow-up-right" /></a>@empty<p class="empty-note">Nessun account cliente con richieste nel mese.</p>@endforelse
        </section>
    </div>
<x-reports.shipping-economics :summary="$shippingEconomics" :receipts="$receiptEconomics"/>
</x-app-layout>
