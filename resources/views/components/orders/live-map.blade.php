@props(['order' => null, 'fleet' => false])
<section class="tracking-panel" data-tracking-panel data-tracking-url="{{ $fleet ? route('tracking.positions') : route('tracking.location', $order) }}" @if($order) data-tracking-order="{{ $order->slug }}" @endif>
    <header class="tracking-heading"><div><span class="eyebrow">{{ $fleet ? 'MAPPA OPERATIVA' : 'LA TUA SPEDIZIONE' }}</span><h2>{{ $fleet ? 'Rider in strada' : 'Segui la consegna' }}</h2></div><span class="tracking-state"><span class="live-dot"></span><span data-tracking-label>Connessione al tracking…</span></span></header>
    <div class="tracking-placeholder" data-tracking-placeholder><x-ui.icon name="geo-alt" /><strong data-tracking-message>Caricamento tracking…</strong><p>Posizioni condivise dai Rider durante le consegne attive.</p></div>
    <div class="tracking-map" data-tracking-map hidden role="region" aria-label="Mappa delle posizioni della consegna"></div>
    <p class="tracking-error" data-tracking-error role="status" hidden></p>
    <footer class="tracking-footer"><strong data-tracking-rider>{{ $fleet ? 'Rider con GPS attivo' : ($order->rider?->name ?: 'Rider da assegnare') }}</strong><span data-tracking-updated>In attesa della posizione</span></footer>
    @if($fleet)<div class="rider-roster" data-rider-roster></div>@else
    <div class="tracking-journey"><div><strong>01 · Ritiro</strong><p>{{ $order->pickup_address }} {{ $order->pickup_street_number }}, {{ $order->pickup_city }}</p><small data-pickup-point></small></div><div><strong>02 · Consegna</strong><p>{{ $order->delivery_address }} {{ $order->delivery_street_number }}, {{ $order->delivery_city }}</p><small data-delivery-point></small></div></div>
    @if(auth()->user()->canOperateDeliveries() && $order->rider_id === auth()->user()->id)
    <div class="gps-notice"><button class="btn btn-primary" type="button" data-gps-start="{{ route('tracking.start', $order) }}" @disabled(!app(\App\Support\RiderTracking::class)->eligible($order))><x-ui.icon name="broadcast" /> Condividi posizione per questa consegna</button><p>Solo questo ordine vedrà la tua posizione. Puoi interrompere in qualsiasi momento. Il GPS si attiva dopo “Parti per il ritiro”; mantieni la pagina visibile e il dispositivo acceso.</p></div>
    @endif
    @endif
</section>
