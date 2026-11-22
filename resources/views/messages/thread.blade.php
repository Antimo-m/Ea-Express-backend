<section class="inbox-thread" data-live-chat="{{ $order->slug }}" data-messages-url="{{ route('messages.show', ['order' => $order, ...request()->only('page')]) }}" aria-label="Conversazione con {{ $order->displayName() }}">
    <header class="inbox-thread-heading">
        <div class="inbox-thread-nav"><a class="btn inbox-back modal-back-icon icon-button action-back" href="{{ route('messages.index') }}" aria-label="Torna alle conversazioni" data-tooltip="Torna alle conversazioni"><x-ui.icon name="arrow-left" /></a></div>
        <div class="inbox-thread-person"><x-ui.identity-avatar :name="$order->displayName()" /><div><span class="inbox-thread-label">Conversazione con</span><h2 data-inbox-recipient>{{ $order->displayName() }}</h2><p>{{ $order->sender_type === 'private' ? 'Privato' : ($order->business_type ?: 'Attività commerciale') }}</p></div></div>
    </header>
    <div class="inbox-shipment"><span class="inbox-shipment-ref" title="{{ $order->reference }}"><x-ui.icon name="box-seam" /><span>{{ $order->reference }}</span></span><x-ui.status-badge :status="$order->status" /><x-ui.icon-button action="open" icon="box-arrow-up-right" :href="route('orders.show', $order)" label="Apri ordine" class="inbox-open-order" /></div>
    <div class="inbox-messages"><x-orders.messages :messages="$messages" /></div>
    @can('update', $order)
    <div class="inbox-composer-area"><form method="post" action="{{ route('messages.store', $order) }}" class="message-composer">@csrf<div class="flex-grow-1"><label for="body" class="visually-hidden">Messaggio per {{ $order->displayName() }}</label><textarea name="body" id="body" class="form-control @error('body') is-invalid @enderror" rows="2" maxlength="2000" required aria-describedby="message-help" placeholder="Scrivi a {{ $order->displayName() }}…">{{ old('body') }}</textarea>@error('body')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror</div><button class="btn btn-primary inbox-send" type="submit" aria-label="Invia a {{ $order->displayName() }}" data-tooltip="Invia"><x-ui.icon name="send" /></button></form><p id="message-help">Visibile al cliente. Per le note interne, apri il dettaglio dell’ordine.</p>
    </div>
    @else<div class="inbox-composer-area"><p>L’invio dei messaggi è gestito dal Rider assegnato.</p></div>@endcan
</section>
