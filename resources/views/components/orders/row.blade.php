@props(['order', 'recipientRisk' => null, 'archive' => false])
@if($archive)
<article class="history-order history-card" data-order-link="{{ route('orders.show', $order) }}" role="link" tabindex="0" aria-label="Apri la richiesta per {{ $order->recipient_name }}">
    <header class="history-card-head">
        <span class="history-card-status"><x-ui.status-badge :status="$order->status" />@if($order->receipt_voided_at)<span class="status-pill tone-danger">Incasso stornato</span>@endif</span>
        <span class="shipment-card-reference">{{ $order->reference }}</span>
        <strong class="money history-card-price">{{ \App\Support\Money::format($order->price_cents) }}</strong>
    </header>
    <div class="history-card-body">
        <div class="history-order-identity history-card-parties">
            <div class="history-card-party"><span class="data-label"><x-ui.icon name="shop" /> Mittente</span><strong>{{ $order->displayName() }}</strong></div>
            <div class="history-card-party"><span class="data-label"><x-ui.icon name="person" /> Destinatario</span><span class="register-note"><x-orders.recipient-indicator :name="$order->recipient_name" :risk="$recipientRisk" /></span></div>
        </div>
        <div class="history-order-route history-card-route">
            <div class="history-card-stop"><span class="history-card-pin is-pickup" aria-hidden="true"><x-ui.icon name="box-arrow-up" /></span><div><span class="data-label">Ritiro</span><strong>{{ $order->pickup_address }} {{ $order->pickup_street_number }}</strong><span>{{ $order->pickup_postal_code }} {{ $order->pickup_city }}</span></div></div>
            <div class="history-card-stop"><span class="history-card-pin is-delivery" aria-hidden="true"><x-ui.icon name="geo-alt-fill" /></span><div><span class="data-label">Consegna</span><strong>{{ $order->delivery_address }} {{ $order->delivery_street_number }}</strong><span>{{ $order->delivery_postal_code }} {{ $order->delivery_city }}@if($order->delivery_province) ({{ $order->delivery_province }})@endif</span></div></div>
        </div>
    </div>
    <footer class="history-card-foot">
        <ul class="history-card-chips" aria-label="Dettagli della spedizione">
            <li class="history-order-dates"><x-ui.icon name="calendar-event" /><span><span class="visually-hidden">Ritiro </span><time datetime="{{ $order->pickup_date->toDateString() }}">{{ $order->pickup_date->format('d/m/Y') }}</time> · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</span></li>
            @if($order->delivered_at)<li class="is-success"><x-ui.icon name="check2-all" />Consegnato il {{ $order->delivered_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</li>@endif
            <li><x-ui.icon name="bicycle" />Rider: {{ $order->rider?->name ?: 'In attesa di assegnazione' }}</li>
            <li><x-ui.icon name="box-seam" />{{ $order->parcel_count }} {{ $order->parcel_count === 1 ? 'collo' : 'colli' }} · {{ $order->shipping_type === 'external' ? 'Fuori regione' : 'Regionale' }}</li>
            @if($order->package_type === 'fragile')<li class="is-alert"><x-ui.icon name="exclamation-triangle" />FRAGILE</li>@endif
            @if($order->urgency === 'urgent')<li class="is-alert"><x-ui.icon name="lightning-charge" />URGENTE</li>@endif
        </ul>
        <div class="history-order-actions"><label class="history-print-choice"><input type="checkbox" data-history-label-single="{{ $order->slug }}" data-label-reference="{{ $order->reference }}" @disabled($order->parcel_count < 1)> Etichetta</label><x-ui.icon-button action="open" :href="route('orders.show', $order)" :label="'Apri la richiesta per '.$order->recipient_name" /></div>
    </footer>
</article>
@else
<article class="dispatch-row" data-order-link="{{ route('orders.show', $order) }}" role="link" tabindex="0" aria-label="Apri la richiesta per {{ $order->recipient_name }}">
    <div><span class="shipment-card-reference">{{ $order->reference }}</span><span class="data-label">Mittente</span><a class="dispatch-order-link" href="{{ route('orders.show', $order) }}"><strong>{{ $order->displayName() }}</strong></a><span class="data-label mt-2">Destinatario</span><x-orders.recipient-indicator :name="$order->recipient_name" :risk="$recipientRisk" /></div>
    <div class="dispatch-route"><x-orders.route :order="$order" /><small>Colli: {{ $order->parcel_count }} @if($order->package_type === 'fragile') · FRAGILE @endif @if($order->urgency === 'urgent') · URGENTE @endif</small></div>
    <div><span class="data-label">Data ritiro</span><strong>{{ $order->pickup_date->format('d/m/Y') }}</strong><small>Fascia oraria: {{ substr($order->pickup_from,0,5) }}–{{ substr($order->pickup_to,0,5) }}</small></div>
    <div class="dispatch-state"><span class="data-label">Stato</span><x-ui.status-badge :status="$order->status" />@if($order->receipt_voided_at)<span class="status-pill tone-danger">Incasso stornato</span>@endif<small>Rider: {{ $order->rider?->name ?: 'In attesa di assegnazione' }}</small></div>
</article>

@endif
