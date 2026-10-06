<x-app-layout title="Rider per Zona">
    <header class="page-heading"><div><span class="eyebrow">CONTROLLO OPERATIVO</span><h1>Rider per Zona</h1><p>Programmazione, avanzamento e risultati della giornata.</p></div></header>
    <x-ui.filters class="operations-day-filter" layout="date" :reset="route('rider-operations.index')" compact><x-ui.field name="date" label="Data" type="date" :value="$day['date']" required /></x-ui.filters>
    <section class="rider-operations" data-rider-operations data-feed="{{ route('rider-operations.feed', ['date'=>$day['date']]) }}" data-detail-url="{{ route('rider-operations.detail', ['date'=>$day['date']]) }}">
        <dl class="operations-kpis" aria-label="Riepilogo giornata">@foreach(['total'=>'Programmate','delivered'=>'Consegnate','undelivered'=>'Non consegnate','overdue'=>'In ritardo','riders'=>'Rider assegnati','zones'=>'Zone interessate'] as $key=>$label)<div><dt>{{ $label }}</dt><dd data-kpi="{{ $key }}">{{ $day['summary'][$key] }}</dd></div>@endforeach</dl>
        <p class="data-caption">Le non consegnate includono gli ordini annullati o rifiutati; i ritardi riguardano solo ordini ancora aperti con una scadenza verificabile. Gli ordini rimangono nella data programmata anche quando vengono completati dopo.</p>
        <section class="surface operations-explorer">
            <header class="explorer-toolbar"><nav class="operations-perspectives" aria-label="Vista operativa"><button type="button" data-operations-view="zone" aria-pressed="true">Per Zona</button><button type="button" data-operations-view="rider" aria-pressed="false">Per Rider</button></nav><span class="small text-secondary" data-zone-updated></span></header>
            <div class="explorer-layout">
                <aside class="explorer-directory" aria-label="Elenco operativo">
                    <label class="explorer-search"><x-ui.icon name="search" /><input type="search" data-operations-search placeholder="Cerca zona o Rider…" aria-label="Cerca zona o Rider"></label>
                    <div class="explorer-refinements"><label>Mostra<select data-operations-filter><option value="all">Tutti</option><option value="open">Non consegnate</option><option value="late">In ritardo</option></select></label><label>Ordina<select data-operations-sort><option value="name">Nome</option><option value="late">Ritardi</option><option value="volume">Volume</option></select></label></div>
                    <p class="explorer-result-count" data-operations-count></p>
                    <div class="explorer-list" data-operations-list aria-label="Zone della giornata">@foreach($day['zones'] as $zone)<button type="button" class="explorer-item" data-operations-key="{{ $zone['key'] }}" aria-pressed="false"><strong>{{ $zone['name'] }}</strong><span>{{ $zone['total'] }} programmate · {{ $zone['delivered'] }} consegnate</span><small>{{ $zone['undelivered'] }} non consegnate · {{ $zone['overdue'] }} in ritardo</small></button>@endforeach</div>
                </aside>
                <section class="explorer-detail" data-operations-detail aria-label="Dettaglio selezione"><h2>Seleziona una zona</h2><p>Consulta assegnazioni e ordini della giornata.</p></section>
            </div>
            <p class="small text-danger explorer-feedback" data-zone-error role="status" hidden></p>
        </section>
        <section class="surface section-panel mt-4" data-operations-history @if(!count($day['schedule_history'])) hidden @endif><h2 class="h5">Programmazioni variate</h2><p class="small text-secondary">Ultime 20 variazioni che hanno interessato questa data. Lo storico completo rimane nel relativo ordine.</p><div data-operations-history-items>@foreach($day['schedule_history'] as $change)<p class="small"><a href="{{ $change['url'] }}">{{ $change['reference'] }}</a> · {{ $change['before']['pickup_date'] }} → {{ $change['after']['pickup_date'] }} · {{ $change['operator'] }} · {{ $change['reason'] }}</p>@endforeach</div></section>
        @php($initial = \Illuminate\Support\Arr::except($day, ['orders']))
        <script type="application/json" data-zone-initial>@json($initial)</script>
    </section>
</x-app-layout>
