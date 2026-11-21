<section class="pickup-agenda-accounts" data-pickup-accounts data-pickup-date="{{ $date->toDateString() }}" data-pickup-page="{{ $groups->currentPage() }}" data-pickup-last-page="{{ $groups->lastPage() }}" data-live-region="pickup-day" aria-label="Account con ritiri della giornata">
    <div class="pickup-day-summary"><p role="status"><strong data-pickup-day-total>{{ $totalOrders }}</strong> {{ $totalOrders === 1 ? 'ritiro' : 'ritiri' }} nella giornata</p>@if($groups->isNotEmpty())<span>{{ $groups->firstItem() }}–{{ $groups->lastItem() }} di {{ $groups->total() }} account · {{ $groups->perPage() }} account per pagina</span>@endif</div>
    @isset($progress)
    <dl class="pickup-day-progress" aria-label="Andamento della giornata" data-pickup-progress>
        @foreach(['waiting' => 'In attesa', 'in_progress' => 'Presi in carico', 'picked_up' => 'Ritirati / in consegna', 'delivered' => 'Consegnati', 'cancelled' => 'Annullati'] as $category => $label)
        <div class="is-{{ str_replace('_', '-', $category) }}"><dt>{{ $label }}</dt><dd data-pickup-progress-{{ str_replace('_', '-', $category) }}>{{ $progress[$category] }}</dd></div>
        @endforeach
    </dl>
    @endisset
    <div class="pickup-account-groups">
    @forelse($groups as $account)
        <details class="pickup-account-group surface" data-pickup-account="{{ $account->slug }}" data-pickup-kind="{{ $account->kind }}" data-pickup-orders-url="{{ $account->orders_url }}">
            <summary class="pickup-account-heading"><div><span class="data-label">{{ $account->kind === 'customer' ? 'Account cliente' : 'Mittente senza account' }}</span><h2>{{ $account->name }}</h2></div><span class="pickup-account-count"><span><strong data-pickup-account-orders-count>{{ $account->orders_count }}</strong> {{ $account->orders_count === 1 ? 'ritiro' : 'ritiri' }}</span><small><span data-pickup-account-parcel-count>{{ $account->parcel_count }}</span> colli</small></span><x-ui.icon name="chevron-down" /></summary>
            <div class="pickup-account-body" data-pickup-orders><p class="text-secondary">Gli ordini vengono caricati soltanto quando apri questo account.</p><a class="btn btn-outline-secondary" data-pickup-load href="{{ $account->orders_url }}">Carica i ritiri</a></div>
        </details>
    @empty
        @if($totalOrders > 0)<div class="surface pickup-day-empty"><x-ui.icon name="calendar2-check" /><h2>Nessun account in questa pagina</h2><p>La lista è cambiata. Sono presenti {{ $totalOrders }} ritiri nella giornata.</p><a class="btn btn-outline-secondary mt-3" data-pickup-groups-first href="{{ route('pickups.day', ['date' => $date->toDateString(), 'per_page' => $groups->perPage()]) }}">Torna alla prima pagina</a>
        </div>
        @else<x-ui.empty-scene class="pickup-day-empty" title="Nessun ritiro in questa data" tagline="Restiamo pronti al prossimo ritiro." message="Per questa data non risultano richieste di ritiro." />@endif
    @endforelse
    </div>
    <div class="pickup-accounts-pagination">{{ $groups->links('components.ui.pagination', ['descriptive' => true]) }}</div>
</section>
