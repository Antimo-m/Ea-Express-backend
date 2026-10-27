@props(['order', 'deadline', 'compact' => false])
<article class="operation-mini-card" data-operation-order="{{ $order->slug }}">
    <header class="operation-mini-heading"><strong>{{ $order->displayName() }}</strong><x-ui.status-badge :status="$order->status" /><x-ui.icon-button class="operation-info" icon="info" label="Dettaglio ordine" :href="route('orders.show', ['order' => $order->slug, 'return_to' => request()->getRequestUri()])" /></header>
    @if($compact)
        <p class="operation-destination">{{ app(\App\Support\RiderOperations::class)->zone($order) }}</p>
        <p class="operation-recipient"><span class="data-label">Destinatario</span> {{ $order->recipient_name }}</p>
    @else
        <div class="operation-parties">
            <div><span class="data-label">Mittente · Ritiro</span><strong>{{ $order->displayName() }}</strong><span>{{ $order->pickup_address }} {{ $order->pickup_street_number }}, {{ $order->pickup_city }}</span></div>
            <div><span class="data-label">Destinatario · Consegna</span><strong>{{ $order->recipient_name }}</strong><span>{{ $order->delivery_address }} {{ $order->delivery_street_number }}, {{ $order->delivery_city }}</span></div>
        </div>
        <footer><span>{{ $order->rider?->name ?: 'Rider da assegnare' }}</span><span>{{ $order->pickup_date->format('d/m/Y') }}</span></footer>
    @endif
    @if($order->delivered_at)<span class="operation-deadline">Consegnata: {{ $order->delivered_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</span>@endif
    @if($deadline['label'])<span class="operation-deadline">Termine: {{ $deadline['label'] }}</span>@endif
    @if($deadline['reported_at'])<span class="operation-late" title="Segnalato dal Rider il {{ $deadline['reported_label'] }}">{{ $deadline['overdue'] ? 'In ritardo' : 'Ritardo segnalato dal Rider' }}</span>@endif
</article>
