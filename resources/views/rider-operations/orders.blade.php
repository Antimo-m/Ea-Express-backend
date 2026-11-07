@if(($chronology ?? false) || ($showPayments ?? false))
<div class="operations-order-list">
@forelse($orders as $order)
    @php($deadline = $deadlines[$order->slug])
    <article class="operations-order" data-operation-order="{{ $order->slug }}">
        <header><a href="{{ route('orders.show', ['order'=>$order->slug, ...(($chronology ?? false) ? ['return_to'=>request()->getRequestUri()] : [])]) }}"><strong>{{ $order->displayName() }}</strong><span>{{ $order->recipient_name }}</span></a><x-ui.status-badge :status="$order->status" />
</header>
        <p>Mittente: {{ $order->displayName() }} · Destinatario: {{ $order->recipient_name }}</p>
        <p>{{ $order->delivery_zone ?: $order->delivery_city }} · {{ $order->delivery_address }}, {{ $order->delivery_city }}</p>
        <x-ui.icon-button action="open" label="Apri ordine" :href="route('orders.show', ['order'=>$order->slug, ...(($chronology ?? false) ? ['return_to'=>request()->getRequestUri()] : [])])" text />
        @if(!$deadline['history_complete'])<p class="small text-warning">Termine previsto storico non ricostruibile dai dati disponibili.</p>@endif
        @if($chronology ?? false)<ol class="rider-order-events">@forelse($order->events as $event)<li><time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->timezone('Europe/Rome')->format('d/m/Y H:i:s') }}</time><strong>{{ $event->label() }}</strong>@if($event->note || $event->public_note)<span>{{ $event->note ?: $event->public_note }}</span>@endif</li>@empty<li>Nessun evento registrato.</li>@endforelse</ol>@endif
        <dl><div><dt>Scadenza prevista</dt><dd>{{ $deadline['label'] ?: 'Non disponibile' }}</dd></div><div><dt>Programmata</dt><dd>{{ $order->pickup_date->format('d/m/Y') }}</dd></div><div><dt>Rider</dt><dd>{{ $order->rider?->name ?: 'Da assegnare' }}</dd></div><div><dt>Consegna effettiva</dt><dd>{{ $order->delivered_at?->timezone('Europe/Rome')->format('d/m/Y H:i') ?: 'Non registrata' }}</dd></div><div><dt>Tariffa</dt><dd>{{ \App\Support\Money::format($order->price_cents) }}</dd></div></dl>
        @if($order->shipping_type === 'external')<p class="small text-secondary">Spedizione fuori regione · quote EA e vettore distinte nel registro.</p>@endif
        @if($showPayments ?? false)<details class="rider-payment-ledger"><summary>Incassi, rettifiche e storni registrati</summary>@forelse($order->payments as $payment)<p><time>{{ $payment->created_at->timezone('Europe/Rome')->format('d/m/Y H:i:s') }}</time><span>{{ $payment->label() }} · {{ $payment->note ?: 'Movimento registrato' }} · {{ $payment->user?->name }}</span><strong>{{ \App\Support\Money::format($payment->amount_cents) }}</strong><small>Cassa: {{ \App\Support\Money::format($payment->cash_amount_cents ?? $payment->amount_cents) }} · EA: {{ \App\Support\Money::format($payment->ea_amount_cents ?? $payment->amount_cents) }}</small></p>@empty<p class="small text-secondary">Nessun incasso registrato.</p>@endforelse<a href="{{ route('audits.index',['type'=>'orders','slug'=>$order->slug]) }}">Storico rettifiche ordine</a></details>@endif
    </article>
@empty<p class="empty-state">Nessuna spedizione per questa selezione.</p>@endforelse
<x-ui.pagination :paginator="$orders" descriptive />
</div>

@else
<div class="operation-mini-grid {{ ($compact ?? false) ? 'operation-mini-compact' : '' }}">
@forelse($orders as $order)
    <x-orders.operation-card :order="$order" :deadline="$deadlines[$order->slug]" :compact="$compact ?? false" />
@empty<p class="empty-state">Nessuna spedizione per questa selezione.</p>@endforelse
</div>
<x-ui.pagination :paginator="$orders" descriptive />
@endif
