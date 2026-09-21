<x-app-layout title="Statistiche clienti">
    <header class="page-heading"><div><span class="eyebrow">ANALISI PER ACCOUNT</span><h1>Statistiche clienti</h1><p>Chi spedisce, quali tariffe utilizza e quanto genera.</p></div><span class="count-badge">{{ $stores->total() }} account / gruppi</span></header>
    <form method="get" class="filter-bar surface">
        <x-ui.field name="q" label="Cerca account" :value="request('q')" placeholder="Nome dell’account"/>
        <div><label class="form-label" for="period">Periodo</label><select class="form-select" id="period" name="period">@foreach(['today'=>'Oggi','week'=>'Settimana','month'=>'Mese','custom'=>'Intervallo'] as $value=>$label)<option value="{{ $value }}" @selected(request('period','month')===$value)>{{ $label }}</option>@endforeach</select></div>
        <x-ui.field name="from" label="Dal" type="date" :value="$period->start->toDateString()"/>
        <x-ui.field name="to" label="Al" type="date" :value="$period->end->toDateString()"/>
        <div><label for="status" class="form-label">Stato</label><select id="status" name="status" class="form-select"><option value="">Tutti</option>@foreach(\App\OrderStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status')===$status->value)>{{ $status->label() }}</option>@endforeach</select></div>
        <button class="btn btn-primary"><x-ui.icon name="filter"/>Applica</button>
    </form>
    <p class="data-caption">Prezzi finali salvati · annullati e rifiutati esclusi dagli importi · conteggio per spedizione, non per collo.</p>
    <div class="account-list">
    @forelse($stores as $store)
        @php
            $selection = $store->customer_id ? ['customer_id'=>$store->customer_id] : ['unassigned'=>1];
            $filters = [...request()->only(['period','from','to','status','sender_type','q']), ...$selection];
            $name = $store->customer_id ? ($customers[$store->customer_id] ?? 'Account non disponibile') : 'Senza account associato';
        @endphp
        <article class="surface account-row">
            <div class="account-identity"><span class="account-monogram" aria-hidden="true">{{ mb_strtoupper(mb_substr($name,0,1)) }}</span><div><a href="{{ route('stores.index',$filters) }}"><h2>{{ $name }}</h2></a><p>{{ $store->orders_count }} spedizioni · {{ $store->delivered_count }} consegnate @if($store->customer_id)· Account #{{ $store->customer_id }}@endif</p></div></div>
            <div class="tariff-composition" aria-label="Composizione tariffe">
                @forelse($accountPrices->get($store->customer_id ?? 'unassigned', collect()) as $price)
                    <a class="tariff-chip" href="{{ route('stores.index',[...$filters,'tariff'=>$price->price_cents]) }}">{{ \App\Support\Money::format($price->price_cents) }} <span>× {{ $price->shipments }}</span><strong>{{ \App\Support\Money::format((int)$price->total_cents) }}</strong></a>
                @empty<span class="text-secondary">Nessuna tariffa finale</span>@endforelse
            </div>
            <div class="account-total"><span>Totale spedizioni</span><strong>{{ \App\Support\Money::format((int)$store->total_cents) }}</strong></div>
        </article>
    @empty<div class="surface empty-state"><x-ui.icon name="bar-chart"/><h2>Nessuna spedizione nel periodo</h2><p>Prova a cambiare intervallo o ricerca.</p></div>@endforelse
    </div>
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
