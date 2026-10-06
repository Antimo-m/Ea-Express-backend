<x-app-layout title="Dettaglio ordine">
<div class="order-page" data-live-order="{{ $order->slug }}" data-order-version="{{ $order->version }}">
    @php($operationsReturn = preg_match('~^/rider-operations(?:/[0-9a-f-]{36})?(?:\?|$)~', (string)request('return_to')) ? request('return_to') : null)
    <nav class="page-back" aria-label="Navigazione pagina"><x-ui.icon-button action="back" :href="$operationsReturn ?: route(auth()->user()->role === \App\UserRole::Rider ? 'orders.in-progress' : 'orders.incoming')" label="Torna indietro" text /></nav><header class="page-heading"><div><span class="eyebrow text-break">Ordine: {{ $order->reference }}</span><span class="data-label">Cliente / negozio</span><h1>{{ $order->displayName() }}</h1><p class="text-secondary mb-0">{{ $order->sender_type === 'private' ? 'Privato' : ($order->sender_type === 'online_shop' ? 'Shop online' : ($order->business_type ?: 'Attività commerciale')) }} · {{ $order->pickup_city }} → {{ $order->delivery_city }}</p></div></header>
    <section class="surface order-operational-summary">
        <header class="card-heading"><div><span class="data-label">Stato spedizione</span><h2 class="h5 mb-0">{{ $order->status->label() }}</h2></div><div class="card-actions"><x-ui.icon-button action="message" :href="route('messages.show', $order)" label="Apri messaggi del cliente" /></div></header>
        <dl class="operation-facts">
            <div><dt>Nome destinatario / affidabilità</dt><dd><x-orders.recipient-name :name="$order->recipient_name" :risk="$recipientRisk" /></dd></div>
            <div><dt>Cliente / negozio</dt><dd>{{ $order->displayName() }}</dd></div>
            <div><dt>Rider</dt><dd>{{ $order->rider?->name ?: 'Da assegnare' }}</dd></div>
            <div><dt>Indirizzo di consegna</dt><dd>{{ $order->delivery_address }} {{ $order->delivery_street_number }}, {{ $order->delivery_city }}</dd></div>
            <div><dt>Orario previsto</dt><dd>{{ $order->estimated_at?->timezone('Europe/Rome')->format('d/m/Y H:i') ?: 'Non disponibile' }}</dd></div>
        </dl>
    </section>
    <x-orders.progress :progress="$progress" />
    <div class="order-detail-layout">
        <div class="order-data-stack"><section class="order-overview"><x-orders.card :order="$order" :action="false" :recipient-risk="$recipientRisk" /></section>

        <section class="surface order-information p-4"><header class="card-heading"><h2 class="h5 mb-0">Dati utili per la consegna</h2><div class="card-actions">@if($order->shipping_type === 'external' && !in_array($order->status->value, \App\OrderStatus::closed(), true))<x-ui.action-dialog :id="'carrier-data-'.$order->id" title="Dati del vettore (facoltativi)"><x-orders.carrier :order="$order" /></x-ui.action-dialog>@endif
@if($canReschedulePickup)@can('update',$order)<x-orders.pickup-schedule :order="$order" />@endcan @endif<x-ui.icon-button action="print" href="{{ route('labels.index',['ids'=>[$order->slug]]) }}" label="Stampa etichetta" target="_blank" rel="noopener" /></div></header><dl class="operation-facts"><div><dt>Servizio</dt><dd>{{ $order->shipping_type === 'external' ? 'Fuori regione' : 'Regionale' }}</dd></div><div><dt>Provincia / Regione</dt><dd>{{ $order->delivery_province }} · {{ $order->delivery_region }}</dd></div><div><dt>Destinatario</dt><dd><x-orders.recipient-name :name="$order->recipient_name" :risk="$recipientRisk" /></dd></div><div><dt>Telefono destinatario</dt><dd>{{ $order->recipient_phone }}</dd></div><div><dt>Contatto mittente</dt><dd>{{ $order->contact_email ?: 'Non indicato' }}</dd></div><div><dt>Contenuto</dt><dd>{{ \App\Support\OrderContent::label($order->category, $order->content_description) }}</dd></div>@if(auth()->user()->role === \App\UserRole::Admin)<div><dt>Incasso effettivo registrato</dt><dd>{{ \App\Support\Money::format((int) $order->payments_sum_amount_cents) }}</dd></div>@endif<div><dt>Tracking vettore</dt><dd>{{ $order->carrier_tracking ?: 'Non indicato' }}</dd></div><div><dt>Tariffa prevista</dt><dd>{{ \App\Support\Money::format($order->price_cents) }}</dd></div><div><dt>Valore merce dichiarato</dt><dd>{{ $order->parcel_value_cents === null ? 'Non dichiarato' : \App\Support\Money::format($order->parcel_value_cents) }}</dd></div><div><dt>Preferenza oraria di consegna</dt><dd>{{ $order->delivery_window ?: 'Nessuna preferenza' }}</dd></div><div><dt>Rider</dt><dd>{{ $order->rider?->name ?: 'Da assegnare' }}</dd></div></dl>
        @if($order->packages)<h3 class="h6 mt-4">Peso e dimensioni dei pacchi</h3><div class="package-summary">@foreach($order->packages as $package)<p class="small mb-2"><strong>Pacco {{ $loop->iteration }}</strong> · {{ $package['weight_kg'] }} kg · {{ $package['length_cm'] }} × {{ $package['width_cm'] }} × {{ $package['height_cm'] }} cm</p>@endforeach</div>@endif
        @if($order->business_description)<p class="small text-secondary">Attività: {{ $order->business_description }}</p>@endif
        @if($order->customer_notes)<div class="operational-note"><strong>Istruzioni del cliente</strong><p>{{ $order->customer_notes }}</p></div>@endif
        @if($order->notes)<div class="operational-note internal"><strong>Note interne · solo operatori</strong><p>{{ $order->notes }}</p></div>@endif

        <p><strong>Tipo pacco: {{ $order->package_type==='fragile'?'FRAGILE':($order->package_type==='other'?$order->package_description:'Pacco standard') }}</strong></p><p>Ritiro: {{ $order->pickup_address }} {{ $order->pickup_street_number }}, {{ $order->pickup_postal_code }} {{ $order->pickup_city }}<br>Consegna: {{ $order->delivery_address }} {{ $order->delivery_street_number }}, {{ $order->delivery_postal_code }} {{ $order->delivery_city }}</p>
