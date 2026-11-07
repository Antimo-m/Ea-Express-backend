<x-app-layout title="Ritiri del {{ $date->format('d/m/Y') }}">
    <nav class="page-back" aria-label="Navigazione agenda"><x-ui.icon-button action="back" :href="route('pickups.index', ['month' => $date->format('Y-m')]).'#pickup-day-'.$date->toDateString()" label="Torna al calendario" text /></nav>
    <header class="page-heading"><div><span class="eyebrow">EA-Express · RESOCONTO GIORNALIERO</span><h1>{{ ucfirst($date->translatedFormat('l j F Y')) }}</h1><p>Ritiri programmati per account, in ordine di orario. Ogni account mantiene tutti i propri ordini nella stessa pagina.</p></div></header>
    <section data-live-region="pickup-day" aria-label="Ritiri della giornata">
        <div class="pickup-day-summary"><p role="status"><strong data-pickup-day-total>{{ $totalOrders }}</strong> {{ $totalOrders === 1 ? 'ritiro programmato' : 'ritiri programmati' }} da effettuare</p>@if($orders->isNotEmpty())<span>{{ $groups->firstItem() }}–{{ $groups->lastItem() }} di {{ $groups->total() }} account · {{ $orders->count() }} ordini in questa pagina</span>@endif</div>
        @forelse($groups as $accountOrders)
            @continue($accountOrders->isEmpty())
            @php($firstOrder = $accountOrders->first())
            @php($accountSlug = $firstOrder->customer?->slug ?? $firstOrder->slug)
            <section class="surface p-0 mb-4" data-pickup-account="{{ $accountSlug }}" aria-labelledby="pickup-account-{{ $accountSlug }}">
                <header class="d-flex flex-wrap justify-content-between align-items-start gap-3 p-3 p-md-4 border-bottom">
                    <div class="text-break"><span class="data-label">{{ $firstOrder->customer ? 'Account cliente' : 'Mittente senza account' }}</span><h2 class="h5 mb-0" id="pickup-account-{{ $accountSlug }}">{{ $firstOrder->customer?->name ?? $firstOrder->displayName() }}</h2></div>
                    <span class="count-badge">{{ $accountOrders->count() }} {{ $accountOrders->count() === 1 ? 'ritiro' : 'ritiri' }} · {{ $accountOrders->sum('parcel_count') }} colli</span>
                </header>
                <form method="get" action="{{ route('labels.index') }}" class="form-stack p-3 p-md-4">
                    <div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-outline-secondary" data-select-labels>Seleziona tutti / nessuno</button><x-ui.icon-button action="print-multiple" label="Stampa tutte le etichette" data-print-all-labels /><x-ui.icon-button action="print-multiple" type="submit" label="Stampa etichette selezionate" text /></div>
                    <div class="pickup-day-orders">
                    @foreach($accountOrders as $order)
                <article class="surface pickup-order-card" data-operational-order="{{ $order->slug }}" data-order-state="{{ $order->status->value }}" aria-labelledby="pickup-order-{{ $order->slug }}">
                    <header><div><span class="pickup-order-reference">{{ $order->reference }}</span><h3 class="h6 mt-2 mb-0 text-break" id="pickup-order-{{ $order->slug }}">{{ $order->displayName() }}</h3></div><x-ui.status-badge :status="$order->status" /><label class="form-check"><input class="form-check-input" type="checkbox" name="ids[]" value="{{ $order->slug }}" aria-label="Etichetta {{ $order->reference }}" checked><span class="form-check-label">Etichetta</span></label></header>
                    <dl>
                        <div><dt>Fascia di ritiro</dt><dd class="pickup-order-time">{{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</dd></div>
                        <div><dt>Rider assegnato</dt><dd>{{ $order->rider?->name ?: 'Da assegnare' }}</dd></div>
                        <div><dt>Indirizzo di ritiro</dt><dd>{{ $order->pickup_address }} {{ $order->pickup_street_number }}<span>{{ $order->pickup_postal_code }} {{ $order->pickup_city }}</span></dd></div>
                        <div><dt>Destinazione</dt><dd>{{ $order->delivery_address }} {{ $order->delivery_street_number }}<span>{{ $order->delivery_postal_code }} {{ $order->delivery_city }}</span><x-orders.recipient-name :name="$order->recipient_name" :risk="$recipientRisks[$order->id] ?? ['count' => 0, 'last_at' => null]" /></dd></div>
                    </dl>
                    <footer><span>{{ $order->parcel_count }} {{ $order->parcel_count === 1 ? 'collo' : 'colli' }}</span><a class="btn btn-outline-secondary" data-order-link="{{ route('orders.show', $order) }}" href="{{ route('orders.show', $order) }}" aria-label="Apri ordine {{ $order->reference }}">Apri dettaglio completo <x-ui.icon name="arrow-up-right" /></a></footer>
                </article>
                    @endforeach
                    </div>
                    <p class="small text-secondary mb-0">Massimo 100 ordini e 500 colli per stampa. Ogni etichetta mantiene il riferimento del proprio ordine.</p>
                </form>
            </section>
        @empty
                <div class="surface pickup-day-empty"><x-ui.icon name="calendar2-check" />
                    @if($totalOrders > 0)<h2>Nessun ritiro in questa pagina</h2><p>La lista è cambiata. Sono presenti {{ $totalOrders }} ritiri nella giornata.</p><a class="btn btn-outline-secondary mt-3" href="{{ route('pickups.day', ['date' => $date->toDateString()]) }}">Torna alla prima pagina</a>
                    @else<h2>Nessun ritiro da effettuare</h2><p>Per questa data non risultano richieste ancora da ritirare. Annullamenti, rifiuti e ritiri già completati sono esclusi.</p>@endif
                </div>
        @endforelse
        @if($orders->isNotEmpty()){{ $groups->onEachSide(1)->links('components.ui.pagination', ['descriptive' => true, 'numbered' => true]) }}@endif
    </section>
</x-app-layout>
