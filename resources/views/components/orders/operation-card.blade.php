@props(['order', 'deadline', 'compact' => false])
<article class="operation-mini-card" data-operation-order="{{ $order->slug }}">
    <header class="operation-mini-heading"><strong>{{ $order->displayName() }}</strong><x-ui.status-badge :status="$order->status" /><x-ui.icon-button class="operation-info" icon="info" label="Dettaglio ordine" :href="route('orders.show', ['order' => $order->slug, 'return_to' => request()->getRequestUri()])" /></header>
    <p class="shipment-card-reference">{{ $order->reference }}</p>
    <p class="operation-recipient"><span class="data-label">Destinatario</span> {{ $order->recipient_name }}</p>
    @if($compact)
        <p class="operation-destination">{{ app(\App\Support\RiderOperations::class)->zone($order) }}</p>
    @endif
    <x-orders.route :order="$order" />
    <footer class="shipment-card-assignment"><span>Rider: {{ $order->rider?->name ?: 'In attesa di assegnazione' }}</span><time datetime="{{ $order->pickup_date->toDateString() }}">{{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</time></footer>
    @if($order->delivered_at)<span class="operation-deadline">Consegnata: {{ $order->delivered_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</span>@endif
    @if($deadline['label'])<span class="operation-deadline">Termine: {{ $deadline['label'] }}</span>@endif
</article>
