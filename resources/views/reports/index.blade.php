<x-app-layout title="Resoconti">
    <header class="page-heading"><div><span class="eyebrow">LA TUA ATTIVITÀ, IN NUMERI</span><h1>Resoconti</h1><p>{{ $period->start->locale('it')->translatedFormat('F Y') }} · {{ auth()->user()->role === \App\UserRole::Admin ? 'Tutta l’attività' : 'Le richieste create, rifiutate o assegnate a te' }}</p></div><x-ui.status-badge class="status-pill tone-blue"><x-ui.icon name="calendar3" /> Vista mensile</x-ui.status-badge></header>
    <x-ui.filters layout="date" compact accent="orange" :reset="route('reports.index')"><x-ui.field name="month" label="Mese" type="month" size="small" :value="$period->start->format('Y-m')" required /></x-ui.filters>
    <section class="report-kpis" aria-label="Indicatori mensili">
        <x-ui.metric label="Richieste ricevute" :value="$received" icon="inbox" :note="($growth === null ? 'Nessuna base di confronto' : ($growth > 0 ? '+' : '').number_format($growth, 1, ',', '.').'%').' · mese precedente intero: '.$previous" />
        <x-ui.metric label="Consegne completate" :value="$delivered" icon="truck" note="In base alla data effettiva di consegna" />
        <x-ui.metric label="Incassi clienti" :value="\App\Support\Money::format($cash)" icon="cash-coin" tone="positive" note="Inclusi i pagamenti dei sospesi, denaro effettivamente ricevuto, al netto dei soli rimborsi pagati" />
        <x-ui.metric label="Netto contabile operativo" :value="\App\Support\Money::format($operatingNet)" icon="wallet2" :tone="$operatingNet < 0 ? 'negative' : 'attention'" note="Incassi lordi − storni + ripristini − spese + saldo amministrativo, esclusi i contanti aggiuntivi" />
    </section>
    <p class="small text-secondary">Richieste regionali: {{ $shippingCounts['regional'] ?? 0 }} · Fuori regione: {{ $shippingCounts['external'] ?? 0 }}</p><div class="report-chart-grid">
        <x-reports.trend id="orders-trend" title="Andamento delle richieste" description="Richieste create ogni giorno del mese, in ora italiana." :points="$trend" />
        <x-reports.trend id="cash-trend" kind="cash" title="Movimenti contabili" description="Incassi e rettifiche alla data di registrazione; spese e movimenti amministrativi alla data contabile. Rimborsi di cassa e ripristini non duplicano entrate o spese." :points="$trend" />
    </div>
    <section class="surface report-totals"><div><span>Entrate e compensazioni del mese</span><x-ui.signed-money :amount="$incomingTotal" /></div><div><span>Uscite e storni</span><x-ui.signed-money :amount="-$outgoingTotal" /></div><div><span>Spese valide</span><strong>{{ \App\Support\Money::format($spent) }}</strong></div><div><span>Tariffe maturate</span><strong>{{ \App\Support\Money::format($earned) }}</strong><small>Non si sommano agli incassi</small></div></section>
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
        <section class="surface workspace-section"><header class="section-heading"><div><span class="eyebrow">ACCOUNT CLIENTI</span><h2>Clienti più attivi</h2></div><x-ui.icon name="people" /></header><p class="small text-secondary">Richieste create nel mese, raggruppate per account cliente.</p>
            @forelse($topCustomers as $customer)<a class="rank-row" href="{{ route('customers.show',['customer'=>$customer->customer?->slug,'period'=>'custom','from'=>$period->start->toDateString(),'to'=>$period->end->toDateString()]) }}"><span class="rank-number">{{ $loop->iteration }}</span><span>{{ $customer->customer?->name ?? 'Account non disponibile' }}</span><strong>{{ $customer->shipments }}</strong><x-ui.icon name="arrow-up-right" /></a>@empty<p class="empty-note">Nessun account cliente con richieste nel mese.</p>@endforelse
        </section>
    </div>
<x-reports.shipping-economics :summary="$shippingEconomics" :receipts="$receiptEconomics"/>
</x-app-layout>
