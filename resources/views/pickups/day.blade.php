<x-app-layout title="Ritiri del {{ $date->format('d/m/Y') }}">
    <nav class="page-back" aria-label="Navigazione agenda"><x-ui.icon-button action="back" :href="route('pickups.index', ['month' => $date->format('Y-m')]).'#pickup-day-'.$date->toDateString()" label="Torna al calendario" text /></nav>
    <header class="page-heading"><div><span class="eyebrow">EA-EXPRESS · RESOCONTO GIORNALIERO</span><h1>{{ ucfirst($date->translatedFormat('l j F Y')) }}</h1><p>Ritiri programmati ancora da effettuare nella giornata selezionata.</p></div><a class="btn btn-outline-secondary" href="{{ route('pickups.grouped', ['view' => 'date', 'date' => $date->toDateString()]) }}">Raggruppa per cliente e stampa etichette</a></header>
    <section data-live-region="pickup-day" aria-label="Ritiri della giornata">
        <div class="pickup-day-summary"><p role="status"><strong data-pickup-day-total>{{ $orders->total() }}</strong> {{ $orders->total() === 1 ? 'ritiro programmato' : 'ritiri programmati' }} da effettuare</p>@if($orders->isNotEmpty())<span>{{ $orders->firstItem() }}–{{ $orders->lastItem() }} di {{ $orders->total() }} ordini</span>@endif</div>
        <div class="pickup-day-orders">
            @forelse($orders as $order)
                <article class="surface pickup-order-card" data-operational-order="{{ $order->slug }}" data-order-state="{{ $order->status->value }}" aria-labelledby="pickup-order-{{ $order->slug }}">
                    <header><div><span class="pickup-order-reference">{{ $order->reference }}</span><h2 id="pickup-order-{{ $order->slug }}">{{ $order->displayName() }}</h2></div><x-ui.status-badge :status="$order->status" /></header>
                    <dl>
                        <div><dt>Fascia di ritiro</dt><dd class="pickup-order-time">{{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</dd></div>
                        <div><dt>Rider assegnato</dt><dd>{{ $order->rider?->name ?: 'Da assegnare' }}</dd></div>
                        <div><dt>Indirizzo di ritiro</dt><dd>{{ $order->pickup_address }} {{ $order->pickup_street_number }}<span>{{ $order->pickup_postal_code }} {{ $order->pickup_city }}</span></dd></div>
                        <div><dt>Destinazione</dt><dd>{{ $order->delivery_address }} {{ $order->delivery_street_number }}<span>{{ $order->delivery_postal_code }} {{ $order->delivery_city }}</span><x-orders.recipient-name :name="$order->recipient_name" :risk="$recipientRisks[$order->id] ?? ['count' => 0, 'last_at' => null]" /></dd></div>
                    </dl>
                    <footer><span>{{ $order->parcel_count }} {{ $order->parcel_count === 1 ? 'collo' : 'colli' }}</span><a class="btn btn-outline-secondary" data-order-link="{{ route('orders.show', $order) }}" href="{{ route('orders.show', $order) }}" aria-label="Apri ordine {{ $order->reference }}">Apri dettaglio completo <x-ui.icon name="arrow-up-right" /></a></footer>
                </article>
            @empty
                <div class="surface pickup-day-empty"><x-ui.icon name="calendar2-check" />
                    @if($orders->total() > 0)<h2>Nessun ritiro in questa pagina</h2><p>La lista è cambiata. Sono presenti {{ $orders->total() }} ritiri nella giornata.</p><a class="btn btn-outline-secondary mt-3" href="{{ route('pickups.day', ['date' => $date->toDateString()]) }}">Torna alla prima pagina</a>
                    @else<h2>Nessun ritiro da effettuare</h2><p>Per questa data non risultano richieste ancora da ritirare. Annullamenti, rifiuti e ritiri già completati sono esclusi.</p>@endif
                </div>
            @endforelse
        </div>
        @if($orders->isNotEmpty()){{ $orders->onEachSide(1)->links('components.ui.pagination', ['descriptive' => true, 'numbered' => true]) }}@endif
    </section>
</x-app-layout>
