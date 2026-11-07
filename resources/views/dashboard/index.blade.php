<x-app-layout title="Centro operativo">
    <header class="dashboard-heading">
        <div><span class="eyebrow">EA-Express · OPERATIVITÀ</span><h1>Centro operativo<span class="brand-accent"> /</span></h1><p>{{ $greeting }}, {{ auth()->user()->name }}. Richieste, ritiri e consegne della tua attività.</p></div>
        <div class="row-actions"><div class="date-chip"><x-ui.icon name="calendar3" />{{ $today }}</div><x-ui.icon-button action="add" href="{{ route('orders.create') }}" label="Nuova richiesta" text /></div>
    </header>
    <section class="metrics-grid" data-live-region="metrics" aria-label="Riepilogo della giornata">@foreach($metrics as $metric)<x-dashboard.metric :metric="$metric" />@endforeach</section>
    <div class="control-desk dashboard-workspace-revision">
        <aside class="control-context" aria-label="Strumenti operativi">
            <section class="control-panel"><span class="dashboard-section-icon" aria-hidden="true"><x-ui.icon name="box-seam" /><x-ui.icon name="arrow-right" /><x-ui.icon name="bicycle" /></span><h2>Ritiri e assegnazioni</h2><p>Programmazione, zone e carico di lavoro dei Rider.</p><nav class="control-links" aria-label="Coordinamento operativo"><a href="{{ route('pickups.index') }}">Pianifica ritiri <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('rider-operations.index') }}">Rider per zona <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('orders.in-progress', ['status' => 'delivery_issue']) }}">Problemi di consegna <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('messages.index') }}">Messaggi <x-ui.icon name="arrow-up-right" /></a></nav></section>
            <section class="control-panel"><span class="dashboard-section-icon" aria-hidden="true"><x-ui.icon name="people" /><x-ui.icon name="journal-text" /></span><h2>Clienti e contabilità</h2><nav class="control-links" aria-label="Clienti e lettura economica"><a href="{{ route('customers.index') }}">Clienti <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('balance.index') }}">Bilancio e incassi <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('pending.index') }}">Sospesi <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('reports.index') }}">Resoconti <x-ui.icon name="arrow-up-right" /></a></nav><p>Le tariffe in corso sono distinte dagli incassi registrati.</p></section>
        </aside>
        <div class="control-registers">
            @foreach([['region' => 'incoming', 'title' => 'Da prendere in carico', 'caption' => 'Ultime richieste ricevute', 'orders' => $requests, 'route' => 'orders.incoming', 'link' => 'Tutte le richieste'], ['region' => 'shipments', 'title' => 'Prossime consegne', 'caption' => 'Spedizioni in corso ordinate per ritiro', 'orders' => $shipments, 'route' => 'orders.in-progress', 'link' => 'Apri le spedizioni']] as $register)
                <section class="control-register" data-live-region="{{ $register['region'] }}" aria-labelledby="{{ $register['region'] }}-title">
                    <header class="control-register-heading"><div><span class="dashboard-section-icon" aria-hidden="true"><x-ui.icon :name="$register['region'] === 'incoming' ? 'inbox' : 'truck'" /></span><span class="eyebrow">{{ $register['caption'] }}</span><h2 id="{{ $register['region'] }}-title">{{ $register['title'] }}</h2></div><a class="btn btn-outline-secondary" href="{{ route($register['route']) }}">{{ $register['link'] }} <x-ui.icon name="arrow-up-right" /></a></header>
                    @if($register['orders']->isNotEmpty())
                        <div class="dashboard-order-previews" aria-label="{{ $register['title'] }}">
                            @foreach($register['orders'] as $order)<x-orders.dashboard-preview :order="$order" />@endforeach
                        </div>
                    @else
                        <p class="control-register-empty">{{ $register['region'] === 'incoming' ? 'Nessuna richiesta in attesa.' : 'Nessuna spedizione in corso.' }}</p>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
</x-app-layout>
