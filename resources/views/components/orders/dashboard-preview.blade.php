@props(['order'])
<a {{ $attributes->class(['dashboard-order-preview', 'is-urgent' => $order->urgency === 'urgent']) }} href="{{ route('orders.show', $order) }}" aria-label="Apri la richiesta di {{ $order->displayName() }} per {{ $order->recipient_name }}">
    <header class="preview-heading"><span class="shipment-card-reference">{{ $order->reference }}</span><x-ui.status-badge :status="$order->status" /></header>
    <div class="dashboard-order-parties"><div><span class="data-label">Mittente</span><strong>{{ $order->displayName() }}</strong></div><div><span class="data-label">Destinatario</span><span>{{ $order->recipient_name }}</span></div></div>
    <x-orders.route :order="$order" />
    <footer class="preview-meta"><span><x-ui.icon name="bicycle" /> Rider: {{ $order->rider?->name ?: 'In attesa di assegnazione' }}</span><time datetime="{{ $order->pickup_date->toDateString() }}"><x-ui.icon name="calendar3" /> {{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</time>@if($order->urgency === 'urgent')<span class="preview-urgent">Urgente</span>@endif</footer>
    <x-ui.icon name="arrow-up-right" class="preview-open" />
</a>
