<x-app-layout title="Centro operativo">
    <header class="dashboard-heading">
        <div><span class="eyebrow">EA-Express · OPERATIVITÀ</span><h1>Centro operativo<span class="brand-accent"> /</span></h1><p>{{ $greeting }}, {{ auth()->user()->name }}. Richieste, ritiri e consegne della tua attività.</p></div>
        <div class="row-actions"><div class="date-chip"><x-ui.icon name="calendar3" />{{ $today }}</div><x-ui.icon-button action="add" href="{{ route('orders.create') }}" label="Nuova richiesta" text /></div>
    </header>
    <section class="metrics-grid" data-live-region="metrics" aria-label="Riepilogo della giornata">@foreach($metrics as $metric)<x-dashboard.metric :metric="$metric" />@endforeach</section>
    <div class="control-desk">
        <aside class="control-context" aria-label="Strumenti operativi">
            <section class="control-panel"><span class="eyebrow">01 / Coordinamento</span><h2>Ritiri e assegnazioni</h2><p>Programmazione, zone e carico di lavoro dei Rider.</p><nav class="control-links" aria-label="Coordinamento operativo"><a href="{{ route('pickups.index') }}">Pianifica ritiri <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('rider-operations.index') }}">Rider per zona <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('orders.in-progress', ['status' => 'delivery_issue']) }}">Problemi di consegna <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('messages.index') }}">Messaggi <x-ui.icon name="arrow-up-right" /></a></nav></section>
            <section class="control-panel"><span class="eyebrow">02 / Attività</span><h2>Clienti e contabilità</h2><nav class="control-links" aria-label="Clienti e lettura economica"><a href="{{ route('customers.index') }}">Clienti <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('balance.index') }}">Bilancio e incassi <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('pending.index') }}">Sospesi <x-ui.icon name="arrow-up-right" /></a><a href="{{ route('reports.index') }}">Resoconti <x-ui.icon name="arrow-up-right" /></a></nav><p>Le tariffe in corso sono distinte dagli incassi registrati.</p></section>
        </aside>
        <div class="control-registers">
            @foreach([['region' => 'incoming', 'title' => 'Da prendere in carico', 'caption' => 'Ultime richieste ricevute', 'orders' => $requests, 'route' => 'orders.incoming', 'link' => 'Tutte le richieste'], ['region' => 'shipments', 'title' => 'Prossime consegne', 'caption' => 'Spedizioni in corso ordinate per ritiro', 'orders' => $shipments, 'route' => 'orders.in-progress', 'link' => 'Apri le spedizioni']] as $register)
                <section class="control-register" data-live-region="{{ $register['region'] }}" aria-labelledby="{{ $register['region'] }}-title">
                    <header class="control-register-heading"><div><span class="eyebrow">{{ $register['caption'] }}</span><h2 id="{{ $register['region'] }}-title">{{ $register['title'] }}</h2></div><a class="btn btn-outline-secondary" href="{{ route($register['route']) }}">{{ $register['link'] }} <x-ui.icon name="arrow-up-right" /></a></header>
                    @if($register['orders']->isNotEmpty())
                        <div class="control-table-region" role="region" tabindex="0" aria-label="{{ $register['title'] }}: tabella scorribile">
                            <table class="control-table" data-table-static>
                                <caption>Anteprima di {{ $register['orders']->count() }} ordini. Apri l’elenco completo per filtri e altre operazioni.</caption>
                                <thead><tr><th scope="col">Ordine / cliente</th><th scope="col">Percorso / ritiro</th><th scope="col">Stato / Rider</th><th scope="col">Azione</th></tr></thead>
                                <tbody>@foreach($register['orders'] as $order)<tr>
                                    <td><span class="control-reference">{{ $order->reference }}</span><a href="{{ route('orders.show', $order) }}"><strong>{{ $order->displayName() }}</strong></a><x-orders.recipient-name :name="$order->recipient_name" :risk="$recipientRisks[$order->id] ?? ['count' => 0, 'last_at' => null]" /></td>
                                    <td><strong>{{ $order->pickup_city }} → {{ $order->delivery_city }}</strong><small>{{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</small></td>
                                    <td><x-ui.status-badge :status="$order->status" /><x-orders.delay-badge :order="$order" /><small>Rider: {{ $order->rider?->name ?: 'Da assegnare' }}</small></td>
                                    <td><a class="btn btn-outline-secondary" href="{{ route('orders.show', $order) }}" aria-label="Apri ordine {{ $order->reference }}">Apri <x-ui.icon name="arrow-up-right" /></a></td>
                                </tr>@endforeach</tbody>
                            </table>
                        </div>
                    @else
                        <p class="control-register-empty">{{ $register['region'] === 'incoming' ? 'Nessuna richiesta in attesa.' : 'Nessuna spedizione in corso.' }}</p>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
</x-app-layout>
