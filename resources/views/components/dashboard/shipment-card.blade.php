@props(['shipment'])
<article class="shipment-card"><div class="d-flex justify-content-between align-items-center gap-2 mb-3"><span class="small text-secondary">#{{ $shipment['id'] }}</span><span class="status-pill tone-blue">{{ $shipment['status'] }}</span></div><h3 class="h6 mb-2">{{ $shipment['shop'] }}</h3><p class="small text-secondary mb-3"><x-ui.icon name="geo-alt" class="me-1" />{{ $shipment['destination'] }}</p>
    <ol class="shipment-steps" aria-label="Avanzamento dimostrativo della spedizione">
        @foreach (['Ritirato', 'In transito', 'In consegna', 'Consegnato'] as $step)
            <li @class(['complete' => $loop->index < $shipment['step'], 'current' => $loop->index === $shipment['step']]) @if($loop->index === $shipment['step']) aria-current="step" @endif><span class="step-dot">@if($loop->index < $shipment['step'])<x-ui.icon name="check" />@endif</span><span>{{ $step }}</span></li>
        @endforeach
    </ol>
    <p class="shipment-estimate"><x-ui.icon name="clock" />{{ $shipment['estimate'] }}</p><p class="small text-secondary mb-0">{{ $shipment['updated'] }}</p>
</article>
