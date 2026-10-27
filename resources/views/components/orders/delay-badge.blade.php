@props(['order'])
@if($order->delay_reported_at)
<span {{ $attributes->class(['status-pill', 'tone-danger', 'order-delay-badge']) }} title="Ritardo segnalato dal Rider il {{ $order->delay_reported_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}"><x-ui.icon name="exclamation-triangle" /> {{ $order->delayData()['label'] }}</span>
@endif
