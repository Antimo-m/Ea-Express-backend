@props(['order', 'action' => true, 'recipientRisk' => null])
<article class="surface order-card mb-3">
    <header class="card-heading order-card-head">
        <div class="min-width-0"><span class="data-label">Cliente / negozio</span><h2><a href="{{ route('orders.show', $order) }}">{{ $order->displayName() }}</a></h2><span class="order-reference">Ordine: {{ $order->reference }}</span></div>
        <div class="card-actions"><x-ui.status-badge :status="$order->status" />@if($action)<x-ui.icon-button action="print" label="Stampa etichetta" :href="route('labels.index', ['ids' => [$order->id]])" target="_blank" rel="noopener" /><x-ui.icon-button action="open" label="Apri ordine" :href="route('orders.show', $order)" />@endif</div>
    </header>
    <dl class="operation-facts">
        <div><dt>Nome destinatario / affidabilità</dt><dd><x-orders.recipient-name :name="$order->recipient_name" :risk="$recipientRisk" /></dd></div>
        <div><dt>Indirizzo ritiro</dt><dd>{{ $order->pickup_address }} {{ $order->pickup_street_number }} · {{ $order->pickup_postal_code }} {{ $order->pickup_city }}</dd></div>
        <div><dt>Indirizzo consegna</dt><dd>{{ $order->delivery_address }} {{ $order->delivery_street_number }} · {{ $order->delivery_postal_code }} {{ $order->delivery_city }}</dd></div>
        <div><dt>Data / fascia ritiro</dt><dd>{{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</dd></div>
        <div><dt>Colli / tipo pacco</dt><dd>{{ $order->parcel_count }} · {{ $order->package_type === 'fragile' ? 'Fragile' : ($order->package_type === 'other' ? $order->package_description : 'Pacco standard') }}</dd></div>
        <div><dt>Priorità</dt><dd><x-ui.status-badge :tone="$order->urgency === 'urgent' ? 'orange' : 'neutral'" :label="$order->urgency === 'urgent' ? 'Urgente' : 'Standard'" /></dd></div>
    </dl>
    @if($action)<div class="row-actions mt-3"><a class="btn btn-primary btn-sm" href="{{ route('orders.show', $order) }}#next-action">{{ $order->status === \App\OrderStatus::Received ? 'Valuta e prendi in carico' : (in_array($order->status->value, \App\OrderStatus::closed()) ? 'Vedi dettagli' : 'Prossima azione') }} <x-ui.icon name="arrow-right" /></a></div>@endif
</article>
