@props(['order', 'incoming' => false, 'recipientRisk' => null])
@php
    $stages = ['received'=>0,'accepted'=>1,'pickup_scheduled'=>1,'rider_arriving'=>1,'picked_up'=>2,'in_transit'=>2,'out_for_delivery'=>3,'delivered'=>4];
    $stage = $stages[$order->status->value] ?? null;
    $previewId = 'order-preview-'.$order->slug;
@endphp
<article @class(['operational-order', 'operational-order--revised', 'operational-order--incoming' => $incoming, 'operational-order--progress' => !$incoming, 'operational-priority-urgent' => !$incoming && $order->urgency === 'urgent', 'operational-priority-standard' => !$incoming && $order->urgency !== 'urgent']) data-operational-order="{{ $order->slug }}" data-order-state="{{ $order->status->value }}">
    <div class="operational-order-main" data-order-link="{{ route('orders.show',$order) }}" role="link" tabindex="0" aria-label="Apri la richiesta di {{ $order->displayName() }} per {{ $order->recipient_name }}">
        <header class="operational-card-heading"><x-orders.age :order="$order" :incoming="$incoming" /><x-ui.status-badge :status="$order->status" /></header>
        <div class="operational-identity">
            <span class="data-label">Cliente</span><a class="operational-name" href="{{ route('orders.show',$order) }}">{{ $order->displayName() }}</a>
            <span class="data-label">Nome destinatario</span><x-orders.recipient-indicator :name="$order->recipient_name" :risk="$recipientRisk" />
        </div>
        <div class="operational-route"><span class="data-label">Percorso · ritiro → consegna</span><div class="order-route-points"><strong>{{ $order->pickup_city }}</strong><x-ui.icon name="arrow-right" /><strong>{{ $order->delivery_city }}</strong></div><span class="order-priority-label">{{ $order->urgency === 'urgent' ? 'Urgente' : 'Standard' }} · {{ $order->parcel_count }} {{ $order->parcel_count === 1 ? 'collo' : 'colli' }}</span></div>
        <div class="operational-time"><span class="data-label">{{ $incoming ? 'Ritiro previsto' : 'Rider e fascia ritiro' }}</span>@unless($incoming)<strong>{{ $order->rider?->name ?: 'Da assegnare' }}</strong>@endunless<time datetime="{{ $order->pickup_date->toDateString() }}">{{ $order->pickup_date->format('d/m') }} · {{ substr($order->pickup_from,0,5) }}–{{ substr($order->pickup_to,0,5) }}</time><small>{{ $order->shipping_type === 'external' ? 'Fuori regione' : 'Regionale' }}</small>@unless($incoming)<ol class="order-progress" aria-label="Avanzamento: {{ $order->status->label() }}">@foreach(['Richiesto','Assegnato','Ritirato','Consegna','Consegnato'] as $index=>$label)<li @class(['is-complete'=>$stage !== null && $index <= $stage]) title="{{ $label }}"><span class="visually-hidden">{{ $label }}</span></li>@endforeach</ol>@endunless</div>
        <div class="operational-price"><span class="data-label">Tariffa</span><strong>{{ $order->price_cents === null ? ($order->quoted_price_cents === null ? 'Da concordare' : \App\Support\Money::format($order->quoted_price_cents)) : \App\Support\Money::format($order->price_cents) }}</strong></div>
        <div class="operational-actions">
            <button type="button" class="btn btn-outline-secondary order-preview-button" data-order-preview-toggle aria-controls="{{ $previewId }}" aria-expanded="false">Mostra più dettagli <x-ui.icon name="chevron-down" /></button>
            <x-ui.icon-button action="message" :href="route('messages.show',$order)" label="Apri messaggi del cliente" />
            @if($incoming)<a class="operational-claim" href="{{ route('orders.show',$order) }}#next-action">Prendi in carico <x-ui.icon name="arrow-up-right" /></a>@endif
        </div>
    </div>
    <section class="operational-preview" id="{{ $previewId }}" hidden aria-label="Dettaglio rapido per {{ $order->recipient_name }}">
        <div class="order-route-points operational-preview-route"><strong>{{ $order->pickup_city }}</strong><x-ui.icon name="arrow-right" /><strong>{{ $order->delivery_city }}</strong></div>
        <div class="operational-preview-addresses"><div><span class="data-label">Nome mittente</span><strong>{{ $order->displayName() }}</strong><p>{{ $order->pickup_address }} {{ $order->pickup_street_number }}<br>{{ $order->pickup_city }}</p></div><div><span class="data-label">Nome destinatario</span><x-orders.recipient-indicator :name="$order->recipient_name" :risk="$recipientRisk" /><p>{{ $order->delivery_address }} {{ $order->delivery_street_number }}<br>{{ $order->delivery_city }}</p></div></div>
        <div class="operational-preview-history"><span class="eyebrow">ULTIMI PASSAGGI</span><ol>@forelse($order->events as $event)<li><time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone('Europe/Rome')->format('d/m H:i') }}</time><span>{{ $event->label() }}</span></li>@empty<li>Richiesta ricevuta · {{ $order->created_at->timezone('Europe/Rome')->format('d/m H:i') }}</li>@endforelse</ol></div>
        <footer><a href="{{ route('orders.show',$order) }}">Apri ordine completo →</a><a href="{{ route('orders.show',$order) }}#next-action">{{ $incoming ? 'Assegna Rider e conferma' : 'Gestisci operazione e tracking' }} →</a></footer>
    </section>
</article>
