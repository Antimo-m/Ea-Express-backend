<x-app-layout title="Attività giornaliera Rider">
    <header class="page-heading"><div><span class="eyebrow">ATTIVITÀ GIORNALIERA RIDER</span><h1>{{ $rider->name }}</h1><p>{{ $period->start->format('d/m/Y') }} – {{ $period->end->format('d/m/Y') }}@if($zone) · {{ $zone }}@endif</p></div><x-ui.icon-button action="back" label="Rider per zona" :href="route('rider-operations.index',request()->only(['date','period','view','q','filter','sort','selected','scope']))" text/></header>
    <x-ui.filters class="operations-period-filter" layout="operational" :reset="route('rider-operations.show', $rider)" compact>
        <x-ui.filter-period route="rider-operations.show" :period="$period" operational />
        @foreach(request()->only(['zone','view','q','filter','sort','selected','scope']) as $name=>$value)<input type="hidden" name="{{ $name }}" value="{{ $value }}" data-filter-context>@endforeach
    </x-ui.filters>
    <div data-live-region="rider-day">
    <dl class="operations-kpis" aria-label="Riepilogo giornata">
        <div><dt>Ordini associati</dt><dd>{{ $programmedCount }}</dd></div><div><dt>Consegnati</dt><dd>{{ $deliveredCount }}</dd></div><div><dt>Rimanenti</dt><dd>{{ $remaining }}</dd></div><div><dt>Incassato netto</dt><dd>{{ \App\Support\Money::format($cash) }}</dd></div><div><dt>In ritardo (totali)</dt><dd>{{ $day['summary']['overdue'] }}</dd></div><div><dt>Quota EA</dt><dd>{{ \App\Support\Money::format($retained) }}</dd></div><div><dt>Tariffe delle consegne</dt><dd>{{ \App\Support\Money::format($tariff) }}</dd></div>
    </dl>
    <p class="data-caption">Gli incassi si riferiscono agli ordini programmati nel periodo selezionato e consegnati da questo Rider, anche successivamente e includono i movimenti successivi registrati fino a oggi. Non rappresentano il contante materialmente riscosso dal Rider in quel giorno. Il valore del pacco non è incluso.</p>
    @if($unallocated)<p class="small text-warning">{{ $unallocated }} movimenti fuori regione senza ripartizione EA/vettore: la quota EA mostrata segue il criterio del Bilancio e deve essere verificata.</p>@endif
    @if($day['summary']['missing_deadlines'])<p class="small text-warning">{{ $day['summary']['missing_deadlines'] }} ordini attualmente aperti del Rider sono privi di scadenza verificabile e non possono essere classificati in ritardo.</p>@endif
    <section class="surface section-panel"><h2 class="h5">Cronologia operativa</h2>
        <nav class="operations-perspectives mb-3" aria-label="Elenco spedizioni"><a class="btn btn-outline-secondary btn-sm {{ request('scope','programmed') === 'programmed' ? 'active' : '' }}" href="{{ route('rider-operations.show',['rider'=>$rider->slug,...request()->except(['page','scope']),'scope'=>'programmed']) }}">Programmate</a><a class="btn btn-outline-secondary btn-sm {{ request('scope') === 'late' ? 'active' : '' }}" href="{{ route('rider-operations.show',['rider'=>$rider->slug,...request()->except(['page','scope']),'scope'=>'late']) }}">Ritardi e arretrati ({{ $day['summary']['overdue'] }})</a></nav>
        <p class="data-caption">Stati e consegne mostrano la situazione attuale. I ritardi storici sono ricostruiti al {{ $period->reference()->format('d/m/Y H:i') }}.</p>
        @include('rider-operations.orders', ['orders' => $orders, 'deadlines' => $deadlines, 'showPayments' => true, 'chronology' => true])
        <p class="small text-secondary">Dieci ordini distinti per pagina. Gli eventi conservano gli orari effettivi; lo storico completo è disponibile nel dettaglio ordine.</p>
    </section>
    </div>
</x-app-layout>
