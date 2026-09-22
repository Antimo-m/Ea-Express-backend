@props(['amount'])
<span {{ $attributes->class(['signed-money', 'cash-in' => $amount > 0, 'cash-out' => $amount < 0]) }}><span class="visually-hidden">{{ $amount > 0 ? 'Entrata: ' : ($amount < 0 ? 'Uscita: ' : 'Nessun movimento: ') }}</span>{{ $amount > 0 ? '+' : ($amount < 0 ? '−' : '') }}{{ \App\Support\Money::format(abs($amount)) }}</span>
