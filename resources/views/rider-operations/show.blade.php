@php
    $money = fn (?int $cents): string => \App\Support\Money::format((int) $cents);
    $plural = fn (int $count, string $one, string $many): string => $count === 1 ? $one : $many;
    $shortDate = fn ($date): string => $date->format('d/m/Y');
    $maxBucket = max(1, ...array_column($summary['buckets'], 'tariff_cents'));
    $bucketTitle = ['day' => 'giorno', 'month' => 'mese', 'year' => 'anno'][$summary['granularity']];
    $distribution = ['todo' => ['Da effettuare', $counts['todo']], 'active' => ['In corso', $counts['active']], 'completed' => ['Completate', $counts['completed']], 'issues' => ['Da verificare', $counts['issues']], 'closed' => ['Annullate o rifiutate', $counts['closed']]];
@endphp
<x-app-layout :title="$admin ? 'Pagina operativa · '.$rider->name : 'La mia pagina operativa'">
    <div class="rider-page" data-rider-day>
        @if($admin)
            <nav class="rider-page-back" aria-label="Navigazione pagina"><x-ui.icon-button action="back" label="Torna ai Rider" :href="route('users.index')" text /></nav>
        @endif
        <header class="rider-page-heading">
            <span class="eyebrow">{{ $admin ? 'PAGINA OPERATIVA RIDER' : 'LA MIA PAGINA OPERATIVA' }}</span>
            <h1>{{ $rider->name }}</h1>
            <p data-rider-day-caption>{{ $selection['label'] }}@if($zone) · {{ $zone }}@endif</p>
        </header>

        <section class="rider-period" aria-label="Periodo da consultare" data-rider-day-filters>
            <nav class="rider-period-presets" aria-label="Periodi rapidi">
                @foreach(['today' => 'Oggi', 'last7' => 'Ultimi 7 giorni', 'month' => 'Mese corrente', 'year' => 'Anno corrente'] as $mode => $label)
                    <a href="{{ $pageUrl(array_filter(['period' => $mode, 'zone' => $zone])) }}" data-rider-period-link @if($selection['mode'] === $mode) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <div class="rider-period-forms">
                <form method="get" action="{{ $pageUrl() }}" class="rider-period-form" data-rider-day-period aria-label="Scegli un mese">
                    <input type="hidden" name="period" value="month_of">@if($zone)<input type="hidden" name="zone" value="{{ $zone }}">@endif
                    <label>Mese<input type="month" name="month" value="{{ $selection['month'] }}" max="{{ now('Europe/Rome')->format('Y-m') }}" required></label>
                    <x-ui.icon-button action="search" type="submit" label="Mostra il mese" />
                </form>
                <form method="get" action="{{ $pageUrl() }}" class="rider-period-form" data-rider-day-period aria-label="Scegli un anno">
                    <input type="hidden" name="period" value="year_of">@if($zone)<input type="hidden" name="zone" value="{{ $zone }}">@endif
                    <label>Anno<select name="year" required>@foreach(range(now('Europe/Rome')->year, 2025) as $year)<option value="{{ $year }}" @selected($selection['year'] === $year)>{{ $year }}</option>@endforeach</select></label>
                    <x-ui.icon-button action="search" type="submit" label="Mostra l’anno" />
                </form>
                <form method="get" action="{{ $pageUrl() }}" class="rider-period-form rider-period-range" data-rider-day-period aria-label="Scegli un intervallo di date">
                    <input type="hidden" name="period" value="custom">@if($zone)<input type="hidden" name="zone" value="{{ $zone }}">@endif
                    <label>Data iniziale<input type="date" name="from" value="{{ $period->start->toDateString() }}" required></label>
                    <label>Data finale<input type="date" name="to" value="{{ $period->end->toDateString() }}" min="{{ $period->start->toDateString() }}" required></label>
                    <span class="rider-period-actions"><x-ui.icon-button action="reset" :href="$pageUrl(array_filter(['zone' => $zone]))" label="Torna a oggi" data-rider-day-reset /><x-ui.icon-button action="search" type="submit" label="Mostra il periodo" /></span>
                </form>
            </div>
        </section>
        <p class="small text-danger" role="alert" data-rider-day-error hidden></p>

        <div data-rider-day-results>
            <section class="rider-now" aria-labelledby="rider-now-title">
                <div class="rider-section-title"><h2 id="rider-now-title">Adesso</h2><span>Spedizioni aperte affidate {{ $admin ? 'al Rider' : 'a te' }}, indipendentemente dal periodo</span></div>
                <dl class="rider-now-grid">
                    @foreach([['waiting', 'In attesa del ritiro', 'hourglass-split'], ['scheduled', 'Ritiri programmati', 'calendar-event'], ['picked', 'Ritirate', 'box-seam'], ['travelling', 'In viaggio', 'truck'], ['issues', 'Da verificare', 'exclamation-triangle'], ['late', 'In ritardo', 'alarm']] as [$key, $label, $icon])
                        <div @class(['rider-now-card', 'rider-now-card--'.$key, 'is-zero' => ! $current[$key]])><dt><x-ui.icon :name="$icon" />{{ $label }}</dt><dd>{{ $current[$key] }}</dd></div>
                    @endforeach
                </dl>
            </section>

            <section class="rider-period-summary" aria-labelledby="rider-period-title">
                <div class="rider-section-title"><h2 id="rider-period-title">Nel periodo</h2><span>Ritiri, consegne e annullamenti contati alla data in cui sono avvenuti</span></div>
                <dl class="rider-kpis">
                    <div><dt>Spedizioni assegnate</dt><dd>{{ $summary['assigned'] }}</dd><small>con ritiro programmato nel periodo</small></div>
                    <div><dt>Ritirate</dt><dd>{{ $summary['picked_up'] }}</dd><small>pacchi presi in carico</small></div>
                    <div class="rider-kpi--delivered"><dt>Consegnate</dt><dd>{{ $summary['delivered'] }}</dd><small>consegne confermate</small></div>
                    <div><dt>Annullate</dt><dd>{{ $summary['cancelled'] }}</dd><small>mentre erano affidate {{ $admin ? 'al Rider' : 'a te' }}</small></div>
                </dl>
                <div class="rider-progress-card">
                    <div class="rider-progress-heading"><h3>Avanzamento delle spedizioni assegnate</h3><strong>@if($counts['valid']){{ $counts['progress'] }}<small>%</small>@else<small>Nessuna consegna prevista</small>@endif</strong></div>
                    <p>{{ $counts['completed'] }} di {{ $counts['valid'] }} {{ $plural($counts['valid'], 'spedizione completata', 'spedizioni completate') }} · stato attuale delle spedizioni con ritiro nel periodo</p>
                    @if($counts['total'])
                        <div class="rider-distribution" role="img" aria-label="Distribuzione degli stati: @foreach($distribution as [$label, $value]){{ $label }} {{ $value }}{{ $loop->last ? '' : ', ' }}@endforeach">
                            @foreach($distribution as $key => [$label, $value])@if($value)<span class="rider-distribution--{{ $key }}" style="--share: {{ $value }}"></span>@endif @endforeach
                        </div>
                        <ul class="rider-distribution-legend">@foreach($distribution as $key => [$label, $value])<li class="rider-distribution--{{ $key }}"><span aria-hidden="true"></span>{{ $label }} <b>{{ $value }}</b></li>@endforeach</ul>
                    @endif
                </div>
            </section>

            <section class="rider-tariffs" aria-labelledby="rider-tariffs-title">
                <div class="rider-section-title"><h2 id="rider-tariffs-title">Valore tariffario</h2><span>Tariffe di spedizione concordate: non è il valore dei pacchi né un compenso</span></div>
                <div class="rider-tariff-totals">
                    <div class="rider-tariff-main"><span>Tariffe delle spedizioni completate</span><strong>{{ $money($summary['delivered_tariff_cents']) }}</strong><small>{{ $summary['delivered'] }} {{ $plural($summary['delivered'], 'consegna', 'consegne') }} nel periodo @if($summary['missing_prices'])· {{ $summary['missing_prices'] }} senza tariffa concordata, non conteggiate @endif</small></div>
                    <div class="rider-tariff-open"><span>Valore tariffario delle spedizioni in corso</span><strong>{{ $money($current['open_tariff_cents']) }}</strong><small>{{ $current['open'] }} {{ $plural($current['open'], 'spedizione aperta', 'spedizioni aperte') }} adesso @if($current['open_missing_prices'])· {{ $current['open_missing_prices'] }} senza tariffa @endif</small></div>
                </div>
                @if(count($summary['buckets']) > 1)
                    <figure class="rider-tariff-chart">
                        <figcaption>Totale tariffario per {{ $bucketTitle }}</figcaption>
                        <ol style="--buckets: {{ count($summary['buckets']) }}">
                            @foreach($summary['buckets'] as $bucket)
                                <li title="{{ $bucket['label'] }}: {{ $money($bucket['tariff_cents']) }} · {{ $bucket['deliveries'] }} {{ $plural($bucket['deliveries'], 'consegna', 'consegne') }}"><span class="rider-tariff-bar" style="--height: {{ max(2, (int) round($bucket['tariff_cents'] / $maxBucket * 100)) }}%"></span>@if(count($summary['buckets']) <= 14)<small>{{ $bucket['label'] }}</small>@endif</li>
                            @endforeach
                        </ol>
                    </figure>
                @endif
                <details class="rider-tariff-table" @if(count($summary['buckets']) <= 7) open @endif>
                    <summary>Riepilogo tariffario per {{ $bucketTitle }}</summary>
                    <div class="table-responsive"><table class="table" data-table-native>
                        <thead><tr><th scope="col">{{ ucfirst($bucketTitle) }}</th><th scope="col">Consegne</th><th scope="col">Totale tariffario</th></tr></thead>
                        <tbody>@foreach($summary['buckets'] as $bucket)<tr @class(['is-empty' => ! $bucket['deliveries']])><th scope="row">{{ $bucket['label'] }}</th><td>{{ $bucket['deliveries'] }}</td><td>{{ $money($bucket['tariff_cents']) }}</td></tr>@endforeach</tbody>
                        <tfoot><tr><th scope="row">Totale del periodo</th><td>{{ $summary['delivered'] }}</td><td>{{ $money($summary['delivered_tariff_cents']) }}</td></tr></tfoot>
                    </table></div>
                </details>
            </section>

            <div @class(['rider-page-layout', 'rider-page-layout--admin' => $admin])>
                <section class="rider-deliveries" aria-labelledby="rider-deliveries-heading">
                    <div class="rider-section-title"><h2 id="rider-deliveries-heading">Le spedizioni del periodo</h2><span>{{ $orders->total() }} {{ $plural($orders->total(), 'spedizione', 'spedizioni') }} con ritiro programmato nel periodo</span></div>
                    <nav class="rider-day-tabs" aria-label="Filtra spedizioni per stato">
                        @foreach(['all' => ['Tutte', $counts['total']], 'todo' => ['Da effettuare', $counts['todo']], 'active' => ['In corso', $counts['active']], 'completed' => ['Completate', $counts['completed']], 'issues' => ['Da verificare', $counts['issues']]] + ($counts['closed'] ? ['closed' => ['Annullate o rifiutate', $counts['closed']]] : []) as $key => [$label, $count])
                            <a href="{{ $pageUrl([...$base, 'state' => $key]) }}" @if($state === $key) aria-current="page" @endif>{{ $label }}<span>{{ $count }}</span></a>
                        @endforeach
                    </nav>
                    <div class="rider-delivery-list">
                        @forelse($orders as $order)
                            <a class="rider-stop-card rider-stop-card--{{ $order->status->tone() }}" href="{{ route('orders.show', ['order' => $order->slug, 'return_to' => request()->getRequestUri()]) }}" aria-label="Apri ordine di {{ $order->displayName() }} per {{ $order->recipient_name }}" data-rider-delivery="{{ $order->slug }}">
                                <header class="rider-stop-heading">
                                    <div><h3>{{ $order->displayName() }}</h3><span class="rider-stop-meta"><span class="shipment-card-reference">{{ $order->reference }}</span><time datetime="{{ $order->pickup_date->toDateString() }}"><x-ui.icon name="calendar3" /> {{ $order->pickup_date->format('d/m/Y') }} · {{ substr($order->pickup_from, 0, 5) }}–{{ substr($order->pickup_to, 0, 5) }}</time></span></div>
                                    <x-ui.status-badge :status="$order->status" />
                                </header>
                                <ol class="rider-stop-route">
                                    <li class="rider-stop-point rider-stop-point--pickup"><span class="rider-stop-label">Ritiro</span><strong>{{ trim($order->pickup_address.' '.$order->pickup_street_number) }}</strong><span>{{ trim($order->pickup_postal_code.' '.$order->pickup_city) }}</span></li>
                                    <li class="rider-stop-point rider-stop-point--delivery"><span class="rider-stop-label">Consegna · {{ $order->recipient_name }}</span><strong>{{ trim($order->delivery_address.' '.$order->delivery_street_number) }}</strong><span>{{ trim($order->delivery_postal_code.' '.$order->delivery_city) }}@if($order->delivery_province) ({{ $order->delivery_province }})@endif</span></li>
                                </ol>
                                <footer class="rider-stop-footer"><span>Rider <b>{{ $order->rider?->name ?? $rider->name }}</b></span><span>Tariffa <b>{{ $order->price_cents === null ? 'da concordare' : $money($order->price_cents) }}</b></span><span>{{ $order->parcel_count }} {{ $plural($order->parcel_count, 'collo', 'colli') }}</span><x-ui.icon name="arrow-up-right" class="rider-stop-open" /></footer>
                            </a>
                        @empty
                            <div class="rider-day-empty"><x-ui.icon name="box-seam" /><h3>Nessuna spedizione per questa selezione.</h3><p>Scegli un altro stato o un periodo diverso.</p></div>
                        @endforelse
                    </div>
                    <x-ui.pagination :paginator="$orders" descriptive />
                    <p class="rider-day-footnote">L’elenco segue la data di ritiro programmata: una consegna conclusa in un giorno successivo resta nella giornata del ritiro, mentre i totali «Nel periodo» la contano nel giorno della consegna.</p>
                </section>

                @if($admin && $accounting)
                    <aside class="rider-day-accounting" aria-labelledby="rider-accounting-heading">
                        <header>
                            <h2 id="rider-accounting-heading" tabindex="-1">@if($selection['single_day']){{ $selection['today'] ? 'Resoconto contabile odierno di '.$rider->name : 'Resoconto contabile del '.$shortDate($period->start).' di '.$rider->name }}@else Resoconto contabile di {{ $rider->name }}@endif</h2>
                            @unless($selection['single_day'])<p class="rider-accounting-range">Dal {{ $shortDate($period->start) }} al {{ $shortDate($period->end) }}</p>@endunless
                        </header>
                        <dl class="rider-day-ledger">
                            <div><dt>Incassi registrati</dt><dd>{{ $money($accounting['gross']) }}</dd></div>
                            <div><dt>Storni contabili</dt><dd>− {{ $money($accounting['reversals']) }}</dd></div>
                            @if($accounting['restorations'])<div><dt>Ripristini</dt><dd>+ {{ $money($accounting['restorations']) }}</dd></div>@endif
                            <div><dt>Spese e uscite</dt><dd>− {{ $money($accounting['expenses']) }}</dd></div>
                            <div class="rider-day-ledger-total"><dt>Saldo contabile</dt><dd>{{ $money($accounting['balance']) }}</dd></div>
                        </dl>
                        <dl class="rider-day-ledger rider-day-cash"><div><dt><x-ui.icon name="cash-stack" /> Variazione contanti</dt><dd>{{ $money($accounting['cash_change']) }}</dd></div>@if($accounting['refunds'])<div><dt>Di cui rimborsi pagati</dt><dd>{{ $money($accounting['refunds']) }}</dd></div>@endif</dl>
                        <p class="rider-day-account-note">La variazione considera solo incassi e uscite effettivi. La disponibilità fisica richiede anche un saldo iniziale e i versamenti del Rider, non presenti nel registro.</p>
                        <div class="rider-day-pending"><h3>Ancora da regolarizzare</h3><dl class="rider-day-ledger"><div><dt>Incassi da registrare</dt><dd>{{ $money($accounting['unrecorded']) }}</dd></div><div><dt>Sospesi in entrata</dt><dd>{{ $money($accounting['pending_incoming']) }}</dd></div><div><dt>Sospesi in uscita</dt><dd>{{ $money($accounting['pending_outgoing']) }}</dd></div></dl><p>I sospesi sono residui delle posizioni aperte nel periodo; possono riferirsi agli incassi da registrare. Sono esclusi dal saldo.</p></div>
                        <a class="rider-day-balance-link" href="{{ route('balance.index', ['from' => $period->start->toDateString(), 'to' => $period->end->toDateString()]) }}">Apri il bilancio generale <x-ui.icon name="arrow-up-right" /></a>
                    </aside>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
