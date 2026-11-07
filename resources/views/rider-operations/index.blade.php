<x-app-layout title="Rider per Zona">
    <div class="rider-operations-page">
    <header class="page-heading"><div><span class="eyebrow">CONTROLLO OPERATIVO</span><h1>Rider per Zona</h1><p>Programmazione e avanzamento del periodo selezionato.</p></div></header>
    <x-ui.filters class="operations-period-filter" layout="operational" :reset="route('rider-operations.index', request()->only(['view','q','filter','sort','selected']))" compact data-operations-period>
        <x-ui.filter-period route="rider-operations.index" :period="$period" operational operational-range />
        @foreach(['view'=>'zone','q'=>'','filter'=>'all','sort'=>'name','selected'=>''] as $name=>$default)<input type="hidden" name="{{ $name }}" value="{{ request($name,$default) }}" data-filter-context>@endforeach
    </x-ui.filters>
    <section class="rider-operations" data-rider-operations data-zone-orders-url="{{ route('rider-operations.zone-orders') }}" data-feed="{{ route('rider-operations.feed', request()->only(['date','from','to','period'])) }}" data-detail-url="{{ route('rider-operations.detail', request()->only(['date','from','to','period'])) }}">
        <dl class="operations-kpis" aria-label="Riepilogo periodo">@foreach(['total'=>'Programmate','delivered'=>'Consegnate','pending'=>'Da consegnare','unassigned'=>'Da assegnare','riders'=>'Rider assegnati','zones'=>'Zone interessate'] as $key=>$label)<div><dt>{{ $label }}</dt><dd data-kpi="{{ $key }}">{{ $day['summary'][$key] }}</dd></div>@endforeach</dl>
        <p class="data-caption" data-operations-caption>Stati e consegne mostrano la situazione attuale. Gli ordini rimangono nella data programmata anche quando vengono completati dopo.</p>
        <section class="surface operations-explorer">
            <header class="explorer-toolbar"><nav class="operations-perspectives" aria-label="Vista operativa"><button type="button" data-operations-view="zone" aria-pressed="{{ request('view','zone') === 'zone' ? 'true' : 'false' }}">Per Zona</button><button type="button" data-operations-view="rider" aria-pressed="{{ request('view') === 'rider' ? 'true' : 'false' }}">Per Rider</button></nav><div class="rider-refresh-controls"><span class="small text-secondary" data-zone-updated></span><button type="button" class="btn btn-outline-secondary btn-sm" data-operations-refresh>Aggiorna</button></div></header>
            <div class="explorer-layout">
                <aside class="explorer-directory" aria-label="Elenco operativo">
                    <label class="explorer-search"><x-ui.icon name="search" /><input type="search" data-operations-search placeholder="Cerca zona o Rider…" aria-label="Cerca zona o Rider"></label>
                    <div class="explorer-refinements"><label>Mostra<select data-operations-filter><option value="all">Tutti</option><option value="open">Ordini aperti</option></select></label><label>Ordina<select data-operations-sort><option value="name">Nome</option><option value="delivered">Consegnati</option><option value="pending">Da consegnare</option><option value="unassigned">Da assegnare</option></select></label></div>
                    <p class="explorer-result-count" data-operations-count></p>
                    <div class="explorer-list" data-operations-list aria-label="Zone del periodo">@foreach($day['zones'] as $zone)<button type="button" class="explorer-item" data-operations-key="{{ $zone['key'] }}" aria-pressed="false"><strong>{{ $zone['name'] }}</strong><span>{{ $zone['delivered'] }} consegnate · {{ $zone['pending'] }} da consegnare</span><small>{{ $zone['unassigned'] }} da assegnare</small></button>@endforeach</div>
                </aside>
                <section class="explorer-detail" data-operations-detail aria-label="Dettaglio selezione"><h2>Seleziona una zona</h2><p>Consulta assegnazioni e ordini della giornata.</p></section>
            </div>
            <p class="small text-danger explorer-feedback" data-zone-error role="status" hidden></p>
        </section>
        <section class="surface section-panel mt-4" data-operations-history @if(!count($day['schedule_history'])) hidden @endif><h2 class="h5">Programmazioni variate</h2><p class="small text-secondary">Ultime 20 variazioni che hanno interessato il periodo selezionato. Lo storico completo rimane nel relativo ordine.</p><div data-operations-history-items>@foreach($day['schedule_history'] as $change)<p class="small"><a href="{{ $change['url'] }}">{{ $change['reference'] }}</a> · {{ $change['before']['pickup_date'] }} → {{ $change['after']['pickup_date'] }} · {{ $change['operator'] }} · {{ $change['reason'] }}</p>@endforeach</div></section>
        @php($initial = \Illuminate\Support\Arr::except($day, ['orders']))
        <script type="application/json" data-zone-initial>@json($initial)</script>
    </section>
    </div>
</x-app-layout>
