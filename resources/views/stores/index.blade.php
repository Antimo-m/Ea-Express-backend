<x-app-layout title="Statistiche Clienti">
    <header class="page-heading"><div><span class="eyebrow">ANALISI PER ACCOUNT</span><h1>Statistiche Clienti</h1><p>Chi spedisce, quali tariffe utilizza e quanto genera.</p></div><span class="count-badge">{{ $stores->total() }} account / gruppi</span></header>
    <section class="report-kpis" aria-label="Resoconto ordini">
        <article class="surface report-kpi"><span>Totale ordini <x-ui.icon name="box-seam"/></span><strong>{{ $summary['total'] }}</strong><small>Periodo precedente: {{ $comparison['total'] }} · {{ $comparison['total'] ? number_format(($summary['total'] - $comparison['total']) * 100 / $comparison['total'], 1, ',', '.').'%' : 'Nessuna base di confronto' }}</small></article>
        <article class="surface report-kpi"><span>Completati <x-ui.icon name="check2-circle"/></span><strong>{{ $summary['delivered'] }}</strong><small>{{ $summary['completion_percent'] }}% degli ordini nel periodo</small></article>
        <article class="surface report-kpi"><span>In corso <x-ui.icon name="truck"/></span><strong>{{ $summary['in_progress'] }}</strong><small>Richieste ancora aperte</small></article>
        <article class="surface report-kpi"><span>Annullati / rifiutati <x-ui.icon name="x-circle"/></span><strong>{{ $summary['cancelled'] }}</strong><small>Esclusi dagli importi delle spedizioni</small></article>
    </section>
    <p class="data-caption">{{ $accountCount }} account attivi · {{ $summary['regional_count'] }} regionali · {{ $summary['external_count'] }} fuori regione · confronto con {{ $previousPeriod->start->format('d/m/Y') }}–{{ $previousPeriod->end->format('d/m/Y') }}</p>
    <x-ui.filters :reset="route('stores.index')">
        <x-ui.field name="q" label="Cerca account" :value="request('q')" placeholder="Nome o email dell’account"/>
        <div><label class="form-label" for="period">Periodo</label><select class="form-select" id="period" name="period">@foreach(['today'=>'Oggi','week'=>'Settimana','month'=>'Mese','custom'=>'Intervallo'] as $value=>$label)<option value="{{ $value }}" @selected(request('period','month')===$value)>{{ $label }}</option>@endforeach</select></div>
        <x-ui.field name="from" label="Dal" type="date" :value="$period->start->toDateString()"/>
        <x-ui.field name="to" label="Al" type="date" :value="$period->end->toDateString()"/>
        <div><label for="sort" class="form-label">Ordina per</label><select id="sort" name="sort" class="form-select">@foreach(['volume'=>'Spedizioni','value'=>'Valore spedizioni','delivered'=>'Consegnate','name'=>'Cliente'] as $value=>$label)<option value="{{ $value }}" @selected(request('sort','volume')===$value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="order" class="form-label">Ordine</label><select id="order" name="order" class="form-select"><option value="desc" @selected(request('order','desc')==='desc')>Decrescente</option><option value="asc" @selected(request('order')==='asc')>Crescente</option></select></div>
        <div><label for="status" class="form-label">Stato</label><select id="status" name="status" class="form-select"><option value="">Tutti</option>@foreach(\App\OrderStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select></div>

    </x-ui.filters>
    <p class="data-caption">Prezzi finali salvati · annullati e rifiutati esclusi dagli importi · conteggio per spedizione, non per collo.</p>
    <x-reports.trend id="statistics-orders" title="Andamento ordini" description="Ordini creati ogni giorno nel periodo selezionato, in ora italiana. I filtri si applicano anche al grafico." :points="$trend"/>
    <h2 class="h4 mt-4">Ordini per cliente</h2>
    <div class="account-card-grid" aria-label="Clienti e attività">
        @forelse($stores as $store)
            @php
                $selection = $store->customer_id ? ['customer_id'=>$store->customer_id] : ['unassigned'=>1];
                $filters = [...request()->only(['period','from','to','status','sender_type','q','sort','order']), ...$selection];
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
    {{ $stores->links() }}
    @if($detail)
    <section class="surface section-panel" id="account-detail">
        <header class="page-heading"><div><span class="eyebrow">DETTAGLIO</span><h2>{{ $detailName }}</h2><p>{{ $detail['total'] }} spedizioni · {{ $detail['unpriced'] }} con tariffa da verificare</p></div><strong>{{ \App\Support\Money::format($detail['shipping_spend_cents']) }}</strong><x-ui.icon-button icon="x-lg" label="Chiudi dettaglio cliente" data-close-account-detail text/></header>
        @if(request()->filled('tariff'))<p>Tariffa {{ \App\Support\Money::format((int)request('tariff')) }} <a href="{{ route('stores.index',request()->except(['tariff','detail_page'])) }}">Mostra tutte</a></p>@endif
        <h3 class="h6">Spedizioni che compongono il totale</h3><ul class="account-order-list">
        @forelse($detailOrders as $item)<li><div><a href="{{ route('orders.show',$item) }}">{{ $item->displayName() }}</a><small>{{ $item->reference }} · {{ $item->created_at->timezone('Europe/Rome')->format('d/m/Y') }} · {{ $item->store_name }} · {{ $item->delivery_city }}</small></div><span class="status-badge">{{ $item->status->label() }}</span><strong>{{ \App\Support\Money::format($item->price_cents) }}</strong></li>@empty<li>Nessuna spedizione con questa tariffa.</li>@endforelse
        </ul>{{ $detailOrders->links() }}
    </section>
    @endif
</x-app-layout>
