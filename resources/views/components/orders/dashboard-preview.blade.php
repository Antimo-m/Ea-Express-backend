@props(['order'])
<a {{ $attributes->class(['dashboard-order-preview']) }} href="{{ route('orders.show', $order) }}" aria-label="Apri la richiesta di {{ $order->displayName() }} per {{ $order->recipient_name }}">
    <div class="dashboard-order-parties"><span class="data-label">Cliente</span><strong>{{ $order->displayName() }}</strong><span class="data-label">Nome destinatario</span><span>{{ $order->recipient_name }}</span></div>
    <div class="dashboard-order-route"><span class="data-label">Percorso e ritiro</span><div class="order-route-points"><strong>{{ $order->pickup_city }}</strong><x-ui.icon name="arrow-right" /><strong>{{ $order->delivery_city }}</strong></div><time datetime="{{ $order->pickup_date->toDateString() }}">{{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</time></div>
</a>
