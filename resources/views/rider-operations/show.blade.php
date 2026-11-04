<x-app-layout title="Giornata Rider">
    <div class="rider-day">
        <header class="rider-day-heading">
            <div><span class="eyebrow">GIORNATA OPERATIVA / RIDER</span><h1>{{ $rider->name }}</h1><p>{{ $period->start->translatedFormat('l j F Y') }}@if($zone) · {{ $zone }}@endif</p></div>
            <x-ui.icon-button action="back" label="Torna ai Rider" :href="route('rider-operations.index', ['date' => $period->start->toDateString(), 'view' => 'rider'])" text />
        </header>
        <div class="rider-day-controls">
            <form method="get" class="rider-day-date" aria-label="Seleziona giornata Rider">
                <label for="rider-day-date"><x-ui.icon name="calendar3" /> Giornata</label>
                <input id="rider-day-date" type="date" name="date" value="{{ $period->start->toDateString() }}" required>
                @if($zone)<input type="hidden" name="zone" value="{{ $zone }}">@endif
                <x-ui.icon-button action="search" type="submit" label="Mostra giornata" />
                @unless($period->start->isSameDay(now('Europe/Rome')))<a href="{{ route('rider-operations.show', ['rider' => $rider->slug, 'zone' => $zone]) }}">Oggi</a>@endunless
            </form>
            <div class="rider-day-context"><p>Ordini della giornata programmata · stato attuale</p><a href="#rider-accounting-heading">Saldo contabile <strong>{{ \App\Support\Money::format($accounting['balance']) }}</strong><x-ui.icon name="arrow-down" /></a></div>
        </div>
        <div data-live-region="rider-day">
            <section class="rider-day-pulse" aria-label="Avanzamento della giornata">
                <div class="rider-day-progress-heading"><div><span class="eyebrow">IL PUNTO DELLA GIORNATA</span><h2><strong>{{ $counts['completed'] }}</strong><span> / {{ $counts['total'] }} consegne completate</span></h2></div><span class="rider-day-percent">{{ $counts['progress'] }}<small>%</small></span></div>
                <progress max="100" value="{{ $counts['progress'] }}" aria-label="Consegne completate">{{ $counts['progress'] }}%</progress>
                <dl class="rider-day-counts">
                    <div><dt><span class="rider-day-dot todo"></span>Da effettuare</dt><dd>{{ $counts['todo'] }}</dd></div>
                    <div><dt><span class="rider-day-dot active"></span>In corso</dt><dd>{{ $counts['active'] }}</dd></div>
                    <div><dt><span class="rider-day-dot completed"></span>Completate</dt><dd>{{ $counts['completed'] }}</dd></div>
                    <div><dt><span class="rider-day-dot issues"></span>Da verificare</dt><dd>{{ $counts['issues'] }}</dd></div>
                    @if($counts['closed'])<div><dt>Chiuse senza consegna</dt><dd>{{ $counts['closed'] }}</dd></div>@endif
                </dl>
            </section>
            <div class="rider-day-layout">
                <section class="rider-day-work" aria-labelledby="rider-deliveries-heading">
                    <div class="rider-day-section-title"><h2 id="rider-deliveries-heading">Le consegne</h2><span>{{ $orders->total() }} {{ $orders->total() === 1 ? 'ordine' : 'ordini' }}</span></div>
                    @if($counts['missing_deadlines'])<p class="rider-day-footnote">{{ $counts['missing_deadlines'] }} ordini sono privi di un termine previsto. I ritardi sono identificati dalle segnalazioni del Rider.</p>@endif
                    <nav class="rider-day-tabs" aria-label="Filtra consegne per stato">
                        @foreach(['all' => ['Tutte', $counts['total']], 'todo' => ['Da effettuare', $counts['todo']], 'active' => ['In corso', $counts['active']], 'completed' => ['Completate', $counts['completed']], 'issues' => ['Da verificare', $counts['issues']]] as $key => [$label, $count])
                            <a href="{{ route('rider-operations.show', ['rider' => $rider->slug, 'date' => $period->start->toDateString(), 'zone' => $zone, 'state' => $key]) }}" @if($state === $key) aria-current="page" @endif>{{ $label }}<span>{{ $count }}</span></a>
                        @endforeach
                        @if($counts['closed'])<a href="{{ route('rider-operations.show', ['rider' => $rider->slug, 'date' => $period->start->toDateString(), 'zone' => $zone, 'state' => 'closed']) }}" @if($state === 'closed') aria-current="page" @endif>Altre chiuse<span>{{ $counts['closed'] }}</span></a>@endif
                    </nav>
                    <div class="rider-day-deliveries">
                        @forelse($orders as $order)
                            <a class="rider-delivery-card rider-delivery-card--{{ $order->status->tone() }}" href="{{ route('orders.show', ['order' => $order->slug, 'return_to' => request()->getRequestUri()]) }}" aria-label="Apri ordine di {{ $order->displayName() }} per {{ $order->recipient_name }}" data-rider-delivery="{{ $order->slug }}">
                                <div class="rider-delivery-card-title"><h3>{{ $order->displayName() }}</h3><div><x-ui.status-badge :status="$order->status" />@if($order->delay_reported_at && !in_array($order->status->value, \App\OrderStatus::closed(), true))<span class="rider-delivery-alert"><x-ui.icon name="exclamation-triangle" /> Ritardo segnalato</span>@endif</div><x-ui.icon name="arrow-up-right" class="rider-delivery-open" /></div>
                                <div class="rider-delivery-route">
                                    <div><x-ui.icon name="shop" /><div><span>Ritiro</span><strong>{{ $order->pickup_address }} {{ $order->pickup_street_number }}</strong><small>{{ $order->pickup_postal_code }} {{ $order->pickup_city }}</small></div></div>
                                    <div><x-ui.icon name="geo-alt" /><div><span>Consegna · {{ $order->recipient_name }}</span><strong>{{ $order->delivery_address }} {{ $order->delivery_street_number }}</strong><small>{{ $order->delivery_postal_code }} {{ $order->delivery_city }}@if($order->delivery_zone) · {{ $order->delivery_zone }}@endif</small></div></div>
                                </div>
                            </a>
                        @empty
                            <div class="rider-day-empty"><x-ui.icon name="box-seam" /><h3>Nessuna spedizione per questa selezione.</h3><p>Scegli un altro stato o una giornata diversa.</p></div>
                        @endforelse
                    </div>
                    <x-ui.pagination :paginator="$orders" descriptive />
                    <p class="rider-day-footnote">La riprogrammazione del ritiro sposta l'ordine alla nuova giornata. Una consegna conclusa successivamente resta nella giornata programmata. Lo storico è disponibile nel dettaglio ordine.</p>
                </section>
                <aside class="rider-day-accounting" aria-labelledby="rider-accounting-heading">
                    <header><span class="eyebrow">{{ $period->start->format('d/m/Y') }} / SOLO ADMIN</span><h2 id="rider-accounting-heading" tabindex="-1">Contabilità del giorno</h2><p>Movimenti registrati nella data selezionata, associati alle consegne del Rider.</p></header>
                    <dl class="rider-day-ledger">
                        <div><dt>Incassi registrati</dt><dd>{{ \App\Support\Money::format($accounting['gross']) }}</dd></div>
                        <div><dt>Storni contabili</dt><dd>− {{ \App\Support\Money::format($accounting['reversals']) }}</dd></div>
                        @if($accounting['restorations'])<div><dt>Ripristini</dt><dd>+ {{ \App\Support\Money::format($accounting['restorations']) }}</dd></div>@endif
                        <div><dt>Spese e uscite</dt><dd>− {{ \App\Support\Money::format($accounting['expenses']) }}</dd></div>
                        <div class="rider-day-ledger-total"><dt>Saldo contabile</dt><dd>{{ \App\Support\Money::format($accounting['balance']) }}</dd></div>
                    </dl>
                    <dl class="rider-day-ledger rider-day-cash"><div><dt><x-ui.icon name="cash-stack" /> Variazione contanti</dt><dd>{{ \App\Support\Money::format($accounting['cash_change']) }}</dd></div>@if($accounting['refunds'])<div><dt>Di cui rimborsi pagati</dt><dd>{{ \App\Support\Money::format($accounting['refunds']) }}</dd></div>@endif</dl>
                    <p class="rider-day-account-note">La variazione considera solo incassi e uscite effettivi. La disponibilità fisica richiede anche un saldo iniziale e i versamenti del Rider, non presenti nel registro.</p>
                    <div class="rider-day-pending"><h3>Ancora da regolarizzare</h3><dl class="rider-day-ledger"><div><dt>Incassi da registrare</dt><dd>{{ \App\Support\Money::format($accounting['unrecorded']) }}</dd></div><div><dt>Sospesi in entrata</dt><dd>{{ \App\Support\Money::format($accounting['pending_incoming']) }}</dd></div><div><dt>Sospesi in uscita</dt><dd>{{ \App\Support\Money::format($accounting['pending_outgoing']) }}</dd></div></dl><p>I sospesi sono residui delle posizioni aperte nella giornata; possono riferirsi agli incassi da registrare. Sono esclusi dal saldo.</p></div>
                    <a class="rider-day-balance-link" href="{{ route('balance.index', ['from' => $period->start->toDateString(), 'to' => $period->start->toDateString()]) }}">Apri il bilancio generale <x-ui.icon name="arrow-up-right" /></a>
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
