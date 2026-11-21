<x-app-layout title="Resoconti">
    <header class="page-heading"><div><span class="eyebrow">LA TUA ATTIVITÀ, IN NUMERI</span><h1>Resoconti</h1><p>{{ $customPeriod ? $period->start->format('d/m/Y').' — '.$period->end->format('d/m/Y') : $period->start->locale('it')->translatedFormat('F Y') }} · {{ auth()->user()->role === \App\UserRole::Admin ? 'Tutta l’attività' : 'Le richieste create, rifiutate o assegnate a te' }}</p></div><x-ui.status-badge class="status-pill tone-blue"><x-ui.icon name="calendar3" /> {{ $customPeriod ? 'Periodo personalizzato' : 'Vista mensile' }}</x-ui.status-badge></header>
    <x-ui.filters layout="date" compact accent="orange" :reset="route('reports.index')" class="report-filters">
        <div class="report-filter-group" role="group" aria-labelledby="report-filter-month">
            <span class="report-filter-label" id="report-filter-month"><x-ui.icon name="calendar3" />Vista mensile</span>
            <x-ui.field name="month" label="Mese" type="month" size="small" :value="request('month', $period->start->format('Y-m'))" />
        </div>
        <span class="report-filter-or" aria-hidden="true">oppure</span>
        <div class="report-filter-group report-filter-range" role="group" aria-labelledby="report-filter-range" aria-describedby="report-filter-help">
            <span class="report-filter-label" id="report-filter-range"><x-ui.icon name="calendar-range" />Periodo personalizzato</span>
            <div class="report-filter-dates">
                <x-ui.field name="from" label="Dal" type="date" data-required-when="to" :value="request('from')" />
                <x-ui.icon name="arrow-right" class="report-filter-arrow" />
                <x-ui.field name="to" label="Al" type="date" data-required-when="from" :value="request('to')" />
            </div>
            <small id="report-filter-help">Entrambe le date, massimo un anno. Vuote: si consulta il mese.</small>
        </div>
    </x-ui.filters>
    <p class="report-shipping-split"><span><x-ui.icon name="geo-alt" />Regionali <strong>{{ $shippingCounts['regional'] ?? 0 }}</strong></span><span><x-ui.icon name="globe2" />Fuori regione <strong>{{ $shippingCounts['external'] ?? 0 }}</strong></span></p>
    <section class="report-kpis" aria-label="Indicatori del periodo">
        <x-ui.metric label="Richieste ricevute" :value="$received" icon="inbox" :note="($growth === null ? 'Nessuna base di confronto' : ($growth > 0 ? '+' : '').number_format($growth, 1, ',', '.').'%').' · '.($customPeriod ? 'periodo precedente equivalente: ' : 'mese precedente intero: ').$previous" />
        <x-ui.metric label="Consegne completate" :value="$delivered" icon="truck" note="In base alla data effettiva di consegna" />
        <x-ui.metric label="Incassi clienti" :value="\App\Support\Money::format($cash)" icon="cash-coin" tone="positive" note="Inclusi i pagamenti dei sospesi, denaro effettivamente ricevuto, al netto dei soli rimborsi pagati" />
        <x-ui.metric label="Netto contabile operativo" :value="\App\Support\Money::format($operatingNet)" icon="wallet2" :tone="$operatingNet < 0 ? 'negative' : 'attention'" note="Incassi lordi − storni + ripristini − spese + saldo amministrativo, esclusi i contanti aggiuntivi" />
    </section>
    <div class="report-chart-grid">
        <x-reports.trend id="orders-trend" title="Andamento delle richieste" description="Richieste create ogni giorno del periodo, in ora italiana." :points="$trend" />
        <x-reports.trend id="cash-trend" kind="cash" title="Movimenti contabili" description="Incassi e rettifiche alla data di registrazione; spese e movimenti amministrativi alla data contabile. Rimborsi di cassa e ripristini non duplicano entrate o spese." :points="$trend" />
    </div>
    <section class="surface report-totals"><div class="report-total report-total--in"><x-ui.icon name="arrow-down-left" /><span>Entrate e compensazioni del periodo</span><x-ui.signed-money :amount="$incomingTotal" /></div><div class="report-total report-total--out"><x-ui.icon name="arrow-up-right" /><span>Uscite e storni</span><x-ui.signed-money :amount="-$outgoingTotal" /></div><div class="report-total report-total--expense"><x-ui.icon name="receipt" /><span>Spese valide</span><strong>{{ \App\Support\Money::format($spent) }}</strong></div><div class="report-total report-total--earned"><x-ui.icon name="tags" /><span>Tariffe maturate</span><strong>{{ \App\Support\Money::format($earned) }}</strong><small>Non si sommano agli incassi</small></div></section>
    <div class="report-detail-grid">
        <section class="surface workspace-section"><header class="section-heading"><div><span class="eyebrow">DISTRIBUZIONE</span><h2>Stato delle richieste</h2></div><span class="count-badge">{{ $accepted }} accettate</span></header><p class="small text-secondary">Stato attuale delle richieste create nel periodo; gli esiti possono ancora cambiare.</p>
            @foreach(\App\OrderStatus::cases() as $state)
                @if(($states[$state->value] ?? 0) > 0)<div class="distribution-row"><div><span>{{ $state->label() }}</span><strong>{{ $states[$state->value] }}</strong></div><meter min="0" max="{{ max($received,1) }}" value="{{ $states[$state->value] }}" aria-label="{{ $state->label() }}: {{ $states[$state->value] }} su {{ $received }}"></meter></div>@endif
            @endforeach
            @if(!$received)<p class="empty-note">Nessuna richiesta nel periodo.</p>@endif
        </section>
        <section class="surface workspace-section"><header class="section-heading"><div><span class="eyebrow">RETE DI CONSEGNA</span><h2>Zone più richieste</h2></div><x-ui.icon name="geo-alt" /></header>
            @forelse($zones as $zone)<div class="distribution-row"><div><span>{{ $zone->delivery_city }}</span><strong>{{ $zone->total }}</strong></div><meter min="0" max="{{ max($received,1) }}" value="{{ $zone->total }}" aria-label="{{ $zone->delivery_city }}: {{ $zone->total }} richieste"></meter></div>@empty<p class="empty-note">Nessuna destinazione nel periodo.</p>@endforelse
        </section>
        <section class="surface workspace-section"><header class="section-heading"><div><span class="eyebrow">ACCOUNT CLIENTI</span><h2>Clienti più attivi</h2></div><x-ui.icon name="people" /></header><p class="small text-secondary">Richieste create nel periodo, raggruppate per account cliente.</p>
            @forelse($topCustomers as $customer)<a class="rank-row" href="{{ route('customers.show',['customer'=>$customer->customer?->slug,'period'=>'custom','from'=>$period->start->toDateString(),'to'=>$period->end->toDateString()]) }}"><span class="rank-number">{{ $loop->iteration }}</span><span>{{ $customer->customer?->name ?? 'Account non disponibile' }}</span><strong>{{ $customer->shipments }}</strong><x-ui.icon name="arrow-up-right" /></a>@empty<p class="empty-note">Nessun account cliente con richieste nel periodo.</p>@endforelse
        </section>
    </div>
<x-reports.shipping-economics :summary="$shippingEconomics" :receipts="$receiptEconomics"/>
</x-app-layout>
