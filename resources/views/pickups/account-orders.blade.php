<div class="pickup-account-body" data-pickup-orders data-pickup-orders-page="{{ $orders->currentPage() }}" data-pickup-orders-last-page="{{ $orders->lastPage() }}" data-pickup-orders-total="{{ $orders->total() }}" data-pickup-orders-parcels="{{ $accountSummary->parcel_count }}" data-pickup-account-slug="{{ $accountSlug }}" data-pickup-date="{{ $date->toDateString() }}" data-pickup-kind="{{ $kind }}">
    <p class="pickup-orders-caption">{{ $orders->firstItem() ?? 0 }}–{{ $orders->lastItem() ?? 0 }} di {{ $orders->total() }} ritiri · Pagina {{ $orders->currentPage() }} di {{ $orders->lastPage() }}</p>
    <form method="get" action="{{ route('labels.index') }}" target="_blank" class="pickup-print-form">
        <div class="pickup-print-actions"><button type="button" class="btn btn-outline-secondary" data-select-labels>Seleziona pagina / nessuno</button><x-ui.icon-button action="print-multiple" type="submit" label="Stampa etichette selezionate di questa pagina" text /></div>
        <div class="pickup-day-orders">
        @forelse($orders as $order)
            <article class="surface pickup-order-card" data-operational-order="{{ $order->slug }}" data-order-state="{{ $order->status->value }}" aria-labelledby="pickup-order-{{ $order->slug }}">
                <header><h3 class="h6 mb-0 text-break" id="pickup-order-{{ $order->slug }}">{{ $order->displayName() }}</h3><x-ui.status-badge :status="$order->status" /><label class="form-check"><input class="form-check-input" type="checkbox" name="ids[]" value="{{ $order->slug }}" aria-label="Etichetta per {{ $order->recipient_name }}" checked><span class="form-check-label">Etichetta</span></label></header>
                <dl>
                    <div><dt>Fascia di ritiro</dt><dd class="pickup-order-time">{{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</dd></div>
                    <div><dt>Rider assegnato</dt><dd>{{ $order->rider?->name ?: 'Da assegnare' }}</dd></div>
                    <div><dt>Indirizzo di ritiro</dt><dd>{{ $order->pickup_address }} {{ $order->pickup_street_number }}<span>{{ $order->pickup_postal_code }} {{ $order->pickup_city }}</span></dd></div>
                    <div><dt>Destinatario e destinazione</dt><dd><x-orders.recipient-indicator :name="$order->recipient_name" :risk="$recipientRisks[$order->id] ?? null" /><span>{{ $order->delivery_city }}</span></dd></div>
                </dl>
                <footer><span>{{ $order->parcel_count }} {{ $order->parcel_count === 1 ? 'collo' : 'colli' }}</span><a class="btn btn-outline-secondary" href="{{ route('orders.show', $order) }}" aria-label="Apri richiesta per {{ $order->recipient_name }}">Apri dettaglio <x-ui.icon name="arrow-up-right" /></a></footer>
            </article>
        @empty<p>Nessun ordine in questa pagina.</p>@endforelse
        </div>
        <p class="small text-secondary mb-0">La stampa include soltanto gli ordini selezionati nella pagina visualizzata.</p>
    </form>
    <div class="pickup-orders-pagination">{{ $orders->links('components.ui.pagination', ['descriptive' => true]) }}</div>
</div>
