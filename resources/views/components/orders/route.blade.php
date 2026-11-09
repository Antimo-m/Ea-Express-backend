@props(['order'])
<div {{ $attributes->class(['shipment-route']) }}>
    <div class="shipment-route-stop">
        <span class="data-label">Ritiro</span>
        <strong>{{ $order->pickup_address }} {{ $order->pickup_street_number }}</strong>
        <span>{{ $order->pickup_postal_code }} {{ $order->pickup_city }}</span>
    </div>
    <x-ui.icon name="arrow-down" class="shipment-route-arrow" />
    <div class="shipment-route-stop">
        <span class="data-label">Consegna</span>
        <strong>{{ $order->delivery_address }} {{ $order->delivery_street_number }}</strong>
        <span>{{ $order->delivery_postal_code }} {{ $order->delivery_city }}@if($order->delivery_province) ({{ $order->delivery_province }})@endif</span>
    </div>
</div>
