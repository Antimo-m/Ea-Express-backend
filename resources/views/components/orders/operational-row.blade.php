@props(['order', 'incoming' => false, 'recipientRisk' => null])
@php
    $stages = ['received'=>0,'accepted'=>1,'pickup_scheduled'=>1,'rider_arriving'=>1,'picked_up'=>2,'in_transit'=>2,'out_for_delivery'=>3,'delivered'=>4];
    $stage = $stages[$order->status->value] ?? null;
    $issue = in_array($order->status, [\App\OrderStatus::DeliveryIssue, \App\OrderStatus::DeliveryAttempted, \App\OrderStatus::Rescheduled], true);
    $tone = $issue ? 'danger' : ($incoming ? 'blue' : $order->status->tone());
    $previewId = 'order-preview-'.$order->slug;
@endphp
<article class="operational-order {{ $incoming ? 'operational-order--incoming' : 'operational-order--progress' }} operational-tone-{{ $tone }}" data-operational-order="{{ $order->slug }}" data-order-state="{{ $order->status->value }}">
    <div class="operational-order-main" data-order-link="{{ route('orders.show',$order) }}" role="link" tabindex="0" aria-label="Apri ordine {{ $order->reference }}">
        <div class="operational-identity">
            <div class="operational-meta"><span>{{ $order->reference }}</span>@if($incoming)<time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->locale('it')->diffForHumans() }}</time>@else<span>{{ $order->parcel_count }} {{ $order->parcel_count === 1 ? 'collo' : 'colli' }}</span>@endif</div>
            <a class="operational-name" href="{{ route('orders.show',$order) }}">{{ $order->displayName() }}</a>
            <span class="operational-recipient">{{ $order->recipient_name }}</span>
            @if(($recipientRisk['count'] ?? 0) > 0)<span class="operational-warning"><x-ui.icon name="exclamation-triangle" /> Destinatario non affidabile</span>@endif
        </div>
        <div class="operational-route"><span class="data-label">Percorso</span><span>{{ $order->pickup_city }}</span><strong><x-ui.icon name="arrow-right" /> {{ $order->delivery_city }}</strong>@if($order->urgency === 'urgent')<span class="operational-warning">● Urgente</span>@endif</div>
        <div class="operational-time"><span class="data-label">{{ $incoming ? 'Ritiro previsto' : 'Rider e fascia ritiro' }}</span>@unless($incoming)<strong>{{ $order->rider?->name ?: 'Da assegnare' }}</strong>@endunless<time datetime="{{ $order->pickup_date->toDateString() }}">{{ $order->pickup_date->format('d/m') }} · {{ substr($order->pickup_from,0,5) }}–{{ substr($order->pickup_to,0,5) }}</time>@if($incoming)<small>{{ $order->shipping_type === 'external' ? 'Fuori regione' : 'Regionale' }} · {{ $order->parcel_count }} colli</small>@endif</div>
        <div class="operational-state"><x-ui.status-badge :status="$order->status" />@unless($incoming)<ol class="order-progress" aria-label="Avanzamento: {{ $order->status->label() }}">@foreach(['Richiesto','Assegnato','Ritirato','Consegna','Consegnato'] as $index=>$label)<li @class(['is-complete'=>$stage !== null && $index <= $stage]) title="{{ $label }}"><span class="visually-hidden">{{ $label }}</span></li>@endforeach</ol><small>{{ $order->events->first()?->created_at->timezone('Europe/Rome')->format('d/m H:i') ?? $order->updated_at->timezone('Europe/Rome')->format('d/m H:i') }}</small>@endunless</div>
        <div class="operational-price"><span class="data-label">Tariffa</span><strong>{{ $order->price_cents === null ? ($order->quoted_price_cents === null ? 'Da concordare' : \App\Support\Money::format($order->quoted_price_cents)) : \App\Support\Money::format($order->price_cents) }}</strong></div>
        <div class="operational-actions">
            <x-ui.icon-button icon="chevron-down" label="Dettaglio rapido ordine {{ $order->reference }}" data-order-preview-toggle :aria-controls="$previewId" aria-expanded="false" />
            @if($incoming)<a class="operational-claim" href="{{ route('orders.show',$order) }}#next-action">Prendi in carico <x-ui.icon name="arrow-up-right" /></a>@else<x-ui.icon-button action="message" :href="route('messages.show',$order)" label="Apri messaggi del cliente" />@endif
        </div>
    </div>
    <section class="operational-preview" id="{{ $previewId }}" hidden aria-label="Dettaglio rapido {{ $order->reference }}">
        <div class="operational-preview-addresses"><div><span class="eyebrow">01 · RITIRO</span><strong>{{ $order->displayName() }}</strong><p>{{ $order->pickup_address }} {{ $order->pickup_street_number }}<br>{{ $order->pickup_city }}</p></div><div><span class="eyebrow">02 · CONSEGNA</span><x-orders.recipient-name :name="$order->recipient_name" :risk="$recipientRisk" tag="strong" /><p>{{ $order->delivery_address }} {{ $order->delivery_street_number }}<br>{{ $order->delivery_city }}</p></div></div>
        <div class="operational-preview-history"><span class="eyebrow">ULTIMI PASSAGGI</span><ol>@forelse($order->events as $event)<li><time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone('Europe/Rome')->format('d/m H:i') }}</time><span>{{ $event->status->label() }}</span></li>@empty<li>Richiesta ricevuta · {{ $order->created_at->timezone('Europe/Rome')->format('d/m H:i') }}</li>@endforelse</ol></div>
        <footer><a href="{{ route('orders.show',$order) }}">Apri ordine completo →</a><a href="{{ route('orders.show',$order) }}#next-action">{{ $incoming ? 'Assegna Rider e conferma' : 'Gestisci operazione e tracking' }} →</a><x-ui.icon-button action="message" :href="route('messages.show',$order)" label="Apri messaggi del cliente" /></footer>
    </section>
</article>
