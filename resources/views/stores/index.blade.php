<x-app-layout title="Statistiche clienti">
    <header class="page-heading"><div><span class="eyebrow">ANALISI PER ACCOUNT</span><h1>Statistiche clienti</h1><p>Chi spedisce, quali tariffe utilizza e quanto genera.</p></div><span class="count-badge">{{ $stores->total() }} account / gruppi</span></header>
    <section class="finance-kpis" aria-label="Statistiche del periodo filtrato">
        <article class="surface finance-kpi"><div><span>Account attivi nel periodo</span><strong>{{ $accountCount }}</strong><small>Identificati dall’ID cliente</small></div></article>
        <article class="surface finance-kpi"><div><span>Spedizioni</span><strong>{{ $summary['total'] }}</strong><small>{{ $summary['delivered'] }} consegnate · {{ $summary['in_progress'] }} in corso</small></div></article>
        <article class="surface finance-kpi"><div><span>Valore delle spedizioni</span><strong>{{ \App\Support\Money::format($summary['shipping_spend_cents']) }}</strong><small>Tariffe finali, esclusi annullati e rifiutati</small></div></article>
    </section>
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
    <section class="surface workspace-section">
        <header class="section-heading"><div><span class="eyebrow">ACCOUNT, NON NOMI DI RITIRO</span><h2>Clienti e attività</h2></div><span class="count-badge">{{ $stores->total() }} gruppi</span></header>
        <div class="table-responsive"><table class="table workspace-table" data-table-static><thead><tr>
            @foreach(['name'=>'Cliente','volume'=>'Spedizioni','delivered'=>'Consegnate','value'=>'Valore spedizioni'] as $sortKey=>$heading)
                <th scope="col" @if(request('sort','volume')===$sortKey) aria-sort="{{ request('order','desc')==='asc' ? 'ascending' : 'descending' }}" @endif><a class="table-sort" href="{{ route('stores.index',[...request()->except(['sort','order','page','detail_page']), 'sort'=>$sortKey, 'order'=>request('sort','volume')===$sortKey && request('order','desc')==='desc' ? 'asc' : 'desc']) }}">{{ $heading }} <x-ui.icon :name="request('sort','volume')===$sortKey ? (request('order','desc')==='asc' ? 'arrow-up' : 'arrow-down') : 'arrow-down-up'" /></a></th>
            @endforeach
            <th scope="col">Tariffe applicate</th><th scope="col">Dettaglio</th>
        </tr></thead><tbody>
        @forelse($stores as $store)
            @php
                $selection = $store->customer_id ? ['customer_id'=>$store->customer_id] : ['unassigned'=>1];
                $filters = [...request()->only(['period','from','to','status','sender_type','q','sort','order']), ...$selection];
                $name = $store->customer_id ? ($customers[$store->customer_id] ?? 'Account non disponibile') : 'Senza account associato';
            @endphp
            <tr @class(['selected-row' => $store->customer_id ? (int)request('customer_id') === $store->customer_id : request()->boolean('unassigned')])>
                <td data-label="Cliente"><a class="account-table-name" href="{{ route('stores.index',$filters) }}#account-detail">{{ $name }}</a><small class="d-block text-secondary">{{ $store->customer_id ? 'Account #'.$store->customer_id : 'Richieste senza un account cliente collegato' }}</small></td>
                <td data-label="Spedizioni" class="money"><strong>{{ $store->orders_count }}</strong></td>
                <td data-label="Consegnate" class="money">{{ $store->delivered_count }}</td>
                <td data-label="Valore spedizioni" class="money"><strong>{{ \App\Support\Money::format((int)$store->total_cents) }}</strong></td>
                <td data-label="Tariffe applicate"><div class="tariff-table-list">@forelse($accountPrices->get($store->customer_id ?? 'unassigned', collect()) as $price)<a class="tariff-chip" href="{{ route('stores.index',[...$filters,'tariff'=>$price->price_cents]) }}#account-detail">{{ \App\Support\Money::format($price->price_cents) }} <span>× {{ $price->shipments }}</span><strong>{{ \App\Support\Money::format((int)$price->total_cents) }}</strong></a>@empty<span class="text-secondary small">Nessuna tariffa finale</span>@endforelse</div></td>
                <td data-label="Dettaglio"><x-ui.icon-button icon="arrow-up-right" :label="'Statistiche di '.$name" :href="route('stores.index',$filters).'#account-detail'" /></td>
            </tr>
        @empty<tr><td colspan="6"><div class="empty-state"><x-ui.icon name="bar-chart"/><h2>Nessuna spedizione nel periodo</h2><p>Prova a cambiare intervallo o ricerca.</p></div></td></tr>@endforelse
        </tbody></table></div>
    </section>
    {{ $stores->links() }}
    @if($detail)
    <section class="surface section-panel" id="account-detail">
        <header class="page-heading"><div><span class="eyebrow">DETTAGLIO</span><h2>{{ $detailName }}</h2><p>{{ $detail['total'] }} spedizioni · {{ $detail['unpriced'] }} con tariffa da verificare</p></div><strong>{{ \App\Support\Money::format($detail['shipping_spend_cents']) }}</strong></header>
        @if(request()->filled('tariff'))<p>Tariffa {{ \App\Support\Money::format((int)request('tariff')) }} <a href="{{ route('stores.index',request()->except(['tariff','detail_page'])) }}">Mostra tutte</a></p>@endif
        <h3 class="h6">Spedizioni che compongono il totale</h3><div class="table-responsive"><table class="table data-table"><thead><tr><th>Spedizione</th><th>Nome del ritiro</th><th>Destinazione</th><th>Stato</th><th>Tariffa</th></tr></thead><tbody>
        @forelse($detailOrders as $item)<tr><td><a href="{{ route('orders.show',$item) }}">{{ $item->reference }}</a><small class="d-block">{{ $item->created_at->timezone('Europe/Rome')->format('d/m/Y') }}</small></td><td>{{ $item->store_name }}</td><td>{{ $item->delivery_city }}</td><td><span class="status-badge">{{ $item->status->label() }}</span></td><td class="money">{{ \App\Support\Money::format($item->price_cents) }}</td></tr>@empty<tr><td colspan="5">Nessuna spedizione con questa tariffa.</td></tr>@endforelse
        </tbody></table></div>{{ $detailOrders->links() }}
    </section>
    @endif
</x-app-layout>
