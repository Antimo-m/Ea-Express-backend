{{-- Rider della consegna: quello registrato sull'evento di consegna. Senza evento attribuito non si indica chi ha consegnato. --}}
@php($deliveredBy = $order->relationLoaded('deliveryEvent') ? $order->deliveryEvent?->rider : null)
@if($deliveredBy)
    <span class="delivery-rider"><x-ui.icon name="bicycle" /> Consegnata da <b>{{ $deliveredBy->name }}</b></span>
@elseif($order->relationLoaded('rider') && $order->rider)
    <span class="delivery-rider is-uncertain" title="Lo storico non registra il Rider della consegna">Rider assegnato: {{ $order->rider->name }} · consegna non attribuita</span>
@else
    <span class="delivery-rider is-uncertain">Rider della consegna non registrato</span>
@endif
