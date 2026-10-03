@props(['order', 'recipientRisk' => null])
<article class="dispatch-row">
    <div><span class="data-label">Cliente / negozio</span><a class="dispatch-order-link" href="{{ route('orders.show', $order) }}"><strong>{{ $order->displayName() }}</strong></a><small>Ordine: {{ $order->reference }}</small><span class="data-label mt-2">Nome destinatario</span><x-orders.recipient-name :name="$order->recipient_name" :risk="$recipientRisk" /></div>
    <div class="dispatch-route"><span class="data-label">Percorso: ritiro → consegna</span><strong>{{ $order->pickup_city }} → {{ $order->delivery_city }}</strong><small>Colli: {{ $order->parcel_count }} @if($order->package_type === 'fragile') · FRAGILE @endif @if($order->urgency === 'urgent') · URGENTE @endif</small></div>
    <div><span class="data-label">Data ritiro</span><strong>{{ $order->pickup_date->format('d/m/Y') }}</strong><small>Fascia oraria: {{ substr($order->pickup_from,0,5) }}–{{ substr($order->pickup_to,0,5) }}</small></div>
    <div class="dispatch-state"><span class="data-label">Stato</span><x-ui.status-badge :status="$order->status" /><small>Rider: {{ $order->rider?->name ?: 'Da assegnare' }}</small></div>
</article>
