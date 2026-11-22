<section class="inbox-thread" data-live-chat="{{ $order->slug }}" data-messages-url="{{ route('messages.show', ['order' => $order, ...request()->only('page')]) }}" aria-label="Conversazione con {{ $order->displayName() }}">
    <header class="inbox-thread-heading">
        <div class="inbox-thread-nav"><a class="btn inbox-back modal-back-icon icon-button action-back" href="{{ \App\Support\SectionReturn::resolve(request(), route('messages.index', request()->only('rider', 'unread')), ['messages.index', 'notifications.index']) }}" aria-label="Torna alle conversazioni" data-tooltip="Torna alle conversazioni"><x-ui.icon name="arrow-left" /></a></div>
        <div class="inbox-thread-person"><x-ui.identity-avatar :name="$order->displayName()" /><div><span class="inbox-thread-label">Conversazione con</span><h2 data-inbox-recipient>{{ $order->displayName() }}</h2><p>{{ $order->sender_type === 'private' ? 'Privato' : ($order->business_type ?: 'Attività commerciale') }}</p></div></div>
    </header>
    <div class="inbox-shipment"><span class="inbox-shipment-ref" title="{{ $order->reference }}"><x-ui.icon name="box-seam" /><span>{{ $order->reference }}</span></span><x-ui.status-badge :status="$order->status" /><x-ui.icon-button action="open" icon="box-arrow-up-right" :href="route('orders.show', $order)" label="Apri ordine" class="inbox-open-order" /></div>
    @if($oversight ?? null)
    <section class="inbox-oversight" aria-label="Controllo della conversazione">
        <div class="inbox-oversight-rider">@if($oversight['rider'])<x-ui.identity-avatar :name="$oversight['rider']->name" /><div><span>Rider assegnato</span><strong>{{ $oversight['rider']->name }}</strong></div>@else<span class="inbox-oversight-icon" aria-hidden="true"><x-ui.icon name="person-dash" /></span><div><span>Rider assegnato</span><strong>Nessun rider</strong></div>@endif</div>
        <ul class="inbox-oversight-stats">
            <li><x-ui.icon name="bicycle" /><span><strong>{{ $oversight['rider_messages'] }}</strong> dal rider</span></li>
            <li><x-ui.icon name="person" /><span><strong>{{ $oversight['customer_messages'] }}</strong> dal cliente</span></li>
            @if($oversight['admin_messages'])<li><x-ui.icon name="shield-check" /><span><strong>{{ $oversight['admin_messages'] }}</strong> dall’admin</span></li>@endif
            @if($oversight['other_staff_messages'])<li><x-ui.icon name="people" /><span><strong>{{ $oversight['other_staff_messages'] }}</strong> da altri rider</span></li>@endif
            <li><x-ui.icon name="stopwatch" /><span>Risposta media <strong>{{ $oversight['average_reply_minutes'] === null ? '—' : ($oversight['average_reply_minutes'] < 60 ? $oversight['average_reply_minutes'].' min' : intdiv($oversight['average_reply_minutes'], 60).' h '.($oversight['average_reply_minutes'] % 60).' min') }}</strong></span></li>
            <li><x-ui.icon name="clock-history" /><span>Ultimo del rider <strong>{{ $oversight['last_rider_at']?->timezone('Europe/Rome')->format('d/m H:i') ?? '—' }}</strong></span></li>
            @if($oversight['waiting_since'])<li class="is-waiting"><x-ui.icon name="hourglass-split" /><span>Cliente in attesa da <strong>{{ $oversight['waiting_since']->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</strong></span></li>@endif
        </ul>
    </section>
    @endif
    <div class="inbox-messages"><x-orders.messages :messages="$messages" :authors="(bool) ($oversight ?? null)" :rider-id="$order->rider_id" /></div>
    @can('update', $order)
    <div class="inbox-composer-area"><form method="post" action="{{ route('messages.store', $order) }}" class="message-composer">@csrf<div class="flex-grow-1"><label for="body" class="visually-hidden">Messaggio per {{ $order->displayName() }}</label><textarea name="body" id="body" class="form-control @error('body') is-invalid @enderror" rows="2" maxlength="2000" required aria-describedby="message-help" placeholder="Scrivi a {{ $order->displayName() }}…">{{ old('body') }}</textarea>@error('body')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror</div><button class="btn btn-primary inbox-send" type="submit" aria-label="Invia a {{ $order->displayName() }}" data-tooltip="Invia"><x-ui.icon name="send" /></button></form><p id="message-help">Visibile al cliente. Per le note interne, apri il dettaglio dell’ordine.</p>
    </div>
    @else<div class="inbox-composer-area"><p>L’invio dei messaggi è gestito dal Rider assegnato.</p></div>@endcan
</section>
