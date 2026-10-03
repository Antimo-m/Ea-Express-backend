<x-app-layout title="Statistiche Clienti">
    <header class="page-heading"><div><span class="eyebrow">ANALISI PER ACCOUNT</span><h1>Statistiche Clienti</h1><p>Chi spedisce, quali tariffe utilizza e quanto genera.</p></div><span class="count-badge">{{ $stores->total() }} account / gruppi</span></header>
    <x-ui.filters :reset="route('stores.index')" layout="analysis">
        <x-slot:search><x-ui.field name="q" label="Cerca account" :value="request('q')" placeholder="Cerca account, nome o email…" /></x-slot:search>
        <x-slot:period><x-ui.filter-period route="stores.index" :period="$period" /></x-slot:period>
        <x-ui.filter-select name="status" label="Stato" :options="[''=>'Tutti', ...collect(\App\OrderStatus::cases())->mapWithKeys(fn($state)=>[$state->value=>$state->label()])->all()]" />
        <x-slot:secondary>
            <x-ui.filter-select name="sender_type" label="Tipo mittente" :options="[''=>'Tutti','business'=>'Attività commerciale','private'=>'Privato','online_shop'=>'Shop online']" size="md" />
            <x-ui.filter-select name="sort" label="Ordina per" default="volume" :options="['volume'=>'Spedizioni','value'=>'Valore spedizioni','delivered'=>'Consegnate','name'=>'Cliente']" size="md" />
            <x-ui.filter-select name="order" label="Ordine" default="desc" :options="['desc'=>'Decrescente','asc'=>'Crescente']" />
        </x-slot:secondary>
        @foreach(['customer_id','unassigned','tariff'] as $selection)@if(request()->filled($selection))<input type="hidden" name="{{ $selection }}" value="{{ request($selection) }}" data-filter-label="{{ ['customer_id'=>'Account','unassigned'=>'Senza account','tariff'=>'Tariffa (centesimi)'][$selection] }}">@endif @endforeach
    </x-ui.filters>
    <h2 class="h4 mt-3">Ordini per cliente</h2>
    <p class="data-caption">Prezzi finali salvati · annullati e rifiutati esclusi dagli importi · conteggio per spedizione, non per collo.</p>
    <div class="account-card-grid" aria-label="Clienti e attività">
        @forelse($stores as $store)
            @php
                $selection = $store->customer_id ? ['customer_id'=>$store->customer_id] : ['unassigned'=>1];
                $filters = [...request()->only(['period','year','month','from','to','status','sender_type','q','sort','order']), ...$selection];
                $name = $store->customer_id ? ($customers[$store->customer_id] ?? 'Account non disponibile') : 'Senza account associato';
            @endphp
            <article @class(['surface account-card', 'is-selected' => $store->customer_id ? (int)request('customer_id') === $store->customer_id : request()->boolean('unassigned')])>
                <header><span class="account-symbol"><x-ui.icon name="shop" /></span><div><span class="eyebrow">{{ $store->customer_id ? 'Cliente' : 'Richieste manuali' }}</span><h2><a href="{{ route('stores.index',$filters) }}#account-detail">{{ $name }}</a></h2></div><x-ui.icon-button icon="arrow-up-right" :label="'Statistiche di '.$name" :href="route('stores.index',$filters).'#account-detail'" /></header>
                <div class="account-volume"><strong>{{ $store->orders_count }}</strong><span>spedizioni complessive<small>{{ $store->delivered_count }} consegnate</small></span></div>
                <h3>Tariffe applicate</h3>
                <ul class="account-tariffs">@forelse($accountPrices->get($store->customer_id ?? 'unassigned', collect()) as $price)<li><a href="{{ route('stores.index',[...$filters,'tariff'=>$price->price_cents]) }}#account-detail"><span>{{ \App\Support\Money::format($price->price_cents) }} <span class="text-secondary">× {{ $price->shipments }}</span></span><strong>{{ \App\Support\Money::format((int)$price->total_cents) }}</strong></a></li>@empty<li class="text-secondary small">Nessuna tariffa finale nel periodo.</li>@endforelse</ul>
                <footer><span>Totale spedizioni</span><strong>{{ \App\Support\Money::format((int)$store->total_cents) }}</strong></footer>
            </article>
        @empty<div class="surface empty-state"><x-ui.icon name="bar-chart"/><h2>Nessuna spedizione nel periodo</h2><p>Prova a cambiare intervallo o ricerca.</p></div>@endforelse
    </div>
    {{ $stores->links('components.ui.pagination') }}
    @if($detail)
    <section class="surface section-panel" id="account-detail">
        <header class="page-heading"><div><span class="eyebrow">DETTAGLIO</span><h2>{{ $detailName }}</h2><p>{{ $detail['total'] }} spedizioni · {{ $detail['unpriced'] }} con tariffa da verificare</p></div><strong>{{ \App\Support\Money::format($detail['shipping_spend_cents']) }}</strong><x-ui.icon-button icon="x-lg" label="Chiudi dettaglio cliente" data-close-account-detail text/></header>
        @if(request()->filled('tariff'))<p>Tariffa {{ \App\Support\Money::format((int)request('tariff')) }} <a href="{{ route('stores.index',request()->except(['tariff','detail_page'])) }}">Mostra tutte</a></p>@endif
        <h3 class="h6">Spedizioni che compongono il totale</h3><ul class="account-order-list">
        @forelse($detailOrders as $item)<li><div><a href="{{ route('orders.show',$item) }}">{{ $item->displayName() }}</a><small>Ordine: {{ $item->reference }} · Data: {{ $item->created_at->timezone('Europe/Rome')->format('d/m/Y') }} · Cliente: {{ $item->store_name }} · Località: {{ $item->delivery_city }}</small></div><x-ui.status-badge :status="$item->status" /><strong>Tariffa: {{ \App\Support\Money::format($item->price_cents) }}</strong></li>@empty<li>Nessuna spedizione con questa tariffa.</li>@endforelse
        </ul>{{ $detailOrders->links('components.ui.pagination') }}
    </section>
    @endif
    <section class="report-kpis" aria-label="Resoconto ordini">
        <article class="surface report-kpi"><span>Totale ordini <x-ui.icon name="box-seam"/></span><strong>{{ $summary['total'] }}</strong><small>Periodo precedente: {{ $comparison['total'] }} · {{ $comparison['total'] ? number_format(($summary['total'] - $comparison['total']) * 100 / $comparison['total'], 1, ',', '.').'%' : 'Nessuna base di confronto' }}</small></article>
        <article class="surface report-kpi"><span>Completati <x-ui.icon name="check2-circle"/></span><strong>{{ $summary['delivered'] }}</strong><small>{{ $summary['completion_percent'] }}% degli ordini nel periodo</small></article>
        <article class="surface report-kpi"><span>In corso <x-ui.icon name="truck"/></span><strong>{{ $summary['in_progress'] }}</strong><small>Richieste ancora aperte</small></article>
        <article class="surface report-kpi"><span>Annullati / rifiutati <x-ui.icon name="x-circle"/></span><strong>{{ $summary['cancelled'] }}</strong><small>Esclusi dagli importi delle spedizioni</small></article>
    </section>
    <p class="data-caption">{{ $accountCount }} account attivi · {{ $summary['regional_count'] }} regionali · {{ $summary['external_count'] }} fuori regione · confronto con {{ $previousPeriod->start->format('d/m/Y') }}–{{ $previousPeriod->end->format('d/m/Y') }}</p>
    <x-reports.trend id="statistics-orders" title="Andamento ordini" description="Ordini creati nel periodo selezionato, in ora italiana. I filtri si applicano anche al grafico." :points="$trend" compact/>
</x-app-layout>
