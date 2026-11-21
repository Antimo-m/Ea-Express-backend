<div class="pickup-account-body" data-pickup-orders data-pickup-orders-page="{{ $orders->currentPage() }}" data-pickup-orders-last-page="{{ $orders->lastPage() }}" data-pickup-orders-total="{{ $orders->total() }}" data-pickup-orders-parcels="{{ $accountSummary->parcel_count }}" data-pickup-account-slug="{{ $accountSlug }}" data-pickup-date="{{ $date->toDateString() }}" data-pickup-kind="{{ $kind }}">
    <p class="pickup-orders-caption">{{ $orders->firstItem() ?? 0 }}–{{ $orders->lastItem() ?? 0 }} di {{ $orders->total() }} ritiri · Pagina {{ $orders->currentPage() }} di {{ $orders->lastPage() }}</p>
    <form method="get" action="{{ route('labels.index') }}" target="_blank" class="pickup-print-form" data-label-selection>
        <div class="pickup-print-actions"><button type="button" class="btn btn-outline-secondary" data-select-labels>Seleziona pagina / nessuno</button><a class="btn btn-outline-secondary" href="{{ route('labels.index', ['scope' => 'pickup', 'date' => $date->toDateString(), 'account' => $accountSlug, 'kind' => $kind]) }}" target="_blank" rel="noopener" data-print-day><x-ui.icon name="calendar-check" /> Stampa tutta la giornata</a><x-ui.icon-button action="print-multiple" type="submit" data-label-availability :aria-disabled="$orders->isEmpty() ? 'true' : 'false'" label="Stampa etichette selezionate" text /></div>
        <p class="pickup-print-count" role="status" data-label-count data-label-total="{{ $orders->total() }}">{{ $orders->count() }} {{ $orders->count() === 1 ? 'etichetta selezionata' : 'etichette selezionate' }} su {{ $orders->total() }} {{ $orders->total() === 1 ? 'ordine' : 'ordini' }}</p>
        <div data-label-carry hidden></div>
        <div class="pickup-day-orders">
        @forelse($orders as $order)
            <article class="surface pickup-order-card" data-operational-order="{{ $order->slug }}" data-order-state="{{ $order->status->value }}" aria-labelledby="pickup-order-{{ $order->slug }}">
                <header><h3 class="h6 mb-0 text-break" id="pickup-order-{{ $order->slug }}">{{ $order->displayName() }}</h3><x-ui.status-badge :status="$order->status" /><label class="form-check"><input class="form-check-input" type="checkbox" name="ids[]" value="{{ $order->slug }}" aria-label="Etichetta per {{ $order->recipient_name }}" checked><span class="form-check-label">Etichetta</span></label></header>
                <p class="shipment-card-reference pickup-order-reference">{{ $order->reference }}</p>
                <dl>
                    <div><dt>Data / fascia di ritiro</dt><dd class="pickup-order-time">{{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</dd></div>
                    <div><dt>Rider assegnato</dt><dd>{{ $order->rider?->name ?: 'In attesa di assegnazione' }}</dd></div>
                    <div class="pickup-party"><dt>Mittente</dt><dd><dl class="key-values">
                        <div><dt>Mittente</dt><dd>{{ $order->displayName() }}</dd></div>
                        <div><dt>Indirizzo</dt><dd>{{ trim($order->pickup_address.' '.$order->pickup_street_number) }}</dd></div>
                        @if($order->pickup_city)<div><dt>Comune</dt><dd>{{ $order->pickup_city }}</dd></div>@endif
                        @if($order->pickup_postal_code)<div><dt>CAP</dt><dd>{{ $order->pickup_postal_code }}</dd></div>@endif
                    </dl></dd></div>
                    <div class="pickup-party"><dt>Destinatario</dt><dd><dl class="key-values">
                        <div><dt>Destinatario</dt><dd><x-orders.recipient-indicator :name="$order->recipient_name" :risk="$recipientRisks[$order->id] ?? null" /></dd></div>
                        <div><dt>Indirizzo</dt><dd>{{ trim($order->delivery_address.' '.$order->delivery_street_number) }}</dd></div>
                        @if($order->delivery_zone)<div><dt>Zona</dt><dd>{{ $order->delivery_zone }}</dd></div>@endif
                        @if($order->delivery_city)<div><dt>Comune</dt><dd>{{ $order->delivery_city }}</dd></div>@endif
                        @if($order->delivery_postal_code)<div><dt>CAP</dt><dd>{{ $order->delivery_postal_code }}</dd></div>@endif
                    </dl></dd></div>
                </dl>
                <footer><span>{{ $order->parcel_count }} {{ $order->parcel_count === 1 ? 'collo' : 'colli' }}</span><a class="btn btn-outline-secondary" href="{{ route('orders.show', $order) }}" aria-label="Apri richiesta per {{ $order->recipient_name }}">Apri dettaglio <x-ui.icon name="arrow-up-right" /></a></footer>
            </article>
        @empty<p>Nessun ordine in questa pagina.</p>@endforelse
        </div>
        <p class="small text-secondary mb-0">La stampa della selezione include gli ordini spuntati in tutte le pagine già aperte. «Stampa tutta la giornata» include ogni ordine dell’account per questa data.</p>
    </form>
    <div class="pickup-orders-pagination">{{ $orders->links('components.ui.pagination', ['descriptive' => true]) }}</div>
</div>
