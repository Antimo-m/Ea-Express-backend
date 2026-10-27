<x-app-layout :title="$customer->name">
    <nav class="page-back"><a href="{{ route('customers.index') }}">← Clienti</a></nav>
    <header class="page-heading"><div><span class="eyebrow">PROFILO CLIENTE</span><h1>{{ $customer->name }}</h1><p>{{ ['business'=>'Attività commerciale','private'=>'Privato','online_shop'=>'Shop online'][$customer->sender_type] ?? 'Cliente' }} · {{ $customer->is_active ? 'Account attivo' : 'Account disabilitato' }}</p></div></header>
    <section class="surface customer-identity" aria-label="Identità e contatti">
        <div><span class="data-label">Email</span><a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a></div>
        <div><span class="data-label">Telefono</span>{{ $customer->phone ?: 'Non indicato' }}</div>
        <div><span class="data-label">Attività</span>{{ $customer->business_type ?: 'Non indicata' }}<small>{{ $customer->business_description }}</small></div>
        <div><span class="data-label">Cliente dal</span>{{ $customer->created_at->timezone('Europe/Rome')->format('d/m/Y') }}</div>
    </section>
    <x-ui.filters :reset="route('customers.show', $customer)" layout="analysis">
        <x-slot:period><x-ui.filter-period route="customers.show" :period="$period" /></x-slot:period>
        <x-ui.filter-select name="status" label="Stato" :options="[''=>'Tutti', ...collect(\App\OrderStatus::cases())->mapWithKeys(fn($state)=>[$state->value=>$state->label()])->all()]" />
        @if(request()->filled('tariff'))<input type="hidden" name="tariff" value="{{ request('tariff') }}">@endif
    </x-ui.filters>
    <section class="report-kpis" aria-label="Attività nel periodo">
        <x-ui.metric label="Richieste nel periodo" :value="$summary['total']" :note="$lifetime['total'].' richieste complessive · '.$comparison['total'].' nel periodo precedente'" />
        <x-ui.metric label="Spedizioni completate" :value="$summary['delivered']" tone="positive" :note="$summary['completion_percent'].'% delle richieste'" />
        <x-ui.metric label="In corso" :value="$summary['in_progress']" tone="attention" note="Richieste ancora aperte" />
        <x-ui.metric label="Annullate / rifiutate" :value="$summary['cancelled']" note="Escluse dal valore delle spedizioni" />
    </section>
    <section class="customer-value-grid" aria-label="Valore per EA-Express">
        <article class="surface customer-value"><span class="eyebrow">VALORE SPEDIZIONI</span><strong>{{ \App\Support\Money::format($summary['shipping_spend_cents']) }}</strong><p>Tariffe finali nel periodo · {{ $summary['unpriced'] }} da verificare</p></article>
        <article class="surface customer-value"><span class="eyebrow">INCASSATO EA-EXPRESS</span><strong>{{ \App\Support\Money::format($receivedCents) }}</strong><p>Quota EA registrata nel periodo per il cliente, al netto delle rettifiche.</p>@if($receipts['unallocated'])<small>{{ $receipts['unallocated'] }} incassi fuori regione con quota EA non ripartita.</small>@endif</article>
        <article class="surface customer-value"><span class="eyebrow">TARIFFA MEDIA</span><strong>{{ \App\Support\Money::format($averageCents) }}</strong><p>Calcolata sulle tariffe finali disponibili</p></article>
        <article class="surface customer-value"><span class="eyebrow">SOSPESI ATTUALI</span><strong>{{ \App\Support\Money::format($pendingCents) }}</strong><p>Residuo da incassare · tutti i periodi</p><a href="{{ route('pending.index', ['customer_id'=>$customer->slug]) }}">Apri sospesi →</a></article>
    </section>
    <div class="customer-analysis-grid">
        <section class="surface section-panel"><h2 class="h5">Distribuzione tariffe</h2><p class="data-caption">Prezzi finali · annullati e rifiutati esclusi</p><ul class="account-tariffs">
            @forelse($summary['prices'] as $price)<li><a href="{{ route('customers.show', ['customer'=>$customer, ...request()->except('page'), 'tariff'=>$price->price_cents]) }}#customer-orders"><span>{{ \App\Support\Money::format($price->price_cents) }} × {{ $price->shipments }}</span><strong>{{ \App\Support\Money::format((int)$price->total_cents) }}</strong></a></li>@empty<li>Nessuna tariffa finale nel periodo.</li>@endforelse
        </ul></section>
        <x-reports.trend id="customer-trend" title="Andamento richieste" description="Richieste create nel periodo, in ora italiana. Per l’anno, aggregazione mensile." :points="$trend" compact />
    </div>
    <section class="surface section-panel" id="customer-orders"><header class="page-heading"><div><h2 class="h4">Storico ordini</h2><p>{{ $orders->total() }} richieste nel periodo</p></div>@if(request()->filled('tariff'))<a href="{{ route('customers.show',['customer'=>$customer, ...request()->except(['tariff','page'])]) }}#customer-orders">Tutte le tariffe</a>@endif</header>
        <ul class="account-order-list">@forelse($orders as $order)<li><div><a href="{{ route('orders.show',$order) }}">{{ $order->reference }}</a><small>{{ $order->recipient_name }} · {{ $order->delivery_city }} · {{ $order->created_at->timezone('Europe/Rome')->format('d/m/Y') }}</small></div><x-ui.status-badge :status="$order->status" /><strong>{{ $order->price_cents === null ? 'Da concordare' : \App\Support\Money::format($order->price_cents) }}</strong></li>@empty<li>Nessun ordine per questi filtri.</li>@endforelse</ul>
        {{ $orders->links('components.ui.pagination') }}
    </section>
</x-app-layout>
