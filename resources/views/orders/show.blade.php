<x-app-layout title="Dettaglio ordine">
<div class="order-page" data-live-order="{{ $order->slug }}" data-order-version="{{ $order->version }}">
    @php
        $operationsReturn = preg_match('~^/rider-operations(?:/(?:[0-9a-f-]{36}|zone-orders))?(?:\\?|$)~', (string) request('return_to')) ? request('return_to') : null;
        $returnRoute = in_array($order->status->value, \App\OrderStatus::closed(), true) ? 'orders.history' : ($order->status === \App\OrderStatus::Received ? 'orders.incoming' : 'orders.in-progress');
    @endphp
    <nav class="page-back" aria-label="Navigazione pagina"><x-ui.icon-button action="back" :href="$operationsReturn ?: route($returnRoute)" label="Torna indietro" text /></nav>
    <header class="page-heading order-detail-heading">
        <div><span class="eyebrow text-break">Ordine: {{ $order->reference }}</span><h1>{{ $order->displayName() }}</h1><p>{{ $order->sender_type === 'private' ? 'Privato' : ($order->sender_type === 'online_shop' ? 'Shop online' : ($order->business_type ?: 'Attività commerciale')) }} · {{ $order->pickup_city }} → {{ $order->delivery_city }}</p></div>
        <div class="row-actions"><x-ui.status-badge :status="$order->status" />@if($order->receipt_voided_at)<span class="status-pill tone-danger">Incasso stornato</span>@endif<x-ui.icon-button action="message" :href="route('messages.show', $order)" label="Apri messaggi del cliente" /><x-ui.icon-button action="print" data-label-availability :aria-disabled="$order->parcel_count > 0 ? 'false' : 'true'" href="{{ route('labels.index', ['ids' => [$order->slug]]) }}" label="Stampa etichetta" target="_blank" rel="noopener" /></div>
    </header>
    <div class="order-workflow">
        @can('update', $order)
            @if(count($transitions) && ($order->status !== \App\OrderStatus::Rejected || $order->recoverable()))
                <x-orders.workflow :order="$order" :transitions="$transitions" :riders="$riders" />
            @elseif($order->status === \App\OrderStatus::Rejected)
                <section class="surface order-complete"><x-ui.status-badge :status="$order->status" /><h2>Richiesta rifiutata</h2><p>La finestra di un’ora per recuperarla è scaduta.</p></section>
            @else
                <section class="surface order-complete"><x-ui.status-badge :status="$order->status" /><h2>Operatività conclusa</h2><p>{{ $order->receipt_voided_at ? 'La consegna resta nello storico. L’incasso è stornato e si gestisce nel Bilancio.' : 'Non sono richieste altre operazioni su questa spedizione.' }}</p></section>
            @endif
        @else
            <section class="surface order-complete"><x-ui.status-badge :status="$order->status" /><h2>Riepilogo spedizione</h2><p>Consulta il percorso e i passaggi registrati.</p></section>
        @endcan
    </div>
    <nav class="order-section-nav" aria-label="Sezioni dell’ordine"><a href="#order-route">Percorso e contatti</a><a href="#order-information">Dati spedizione</a>@if(auth()->user()->role === \App\UserRole::Admin)<a href="#order-pricing">Tariffa e Rider</a>@endif<a href="#order-events">Cronologia</a></nav>
    <div class="order-detail-layout">
        <div class="order-data-stack">
            <section class="surface order-route-sheet" id="order-route" aria-labelledby="order-route-title">
                <header class="section-heading"><div><span class="eyebrow">PERCORSO E CONTATTI</span><h2 id="order-route-title">Dal mittente al destinatario</h2></div><span>{{ $order->parcel_count }} {{ $order->parcel_count === 1 ? 'collo' : 'colli' }}</span></header>
                <div class="order-route-stops">
                    <div><span class="order-stop-marker" aria-hidden="true"><x-ui.icon name="box-seam" /></span><div><span class="data-label">Ritiro · {{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</span><h3>{{ $order->displayName() }}</h3><p>{{ $order->pickup_address }} {{ $order->pickup_street_number }}<br>{{ $order->pickup_postal_code }} {{ $order->pickup_city }}</p><span class="register-note">Contatto mittente: {{ $order->contact_email ?: 'Non indicato' }}</span>@if($order->business_description)<p class="register-note">{{ $order->business_description }}</p>@endif</div></div>
                    <div><span class="order-stop-marker" aria-hidden="true"><x-ui.icon name="geo-alt" /></span><div><span class="data-label">Consegna · {{ $order->delivery_province }} / {{ $order->delivery_region }}</span><h3><x-orders.recipient-name :name="$order->recipient_name" :risk="$recipientRisk" /></h3><p>{{ $order->delivery_address }} {{ $order->delivery_street_number }}<br>{{ $order->delivery_postal_code }} {{ $order->delivery_city }}</p><span class="register-note">Telefono destinatario: {{ $order->recipient_phone }}</span><span class="register-note">Preferenza oraria: {{ $order->delivery_window ?: 'Nessuna preferenza' }}</span><span class="register-note">Orario previsto: {{ $order->estimated_at?->timezone('Europe/Rome')->format('d/m/Y H:i') ?: 'Non disponibile' }}</span></div></div>
                </div>
                @if($order->customer_notes)<div class="operational-note"><strong>Istruzioni del cliente</strong><p>{{ $order->customer_notes }}</p></div>@endif
            </section>
            <section class="surface order-information" id="order-information" aria-labelledby="order-information-title">
                <header class="section-heading"><div><span class="eyebrow">LA SPEDIZIONE</span><h2 id="order-information-title">Dati e contenuto</h2></div>@if($order->shipping_type === 'external' && !in_array($order->status->value, \App\OrderStatus::closed(), true))@can('update', $order)<x-ui.action-dialog :id="'carrier-data-'.$order->slug" title="Dati del vettore (facoltativi)"><x-orders.carrier :order="$order" /></x-ui.action-dialog>@endcan @endif</header>
                <dl class="operation-facts">
                    <div><dt>Servizio</dt><dd>{{ $order->shipping_type === 'external' ? 'Fuori regione' : 'Regionale' }}</dd></div>
                    <div><dt>Contenuto</dt><dd>{{ \App\Support\OrderContent::label($order->category, $order->content_description) }}</dd></div>
                    <div><dt>Tipo pacco</dt><dd>{{ $order->package_type === 'fragile' ? 'FRAGILE' : ($order->package_type === 'other' ? $order->package_description : 'Pacco standard') }}</dd></div>
                    <div><dt>Priorità</dt><dd>{{ $order->urgency === 'urgent' ? 'Urgente' : 'Standard' }}</dd></div>
                    <div><dt>Tariffa prevista</dt><dd>{{ \App\Support\Money::format($order->price_cents) }}</dd></div>
                    <div><dt>Valore merce dichiarato</dt><dd>{{ $order->parcel_value_cents === null ? 'Non dichiarato' : \App\Support\Money::format($order->parcel_value_cents) }}</dd></div>
                    <div><dt>Rider</dt><dd>{{ $order->rider?->name ?: 'Da assegnare' }}</dd></div>
                    @if(auth()->user()->role === \App\UserRole::Admin)<div><dt>{{ $receipt['label'] }}</dt><dd>{{ \App\Support\Money::format($receipt['amount_cents']) }}@if($receipt['state'] === 'partial')<small>Residuo: {{ \App\Support\Money::format($receipt['remaining_cents']) }}</small>@endif</dd></div>@endif
                </dl>
                @if($order->packages)<details class="order-package-details"><summary>Peso e dimensioni · {{ count($order->packages) }} {{ count($order->packages) === 1 ? 'pacco' : 'pacchi' }}</summary><div class="package-summary">@foreach($order->packages as $package)<p><strong>Pacco {{ $loop->iteration }}</strong> · {{ $package['weight_kg'] }} kg · {{ $package['length_cm'] }} × {{ $package['width_cm'] }} × {{ $package['height_cm'] }} cm</p>@endforeach</div></details>@endif
                @if($order->notes)<div class="operational-note internal"><strong>Note interne · solo operatori</strong><p>{{ $order->notes }}</p></div>@endif
            </section>
            <x-orders.progress :progress="$progress" />
        </div>
        <aside class="order-support" aria-label="Tariffa e strumenti operativi">
            @if(auth()->user()->role === \App\UserRole::Admin)
                <div id="order-pricing"><x-orders.price :order="$order" />
                    <details class="surface order-price-origin"><summary>Origine del prezzo e storico economico</summary><dl class="operation-facts"><div><dt>Tariffa iniziale</dt><dd>{{ \App\Support\Money::format($order->quoted_price_cents) }}</dd></div><div><dt>Tariffa applicata</dt><dd>{{ \App\Support\Money::format($order->price_cents) }}</dd></div><div><dt>Località listino</dt><dd>{{ $order->rate_snapshot['city'] ?? $order->delivery_city }}</dd></div><div><dt>Fonte</dt><dd>{{ $order->rate_snapshot['source_reference'] ?? 'Fonte non registrata nello storico' }}</dd></div></dl><a href="{{ route('audits.index', ['type' => 'orders', 'slug' => $order->slug]) }}">Storico economico dell’ordine</a></details>
                </div>
                @if(!in_array($order->status->value, [...\App\OrderStatus::closed(), 'received'], true))
                    <section class="surface rider-assignment-card"><header class="section-heading"><h2>Assegnazione Rider</h2></header><p class="small"><strong>Rider attuale:</strong> {{ $order->rider?->name ?: 'Da assegnare' }}</p>
                        <form id="rider-assignment-{{ $order->slug }}" data-order-action method="post" action="{{ route('orders.rider', $order) }}" class="rider-assignment-controls">
                            @csrf @method('patch')<input type="hidden" name="version" value="{{ $order->version }}">
                            <div><label class="form-label" for="reassign-rider">Rider da assegnare</label><select class="form-select" id="reassign-rider" data-inline-label="Rider" name="rider_id" required><option value="">Seleziona un Rider</option>@foreach($riders as $rider)<option value="{{ $rider->slug }}" @selected($order->rider?->slug === $rider->slug)>{{ $rider->name }}{{ $rider->slug === auth()->user()->slug ? ' · Tu (Admin)' : '' }}</option>@endforeach</select></div><x-ui.icon-button action="confirm" type="submit" label="Conferma Rider" />
                        </form>
                    </section>
                @endif
            @endif
        </aside>
    </div>
    <section class="surface order-event-history" id="order-events"><header class="section-heading"><div><span class="eyebrow">PASSAGGI REGISTRATI</span><h2>Cronologia ordine <span class="count-pill">{{ $events->total() }}</span></h2></div></header><x-orders.timeline :events="$events" /></section>
</div>
</x-app-layout>
