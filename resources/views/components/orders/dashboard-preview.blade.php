@props(['order'])
<a {{ $attributes->class(['dashboard-order-preview']) }} href="{{ route('orders.show', $order) }}" aria-label="Apri la richiesta di {{ $order->displayName() }} per {{ $order->recipient_name }}">
    <div class="shipment-card-reference"><span>{{ $order->reference }}</span><x-ui.status-badge :status="$order->status" /></div>
    <div class="dashboard-order-parties"><span class="data-label">Mittente</span><strong>{{ $order->displayName() }}</strong><span class="data-label">Destinatario</span><span>{{ $order->recipient_name }}</span><small>Rider: {{ $order->rider?->name ?: 'In attesa di assegnazione' }}</small><time datetime="{{ $order->pickup_date->toDateString() }}">{{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</time></div>
    <x-orders.route :order="$order" />
</a>
