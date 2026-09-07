<nav class="mobile-nav d-lg-none" aria-label="Navigazione rapida">
    @foreach ([['label' => 'Home', 'route' => 'dashboard', 'icon' => 'grid-1x2'], ['label' => 'Ordini', 'route' => 'orders.incoming', 'icon' => 'inbox'], ['label' => 'Spedizioni', 'route' => 'orders.in-progress', 'icon' => 'truck'], ['label' => 'Messaggi', 'route' => 'messages.index', 'icon' => 'chat-dots']] as $item)
        <x-navigation.link :item="$item" compact />
    @endforeach
    <button type="button" class="nav-item-link compact" data-bs-toggle="offcanvas" data-bs-target="#app-navigation" aria-controls="app-navigation" aria-label="Apri tutte le sezioni"><x-ui.icon name="list" /><span>Altro</span></button>
</nav>
