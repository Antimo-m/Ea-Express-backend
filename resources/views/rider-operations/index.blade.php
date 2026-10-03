<x-app-layout title="Rider per zona">
    <header class="page-heading"><div><span class="eyebrow">OPERATIVITÀ RIDER</span><h1>Rider per zona</h1><p>{{ $day['today'] ? 'Posizioni GPS e consegne in corso.' : 'Zone delle consegne effettuate nella giornata.' }}</p></div></header>
    <x-ui.filters layout="date" :reset="route('rider-operations.index')" compact>
        <x-ui.field name="date" label="Data" type="date" :value="$day['date']" :max="now('Europe/Rome')->toDateString()" required />
        <nav class="period-options filter-view-options" aria-label="Raggruppamento attività"><button type="button" class="btn btn-outline-secondary btn-sm active" data-operations-view="rider" aria-pressed="true">Per Rider</button><button type="button" class="btn btn-outline-secondary btn-sm" data-operations-view="zone" aria-pressed="false">Per Zona</button></nav>
    </x-ui.filters>
    <section class="rider-operations" data-rider-operations data-feed="{{ route('rider-operations.feed', ['date'=>$day['date']]) }}" data-today="{{ $day['today'] ? 'true' : 'false' }}">
        <dl class="operations-kpis" data-operations-kpis>@foreach(['total'=>'Consegne nella giornata','active'=>'In corso','delivered'=>'Completate','riders'=>'Rider operativi','zones'=>'Zone coperte'] as $key=>$label)<div><dt>{{ $label }}</dt><dd data-kpi="{{ $key }}">{{ $day['summary'][$key] }}</dd></div>@endforeach</dl>
        <section class="surface section-panel rider-map-panel">
            <header class="section-heading"><div><span class="eyebrow">{{ $day['today'] ? 'GPS LIVE' : 'STORICO' }}</span><h2 class="h5">{{ $day['today'] ? 'Posizione dei Rider' : 'Consegne del '.$day['date'] }}</h2></div><span class="small text-secondary" data-zone-updated></span></header>
            @if($day['today'])
                <div class="operational-map" data-operational-map aria-label="Mappa delle posizioni GPS aggiornate"></div>
                <p class="small text-secondary" data-map-empty>Nessuna posizione GPS recente disponibile.</p>
                <p class="small text-secondary">La mappa mostra soltanto GPS ricevuti negli ultimi 90 secondi. La zona indicata sotto è quella dell’ordine, non una localizzazione GPS.</p>
            @else
                <p class="small text-secondary">Lo storico usa le consegne e gli eventi registrati. Apri il dettaglio di un Rider per vedere i campioni GPS disponibili, conservati per 30 giorni.</p>
            @endif
            <p class="small text-danger" data-zone-error role="status" hidden></p>
        </section>
        <p class="data-caption">{{ $day['today'] ? 'Zone degli ordini assegnati · uno stesso Rider può avere ordini in più zone.' : 'Uno stesso Rider compare in ogni zona in cui ha effettuato consegne.' }}</p>
        @if(!$day['today'] && $day['legacy_zones'])<p class="data-caption">{{ $day['legacy_zones'] }} consegne meno recenti usano la zona attualmente salvata nell’ordine, perché la zona originale non è disponibile.</p>@endif
        <section class="unassigned-summary" data-unassigned-summary @if(!$day['summary']['unassigned']) hidden @endif><header><h2 class="h5">Da assegnare</h2><strong data-unassigned-count>{{ $day['summary']['unassigned'] }} ordini</strong></header><div class="rider-zone-chips" data-unassigned-zones>@foreach($day['unassigned'] as $zone)<a class="btn btn-outline-secondary btn-sm" href="{{ $zone['url'] }}">{{ $zone['name'] }} · {{ $zone['count'] }}</a>@endforeach</div></section>

        <p class="small text-secondary">Incassato netto: pagamenti, rettifiche e storni degli ordini mostrati, registrati fino a oggi. Le tariffe previste sono distinte dagli incassi e dal valore del pacco.</p>
        <div class="rider-card-grid" data-rider-grid>@forelse($day['riders'] as $rider)@include('rider-operations.card')@empty<p>Nessun Rider con attività registrata nella giornata.</p>@endforelse</div>
        <div class="rider-zone-grid" data-zone-grid hidden>
            @forelse($day['zones'] as $zone)
                <section class="surface rider-zone"><header><h2>{{ $zone['name'] }}</h2><span class="count-badge">{{ count($zone['riders']) }} Rider</span></header>
                    @foreach($zone['riders'] as $rider)
                        <article class="rider-zone-person" data-rider-state="{{ $rider['state'] }}"><div><strong>{{ $rider['name'] }}</strong><span class="rider-state">{{ $rider['label'] }}</span><small>{{ $rider['active_count'] }} in corso · {{ $rider['delivered_count'] }} consegnate</small><small>{{ $rider['last_gps'] ? 'Ultimo GPS: '.\Illuminate\Support\Carbon::parse($rider['last_gps'])->timezone('Europe/Rome')->format('H:i:s') : 'GPS non disponibile' }}</small></div>@if($rider['url'])<x-ui.icon-button icon="person" action="open" :label="'Attività di '.$rider['name']" :href="$rider['url']"/>@endif</article>
                    @endforeach
                </section>
            @empty<div class="surface empty-state"><h2>Nessuna consegna nella giornata</h2><p>Scegli un’altra data per consultare lo storico.</p></div>@endforelse
        </div>
        @php($initial = \Illuminate\Support\Arr::except($day, ['activity','deliveries','orders']))
        <script type="application/json" data-zone-initial>@json($initial)</script>
    </section>
</x-app-layout>
