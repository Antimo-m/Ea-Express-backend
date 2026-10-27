<header class="page-heading"><div><span class="eyebrow">CONNESSI AI TUOI CLIENTI</span><h1>Messaggi</h1><p>Ogni conversazione, nel contesto della sua spedizione.</p></div><a class="btn btn-outline-secondary" href="{{ route('orders.incoming') }}"><x-ui.icon name="inbox" /> Richieste in arrivo</a></header>
<section class="inbox-workspace" data-inbox aria-label="Messaggistica clienti">
    <aside class="inbox-sidebar" aria-labelledby="inbox-heading">
        <header class="inbox-sidebar-heading"><h2 id="inbox-heading" tabindex="-1">Conversazioni</h2><span>{{ $orders->total() }}</span></header>
        <label class="directory-search"><x-ui.icon name="search" /><input type="search" data-inbox-search placeholder="Cerca in questa pagina" aria-label="Cerca conversazioni in questa pagina"></label>
        <nav class="inbox-filters" aria-label="Filtra conversazioni in questa pagina"><button type="button" data-inbox-filter="all" aria-pressed="true">Tutte</button><button type="button" data-inbox-filter="unread" aria-pressed="false">Da leggere</button></nav>
        <div class="inbox-live-list" data-live-region="conversations" data-current-conversation="{{ $order?->slug }}">
            <div class="inbox-conversations" data-inbox-list data-scroll-key="conversations">
                @if($order && !$orders->contains('slug', $order->slug))@include('messages.conversation-link', ['conversation' => $order])@endif
                @forelse($orders as $conversation)@include('messages.conversation-link', ['conversation' => $conversation])@empty<p class="inbox-empty-list">Nessuna conversazione avviata.</p>@endforelse
            </div>
            {{ $orders->links('components.ui.pagination') }}
        </div>
        <p data-inbox-empty class="inbox-empty-list" hidden role="status">Nessuna conversazione corrisponde ai filtri in questa pagina.</p>
    </aside>
    @if($order)
        @include('messages.thread', ['order' => $order, 'messages' => $messages])
    @else
        <section class="inbox-empty"><x-ui.icon name="chat-square-text" /><h2>Il prossimo contatto parte da qui</h2><p>Apri una spedizione per scrivere al cliente. Le risposte saranno raccolte in questo spazio.</p><a class="btn btn-outline-primary" href="{{ route('orders.incoming') }}">Vai agli ordini</a></section>
    @endif
</section>
