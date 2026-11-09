<x-app-layout title="Giornata Rider">
    <div class="rider-day" data-rider-day>
        <header class="rider-day-heading">
            <div><span class="eyebrow">GIORNATA OPERATIVA / RIDER</span><h1>{{ $rider->name }}</h1><p data-rider-day-caption>{{ $period->start->format('d/m/Y') }}@unless($period->start->isSameDay($period->end)) – {{ $period->end->format('d/m/Y') }}@endunless @if($zone) · {{ $zone }}@endif</p></div>
            <x-ui.icon-button action="back" label="Torna ai Rider" :href="route('users.index')" text />
        </header>
        <div class="rider-day-controls">
            <form method="get" action="{{ route('rider-operations.show', $rider) }}" class="rider-day-range" aria-label="Seleziona periodo Rider" data-rider-day-period>
                <input type="hidden" name="period" value="custom">
                <input type="hidden" name="state" value="{{ $state }}">
                <label>Data iniziale<input type="date" name="from" value="{{ $period->start->toDateString() }}" required></label>
                <label>Data finale<input type="date" name="to" value="{{ $period->end->toDateString() }}" min="{{ $period->start->toDateString() }}" required></label>
                @if($zone)<input type="hidden" name="zone" value="{{ $zone }}">@endif
                <div class="rider-range-actions"><x-ui.icon-button action="reset" :href="route('rider-operations.show', $rider)" label="Ripristina filtri" data-rider-day-reset /><x-ui.icon-button action="search" type="submit" label="Mostra periodo" /></div>
            </form>
            <div class="rider-day-context"><p>Ordini della giornata programmata · stato attuale</p><a href="#rider-accounting-heading">Saldo contabile <strong>{{ \App\Support\Money::format($accounting['balance']) }}</strong><x-ui.icon name="arrow-down" /></a></div>
        </div>
        <p class="small text-danger" role="alert" data-rider-day-error hidden></p>
        <div data-rider-day-results>
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
                    <nav class="rider-day-tabs" aria-label="Filtra consegne per stato">
                        @foreach(['all' => ['Tutte', $counts['total']], 'todo' => ['Da effettuare', $counts['todo']], 'active' => ['In corso', $counts['active']], 'completed' => ['Completate', $counts['completed']], 'issues' => ['Da verificare', $counts['issues']]] as $key => [$label, $count])
                            <a href="{{ route('rider-operations.show', ['rider' => $rider->slug, 'period' => 'custom', 'from' => $period->start->toDateString(), 'to' => $period->end->toDateString(), 'zone' => $zone, 'state' => $key]) }}" @if($state === $key) aria-current="page" @endif>{{ $label }}<span>{{ $count }}</span></a>
                        @endforeach
                        @if($counts['closed'])<a href="{{ route('rider-operations.show', ['rider' => $rider->slug, 'period' => 'custom', 'from' => $period->start->toDateString(), 'to' => $period->end->toDateString(), 'zone' => $zone, 'state' => 'closed']) }}" @if($state === 'closed') aria-current="page" @endif>Altre chiuse<span>{{ $counts['closed'] }}</span></a>@endif
                    </nav>
                    <div class="rider-day-deliveries">
                        @forelse($orders as $order)
                            <a class="rider-delivery-card rider-delivery-card--{{ $order->status->tone() }}" href="{{ route('orders.show', ['order' => $order->slug, 'return_to' => request()->getRequestUri()]) }}" aria-label="Apri ordine di {{ $order->displayName() }} per {{ $order->recipient_name }}" data-rider-delivery="{{ $order->slug }}">
                                <div class="rider-delivery-card-title"><h3>{{ $order->displayName() }}</h3><div><x-ui.status-badge :status="$order->status" /></div><x-ui.icon name="arrow-up-right" class="rider-delivery-open" /></div>
                                <span class="shipment-card-reference">{{ $order->reference }}</span>
                                <span class="shipment-card-party">Destinatario: {{ $order->recipient_name }}</span>
                                <x-orders.route :order="$order" />
                                <span class="shipment-card-assignment"><span>Rider: {{ $rider->name }}</span><time datetime="{{ $order->pickup_date->toDateString() }}">{{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</time></span>
                            </a>
                        @empty
                            <div class="rider-day-empty"><x-ui.icon name="box-seam" /><h3>Nessuna spedizione per questa selezione.</h3><p>Scegli un altro stato o una giornata diversa.</p></div>
                        @endforelse
                    </div>
                    <x-ui.pagination :paginator="$orders" descriptive />
                    <p class="rider-day-footnote">La riprogrammazione del ritiro sposta l'ordine alla nuova giornata. Una consegna conclusa successivamente resta nella giornata programmata. Lo storico è disponibile nel dettaglio ordine.</p>
                </section>
                <aside class="rider-day-accounting" aria-labelledby="rider-accounting-heading">
                    <header><span class="eyebrow">{{ $period->start->format('d/m/Y') }} – {{ $period->end->format('d/m/Y') }} / SOLO ADMIN</span><h2 id="rider-accounting-heading" tabindex="-1">Contabilità del periodo</h2><p>Movimenti registrati nel periodo selezionato, associati alle consegne del Rider.</p></header>
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
                    <a class="rider-day-balance-link" href="{{ route('balance.index', ['from' => $period->start->toDateString(), 'to' => $period->end->toDateString()]) }}">Apri il bilancio generale <x-ui.icon name="arrow-up-right" /></a>
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
