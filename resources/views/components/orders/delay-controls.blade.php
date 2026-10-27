@props(['order'])
@if($order->delay_reported_at || auth()->user()->can('reportDelay', $order))
<section class="surface order-delay-panel p-4" aria-label="Segnalazione di ritardo">
    <h2 class="h6">Monitoraggio ritardo</h2>
    @if($order->delay_reported_at)
        <x-orders.delay-badge :order="$order" />
        <p class="small mt-3">Segnalato da {{ $order->delayReporter?->name ?: 'Rider' }} il <time datetime="{{ $order->delay_reported_at->toIso8601String() }}">{{ $order->delay_reported_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</time>.</p>
        @can('correctDelay', $order)
        <details><summary>Correggi una segnalazione errata</summary><p class="small text-secondary mt-2">La motivazione e la segnalazione originale rimangono nella cronologia.</p><form method="post" action="{{ route('orders.delay.update', $order) }}" data-order-action>
            @csrf @method('patch')<input type="hidden" name="version" value="{{ $order->version }}">
            <label class="form-label" for="delay-correction">Motivo della correzione</label><textarea class="form-control mb-3" id="delay-correction" name="reason" required minlength="5" maxlength="2000" rows="3"></textarea>
            <button type="submit" class="btn btn-outline-secondary">Conferma correzione</button>
        </form></details>
        @endcan
    @else
        <p class="small text-secondary">Segnala un ritardo effettivo. Lo stato della spedizione rimane invariato e la segnalazione viene registrata nella cronologia.</p>
        <form method="post" action="{{ route('orders.delay.store', $order) }}" data-order-action>
            @csrf<input type="hidden" name="version" value="{{ $order->version }}">
            <button type="submit" class="btn btn-outline-danger"><x-ui.icon name="exclamation-triangle" /> Segnala ritardo</button>
        </form>
    @endif
</section>
@endif