@if(auth()->user()->role === \App\UserRole::Admin)<div class="mt-3"><x-ui.action-dialog :id="'price-origin-'.$order->id" action="history" title="Origine del prezzo" text><p class="mt-2">Tariffa iniziale: {{ \App\Support\Money::format($order->quoted_price_cents) }} · Tariffa applicata: {{ \App\Support\Money::format($order->price_cents) }}</p><p>Versione listino #{{ $order->shipping_rate_id ?? 'storica' }} · {{ $order->rate_snapshot['city'] ?? $order->delivery_city }} · {{ $order->rate_snapshot['source_reference'] ?? 'Fonte non registrata nello storico' }}</p><a href="{{ route('audits.index',['type'=>'orders','slug'=>$order->slug]) }}">Storico economico dell’ordine</a></x-ui.action-dialog></div>@endif
        </section></div>
        <aside class="order-workflow">
        @if(auth()->user()->role === \App\UserRole::Admin && !in_array($order->status->value, [...\App\OrderStatus::closed(), 'received'], true))
        <section class="surface rider-assignment-card">
            <header class="card-heading"><h2 class="h5 mb-0">Assegnazione Rider</h2></header>
            <p class="small"><strong>Rider attuale:</strong> {{ $order->rider?->name ?: 'Da assegnare' }}</p>
            <form id="rider-assignment-{{ $order->id }}" data-order-action method="post" action="{{ route('orders.rider', $order) }}" class="rider-assignment-controls">
                @csrf @method('patch')<input type="hidden" name="version" value="{{ $order->version }}">
                <div><label class="form-label" for="reassign-rider">Rider da assegnare</label><select class="form-select" id="reassign-rider" data-inline-label="Rider" name="rider_id" required><option value="">Seleziona un Rider</option>@foreach($riders as $rider)<option value="{{ $rider->slug }}" @selected($order->rider?->slug === $rider->slug)>{{ $rider->name }}{{ $rider->slug === auth()->user()->slug ? ' · Tu (Admin)' : '' }}</option>@endforeach</select></div><x-ui.icon-button action="confirm" type="submit" label="Conferma Rider" />
            </form>
        </section>
        @endif
@if(auth()->user()->role === \App\UserRole::Admin)<x-orders.price :order="$order" />@endif
            @can('update', $order)
                @if(count($transitions) && ($order->status !== \App\OrderStatus::Rejected || $order->recoverable()))
                    <x-orders.workflow :order="$order" :transitions="$transitions" :riders="$riders" />
                @elseif($order->status === \App\OrderStatus::Rejected)<div class="surface p-4"><h2 class="h6">Richiesta rifiutata</h2><p class="small text-secondary mb-0">La finestra di un’ora per recuperarla è scaduta.</p></div>
                @else<div class="surface p-4"><x-ui.status-badge class="status-pill tone-{{ $order->status->tone() }}">{{ $order->status->label() }}</x-ui.status-badge><p class="small text-secondary mt-3 mb-0">Non sono richieste altre operazioni su questa spedizione.</p></div>@endif
            @endcan
        </aside>
    </div>
    <section class="surface order-event-history p-4 mt-4"><h2 class="h5">Cronologia ordine <span class="count-pill">{{ $events->total() }}</span></h2><x-orders.timeline :events="$events" /></section>
</div>
</x-app-layout>
