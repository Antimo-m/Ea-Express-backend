<x-app-layout :title="$title">
    <header class="dashboard-heading mb-4"><div><span class="eyebrow">CENTRO OPERATIVO</span><h1>{{ $title }} @if($section==='orders.in-progress')<span data-live-region="current-count"><span class="shipment-count" aria-label="Spedizioni in corso">{{ $currentCount }}</span></span>@endif</h1><p class="text-secondary mb-0">{{ $section === 'orders.history' ? 'Consulta gli ordini chiusi degli ultimi 12 mesi.' : 'Organizza ritiri e consegne, una richiesta alla volta.' }}</p></div><div class="row-actions">@if($section === 'orders.in-progress')<x-ui.icon-button action="print-multiple" target="_blank" rel="noopener" href="{{ route('labels.index',[...request()->only(['q','zone','status','customer_id','rider_id','shipping_type','urgency','sender_type','from','to']),'scope'=>'filtered']) }}" label="Stampa etichette dei risultati filtrati" />@endif @if(auth()->user()->role === \App\UserRole::Admin)<x-ui.icon-button action="add" href="{{ route('orders.create') }}" label="Nuova richiesta" />@endif</div></header>
    <x-ui.filters :reset="route($section)">
        <x-slot:search><x-ui.field name="q" label="Cerca ordine" :value="request('q')" placeholder="Cerca ordine, cliente, destinatario…" /></x-slot:search>
        @if($section === 'orders.history')
            <div class="filter-range" data-filter-range>
                <button type="button" class="btn btn-outline-secondary" data-custom-period aria-controls="filter-custom-dates" aria-expanded="{{ request('from') || request('to') ? 'true' : 'false' }}">Periodo <x-ui.icon name="calendar3" /></button>
                <div class="filter-range-dates" id="filter-custom-dates" data-custom-dates @if(!request('from') && !request('to')) hidden @endif>
                    <x-ui.field name="from" label="Dal" type="date" :value="request('from')" />
                    <x-ui.field name="to" label="Al" type="date" :value="request('to')" />
                </div>
            </div>
        @endif
        @if($section !== 'orders.incoming')
            <x-ui.filter-select name="status" label="Stato" :options="[''=>'Tutti', ...collect(\App\OrderStatus::cases())->filter(fn($state)=>$section === 'orders.history' ? in_array($state->value, \App\OrderStatus::closed()) : !in_array($state->value, [...\App\OrderStatus::closed(), 'received']))->mapWithKeys(fn($state)=>[$state->value=>$state->label()])->all()]" />
            @if($section === 'orders.history')<x-ui.searchable-select name="customer_id" label="Cliente" :options="$customers" :value="request('customer_id')" />
            @else<x-ui.searchable-select name="rider_id" label="Rider" :options="$riders" :value="request('rider_id')" />@endif
        @else
            <div class="filter-control filter-md"><x-ui.field name="zone" label="Zona" :value="request('zone')" placeholder="Zona / comune…" /></div>
            <x-ui.filter-select name="urgency" label="Urgenza" :options="[''=>'Tutte','standard'=>'Standard','urgent'=>'Urgente']" />
        @endif
        <x-slot:secondary>
            @if($section === 'orders.history')<x-ui.searchable-select name="rider_id" label="Rider" :options="$riders" :value="request('rider_id')" />@endif
            @if($section !== 'orders.incoming')
                <div class="filter-control filter-md"><x-ui.field name="zone" label="Zona" :value="request('zone')" placeholder="Zona / comune…" /></div>
            @endif
            <x-ui.filter-select name="shipping_type" label="Servizio" :options="[''=>'Tutti','regional'=>'Regionale','external'=>'Fuori regione']" size="md" />
            @if($section !== 'orders.incoming')<x-ui.filter-select name="urgency" label="Urgenza" :options="[''=>'Tutte','standard'=>'Standard','urgent'=>'Urgente']" />@endif
            <x-ui.filter-select name="sender_type" label="Tipo mittente" :options="[''=>'Tutti','business'=>'Attività commerciale','private'=>'Privato','online_shop'=>'Shop online']" size="md" />
        </x-slot:secondary>
    </x-ui.filters>
    <div data-live-region="orders">@if($section==='orders.in-progress')<p class="data-caption">{{ $orders->total() }} risultati filtrati · La stampa nell’intestazione include tutte le pagine dei risultati, un’etichetta A6 per spedizione.</p>@endif
<section class="surface order-table-surface" aria-label="Elenco spedizioni">@forelse($orders as $order)<x-orders.row :order="$order" :recipient-risk="$recipientRisks[$order->id] ?? ['count' => 0, 'last_at' => null]" />@empty<div class="surface p-5 text-center"><x-ui.icon name="inbox" class="display-6 text-secondary" /><h2 class="h5 mt-3">Nessun ordine trovato</h2><p class="text-secondary mb-0">{{ auth()->user()->role === \App\UserRole::Rider ? 'Qui trovi le spedizioni assegnate al tuo account. Prova a modificare i filtri.' : 'Le richieste compariranno qui. Puoi crearne una o modificare i filtri.' }}</p></div>@endforelse</section>
    <div class="mt-4">{{ $orders->links('components.ui.pagination') }}</div>
</div></x-app-layout>
