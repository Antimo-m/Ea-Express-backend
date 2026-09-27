<x-app-layout title="Messaggi">
    <header class="page-heading"><div><span class="eyebrow">IN CONTATTO</span><h1>Messaggi</h1><p class="text-secondary">Conversazioni con i clienti, collegate alle spedizioni.</p></div><x-ui.icon-button action="add" href="{{ route('orders.incoming') }}" label="Apri una conversazione" text/></header>
    <div class="conversation-grid" data-live-region="orders">
        @forelse($orders as $order)
        <article @class(['surface conversation-card', 'has-unread' => $order->unread_count > 0])>
            <header><span class="conversation-avatar" aria-hidden="true">{{ collect(preg_split('/\s+/u', $order->displayName()))->take(2)->map(fn ($part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))->implode('') }}</span><div><h2><a href="{{ route('messages.show', $order) }}">{{ $order->displayName() }}</a></h2><span class="small text-secondary">{{ $order->sender_type === 'private' ? 'Privato' : ($order->business_type ?: 'Attività commerciale') }}</span></div><time datetime="{{ $order->latestMessage?->created_at->toIso8601String() }}">{{ $order->latestMessage?->created_at->timezone('Europe/Rome')->format('d/m H:i') }}</time></header>
            <p class="conversation-preview"><strong>{{ $order->latestMessage?->user_id ? 'EA-Express' : 'Cliente' }}:</strong> {{ $order->latestMessage?->body }}</p>
            <footer><div><small class="conversation-reference">Spedizione {{ $order->reference }}</small><span class="status-pill tone-{{ $order->status->tone() }}">{{ $order->status->label() }}</span></div><div class="conversation-actions">@if($order->unread_count)<span class="status-pill tone-orange">{{ $order->unread_count }} {{ (int) $order->unread_count === 1 ? 'nuovo' : 'nuovi' }}</span>@else<span class="small text-secondary"><x-ui.icon name="check2-all"/> Letta dal team</span>@endif<x-ui.icon-button icon="chat-dots" label="Apri conversazione" href="{{ route('messages.show', $order) }}"/></div></footer>
        </article>
        @empty<div class="surface empty-state"><h2>Nessuna conversazione</h2><p>Apri un ordine per iniziare a scrivere al cliente.</p><a class="btn btn-primary" href="{{ route('orders.incoming') }}">Vai agli ordini</a></div>@endforelse
    </div>
    {{ $orders->links() }}
</x-app-layout>
