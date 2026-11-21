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
            <form method="get" action="{{ $pageUrl() }}" class="rider-period-form rider-period-range" data-rider-day-period aria-label="Scegli il periodo">
                <input type="hidden" name="period" value="custom">@if($zone)<input type="hidden" name="zone" value="{{ $zone }}">@endif
                <label>Mese<input type="month" data-period-month value="{{ $selection['shortcut'] === 'month' ? $selection['month'] : '' }}" max="{{ now('Europe/Rome')->format('Y-m') }}" aria-describedby="rider-period-help"></label>
                <label>Anno<select data-period-year aria-describedby="rider-period-help"><option value="">—</option>@foreach(range(now('Europe/Rome')->year, 2025) as $year)<option value="{{ $year }}" @selected($selection['shortcut'] === 'year' && $selection['year'] === $year)>{{ $year }}</option>@endforeach</select></label>
                <label>Data iniziale<input type="date" name="from" value="{{ $period->start->toDateString() }}" required></label>
                <label>Data finale<input type="date" name="to" value="{{ $period->end->toDateString() }}" min="{{ $period->start->toDateString() }}" required></label>
                <span class="rider-period-actions"><x-ui.icon-button action="reset" :href="$pageUrl(array_filter(['zone' => $zone]))" label="Torna a oggi" data-rider-day-reset /><x-ui.icon-button action="search" type="submit" label="Applica il periodo" /></span>
                <p class="rider-period-help" id="rider-period-help">Mese e anno compilano le date: la ricerca usa sempre data iniziale e finale.</p>
            </form>
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
                    <div><dt>Spedizioni assegnate</dt><dd data-count-to="{{ $summary['assigned'] }}">{{ $summary['assigned'] }}</dd><small>con ritiro programmato nel periodo</small></div>
                    <div><dt>Ritirate</dt><dd data-count-to="{{ $summary['picked_up'] }}">{{ $summary['picked_up'] }}</dd><small>pacchi presi in carico</small></div>
                    <div class="rider-kpi--delivered"><dt>Consegnate</dt><dd data-count-to="{{ $summary['delivered'] }}">{{ $summary['delivered'] }}</dd><small>consegne confermate</small></div>
                    <div class="rider-kpi--cancelled"><dt>Annullate</dt><dd data-count-to="{{ $summary['cancelled'] }}">{{ $summary['cancelled'] }}</dd><small>mentre erano affidate {{ $admin ? 'al Rider' : 'a te' }}</small></div>
                </dl>
                <div class="rider-track" style="--done: {{ $counts['valid'] ? $counts['progress'] : 0 }}%">
                    <div class="rider-track-heading"><div><h3>Avanzamento delle spedizioni assegnate</h3><p>{{ $counts['completed'] }} di {{ $counts['valid'] }} {{ $plural($counts['valid'], 'spedizione completata', 'spedizioni completate') }} · stato attuale delle spedizioni con ritiro nel periodo</p></div><strong class="rider-track-percent">@if($counts['valid'])<span data-count-to="{{ $counts['progress'] }}">{{ $counts['progress'] }}</span><small>%</small>@else<small>Nessuna consegna prevista</small>@endif</strong></div>
                    <div class="rider-track-road" role="progressbar" aria-label="Spedizioni completate" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $counts['valid'] ? $counts['progress'] : 0 }}">
                        <span class="rider-track-lane" aria-hidden="true"></span><span class="rider-track-fill" aria-hidden="true"></span>
                        <span class="rider-track-parcel" aria-hidden="true"><x-ui.icon name="box-seam-fill" /></span>
                        <span class="rider-track-flag" aria-hidden="true"><x-ui.icon name="flag-fill" /></span>
                    </div>
                    <ol class="rider-track-stations" aria-label="Spedizioni per stato">
                        @foreach(['todo' => ['Da effettuare', 'hourglass-split'], 'active' => ['In corso', 'truck'], 'completed' => ['Completate', 'check2-circle'], 'issues' => ['Da verificare', 'exclamation-triangle'], 'closed' => ['Annullate o rifiutate', 'x-circle']] as $key => [$label, $icon])
                            <li @class(['rider-station', 'rider-station--'.$key, 'is-zero' => ! $counts[$key]])><span class="rider-station-icon" aria-hidden="true"><x-ui.icon :name="$icon" /></span><b data-count-to="{{ $counts[$key] }}">{{ $counts[$key] }}</b><small>{{ $label }}</small></li>
                        @endforeach
                    </ol>
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

            <div class="rider-page-layout rider-page-layout--admin">
                <section class="rider-deliveries" aria-labelledby="rider-deliveries-heading">
                    <div class="rider-section-title"><h2 id="rider-deliveries-heading">Le spedizioni del periodo</h2><span>{{ $orders->total() }} {{ $plural($orders->total(), 'spedizione', 'spedizioni') }} con ritiro programmato nel periodo</span></div>
                    <nav class="rider-day-tabs" aria-label="Filtra spedizioni per stato">
                        @foreach(['all' => ['Tutte', $counts['total']], 'todo' => ['Da effettuare', $counts['todo']], 'active' => ['In corso', $counts['active']], 'completed' => ['Completate', $counts['completed']], 'issues' => ['Da verificare', $counts['issues']]] + ($counts['closed'] ? ['closed' => ['Annullate o rifiutate', $counts['closed']]] : []) as $key => [$label, $count])
                            <a href="{{ $pageUrl([...$base, 'state' => $key]) }}" @class(['is-cancelled' => $key === 'closed']) @if($state === $key) aria-current="page" @endif>{{ $label }}<span>{{ $count }}</span></a>
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

                <aside class="rider-wallet" aria-labelledby="rider-accounting-heading">
                    <header>
                        <span class="rider-wallet-icon" aria-hidden="true"><x-ui.icon name="wallet2" /></span>
                        <h2 id="rider-accounting-heading" tabindex="-1">@if($selection['single_day']){{ $selection['today'] ? 'Resoconto contabile odierno di '.$rider->name : 'Resoconto contabile del '.$shortDate($period->start).' di '.$rider->name }}@else Resoconto contabile di {{ $rider->name }}@endif</h2>
                        @unless($selection['single_day'])<p class="rider-accounting-range">Dal {{ $shortDate($period->start) }} al {{ $shortDate($period->end) }}</p>@endunless
                    </header>
                    <dl class="rider-wallet-lines">
                        <div class="rider-wallet-line is-income"><dt><span class="money-arrow" aria-hidden="true"><x-ui.icon name="arrow-down-left" /></span>Netto finale disponibile<small>Incassi in contanti registrati dall’Admin, al netto di storni e rimborsi</small></dt><dd @class(['money', 'money-in', 'is-zero' => ! $balance['net_cash']])>{{ $money($balance['net_cash']) }}</dd></div>
                        <div class="rider-wallet-line is-expense"><dt><span class="money-arrow" aria-hidden="true"><x-ui.icon name="arrow-up-right" /></span>Spese<small>Spese registrate {{ $admin ? 'dal Rider' : 'da te' }} nel periodo</small></dt><dd @class(['money', 'money-out', 'is-zero' => ! $balance['expenses']])>@if($balance['expenses'])− @endif{{ $money($balance['expenses']) }}</dd></div>
                    </dl>
                    <div @class(['rider-wallet-total', 'is-negative' => $balance['available'] < 0])><span>Saldo disponibile</span><strong data-money-value="{{ $balance['available'] }}">{{ $money($balance['available']) }}</strong><small>Netto finale disponibile − Spese</small></div>
                    @if($balance['declared_count'])<p class="rider-wallet-pending"><x-ui.icon name="hourglass-split" /> {{ $balance['declared_count'] }} {{ $plural($balance['declared_count'], 'conferma d’incasso', 'conferme d’incasso') }} in attesa dell’Admin · {{ $money($balance['declared_cents']) }}. Entreranno nel netto quando l’Admin registrerà l’incasso.</p>@endif
                    @if($admin)
                        <a class="rider-day-balance-link" href="{{ route('balance.index', ['from' => $period->start->toDateString(), 'to' => $period->end->toDateString()]) }}">Apri il bilancio generale <x-ui.icon name="arrow-up-right" /></a>
                    @else
                        <div class="rider-wallet-actions">
                            <x-ui.action-dialog id="rider-expense" action="receive" icon="receipt" title="Registra una spesa" description="La spesa viene registrata a tuo nome e l’Admin la vede nel Bilancio." text>
                                <form method="post" action="{{ route('rider-operations.expenses.store') }}" class="form-stack mt-3">@csrf<input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                    <x-ui.field name="description" id="rider-expense-description" label="Descrizione" maxlength="200" placeholder="Es. carburante" required />
                                    <x-ui.field name="amount" id="rider-expense-amount" label="Importo (€)" inputmode="decimal" required />
                                    <x-ui.field name="spent_on" id="rider-expense-date" label="Data della spesa" type="date" :value="now('Europe/Rome')->toDateString()" :max="now('Europe/Rome')->toDateString()" required />
                                    <x-ui.icon-button action="confirm" type="submit" label="Registra spesa" />
                                </form>
                            </x-ui.action-dialog>
                        </div>
                        <section class="rider-receipts" aria-labelledby="rider-receipts-heading">
                            <h3 id="rider-receipts-heading">Incassi da confermare</h3>
                            <p>Segnala all’Admin i pagamenti che hai ricevuto: la registrazione nel Bilancio resta all’Admin.</p>
                            @forelse($toConfirm as $order)
                                @php($pendingDeclaration = $order->receiptDeclarations->first())
                                <article class="rider-receipt">
                                    <div><strong>{{ $order->reference }}</strong><span>{{ $order->displayName() }} · {{ $order->recipient_name }}</span><small>Tariffa {{ $money($order->price_cents) }}</small></div>
                                    @if($pendingDeclaration)
                                        <span class="rider-receipt-sent"><x-ui.icon name="hourglass-split" /> Inviata · {{ $money($pendingDeclaration->amount_cents) }}</span>
                                    @else
                                        <button type="button" class="btn rider-receipt-confirm" data-dialog-open="declare-{{ $order->slug }}" aria-controls="declare-{{ $order->slug }}" aria-haspopup="dialog"><x-ui.icon name="check2-circle" /> Conferma incasso</button>
                                        <x-ui.modal :id="'declare-'.$order->slug" title="Conferma incasso ricevuto" :description="$order->reference.' · '.$order->recipient_name">
                                            <form method="post" action="{{ route('rider-operations.receipts.declare', $order) }}" class="form-stack mt-3">@csrf
                                                <p>L’Admin riceverà la tua conferma e registrerà l’incasso effettivo.</p>
                                                <x-ui.field name="amount" :id="'declare-amount-'.$order->slug" label="Importo ricevuto (€)" inputmode="decimal" :value="number_format($order->price_cents / 100, 2, ',', '')" required />
                                                <x-ui.field name="note" :id="'declare-note-'.$order->slug" label="Nota (facoltativa)" maxlength="300" />
                                                <x-ui.icon-button action="confirm" type="submit" label="Invia conferma" />
                                            </form>
                                        </x-ui.modal>
                                    @endif
                                </article>
                            @empty
                                <p class="rider-receipts-empty"><x-ui.icon name="check2-circle" /> Nessun incasso da confermare.</p>
                            @endforelse
                        </section>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
