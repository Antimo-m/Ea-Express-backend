@props(['order', 'recipientRisk' => null, 'archive' => false])
@if($archive)
<article class="history-order" data-order-link="{{ route('orders.show', $order) }}" role="link" tabindex="0" aria-label="Apri la richiesta per {{ $order->recipient_name }}">
    <div class="history-order-identity"><span class="shipment-card-reference">{{ $order->reference }}</span><span class="data-label">Nome mittente</span><strong>{{ $order->displayName() }}</strong><span class="register-note">Nome destinatario: <x-orders.recipient-indicator :name="$order->recipient_name" :risk="$recipientRisk" /></span></div>
    <div class="history-order-route"><x-orders.route :order="$order" /><small>{{ $order->parcel_count }} {{ $order->parcel_count === 1 ? 'collo' : 'colli' }} · {{ $order->shipping_type === 'external' ? 'Fuori regione' : 'Regionale' }}@if($order->package_type === 'fragile') · FRAGILE @endif @if($order->urgency === 'urgent') · URGENTE @endif</small></div>
    <div class="history-order-dates"><span class="data-label">Ritiro</span><time datetime="{{ $order->pickup_date->toDateString() }}">{{ $order->pickup_date->format('d/m/Y') }}</time><small>{{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</small>@if($order->delivered_at)<small>Consegnato il {{ $order->delivered_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</small>@endif</div>
    <div class="history-order-state"><x-ui.status-badge :status="$order->status" />@if($order->receipt_voided_at)<span class="status-pill tone-danger">Incasso stornato</span>@endif<small>Rider: {{ $order->rider?->name ?: 'In attesa di assegnazione' }}</small><strong class="money">{{ \App\Support\Money::format($order->price_cents) }}</strong></div>
    <div class="history-order-actions"><x-ui.icon-button action="open" :href="route('orders.show', $order)" :label="'Apri la richiesta per '.$order->recipient_name" /><label class="history-print-choice"><input type="radio" name="history-label-choice" value="order:{{ $order->slug }}" data-history-label-single="{{ $order->slug }}" data-label-reference="{{ $order->reference }}" @disabled($order->parcel_count < 1)> Seleziona etichetta</label></div>
</article>
@else
<article class="dispatch-row" data-order-link="{{ route('orders.show', $order) }}" role="link" tabindex="0" aria-label="Apri la richiesta per {{ $order->recipient_name }}">
    <div><span class="shipment-card-reference">{{ $order->reference }}</span><span class="data-label">Mittente</span><a class="dispatch-order-link" href="{{ route('orders.show', $order) }}"><strong>{{ $order->displayName() }}</strong></a><span class="data-label mt-2">Nome destinatario</span><x-orders.recipient-indicator :name="$order->recipient_name" :risk="$recipientRisk" /></div>
    <div class="dispatch-route"><x-orders.route :order="$order" /><small>Colli: {{ $order->parcel_count }} @if($order->package_type === 'fragile') · FRAGILE @endif @if($order->urgency === 'urgent') · URGENTE @endif</small></div>
    <div><span class="data-label">Data ritiro</span><strong>{{ $order->pickup_date->format('d/m/Y') }}</strong><small>Fascia oraria: {{ substr($order->pickup_from,0,5) }}–{{ substr($order->pickup_to,0,5) }}</small></div>
    <div class="dispatch-state"><span class="data-label">Stato</span><x-ui.status-badge :status="$order->status" />@if($order->receipt_voided_at)<span class="status-pill tone-danger">Incasso stornato</span>@endif<small>Rider: {{ $order->rider?->name ?: 'In attesa di assegnazione' }}</small></div>
</article>

@endif
