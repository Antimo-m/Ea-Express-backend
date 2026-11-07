@props(['order', 'incoming' => false])
@php
    $reference = $incoming ? $order->created_at : ($order->assigned_at ?? $order->created_at);
    $hours = max(0, (int) floor($reference->diffInHours(now())));
    $days = intdiv($hours, 24);
    $amount = $incoming || $hours >= 24 ? $days : $hours;
    $unit = $incoming || $hours >= 24 ? ($amount === 1 ? 'giorno' : 'giorni') : ($amount === 1 ? 'ora' : 'ore');
    $caption = $incoming ? 'Dalla creazione della richiesta' : ($order->assigned_at ? 'Dall’assegnazione' : 'Dalla creazione della richiesta');
@endphp
<time class="order-elapsed" datetime="{{ $reference->toIso8601String() }}" title="{{ $caption }} · {{ $reference->timezone('Europe/Rome')->format('d/m/Y H:i') }}" aria-label="{{ $caption }}: {{ $amount }} {{ $unit }}">{{ $amount }} {{ $unit }}</time>
