<x-app-layout title="Attività giornaliera Rider">
    <header class="page-heading"><div><span class="eyebrow">ATTIVITÀ GIORNALIERA RIDER</span><h1>{{ $rider->name }}</h1><p>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('d/m/Y') }}@if($zone) · {{ $zone }}@endif</p></div><x-ui.icon-button action="back" label="Rider per zona" :href="route('rider-operations.index',['date'=>$day['date']])" text/></header>
    <x-ui.filters class="operations-day-filter" layout="date" :reset="route('rider-operations.show', $rider)" compact>
        <x-ui.field name="date" label="Data" type="date" :value="$day['date']" required/>
        @if($zone)<input type="hidden" name="zone" value="{{ $zone }}" data-filter-label="Zona">@endif
    </x-ui.filters>
    <div data-live-region="rider-day">
    <dl class="operations-kpis" aria-label="Riepilogo giornata">
        <div><dt>Ordini associati</dt><dd>{{ $orders->total() }}</dd></div><div><dt>Consegnati</dt><dd>{{ $deliveredCount }}</dd></div><div><dt>Rimanenti</dt><dd>{{ $remaining }}</dd></div><div><dt>Incassato netto</dt><dd>{{ \App\Support\Money::format($cash) }}</dd></div><div><dt>Quota EA</dt><dd>{{ \App\Support\Money::format($retained) }}</dd></div><div><dt>Tariffe delle consegne</dt><dd>{{ \App\Support\Money::format($tariff) }}</dd></div>
    </dl>
    <p class="data-caption">Gli incassi si riferiscono agli ordini programmati nella giornata selezionata e consegnati da questo Rider, anche successivamente e includono i movimenti successivi registrati fino a oggi. Non rappresentano il contante materialmente riscosso dal Rider in quel giorno. Il valore del pacco non è incluso.</p>
    @if($unallocated)<p class="small text-warning">{{ $unallocated }} movimenti fuori regione senza ripartizione EA/vettore: la quota EA mostrata segue il criterio del Bilancio e deve essere verificata.</p>@endif
    <section class="surface section-panel"><h2 class="h5">Cronologia operativa</h2><ol class="rider-activity-timeline">
        @forelse($activity as $item)
            <li><time datetime="{{ $item['event']->created_at->toIso8601String() }}">{{ $item['event']->created_at->timezone('Europe/Rome')->format('d/m/Y H:i:s') }}</time><div><strong>{{ $item['event']->status->label() }} · {{ $item['order']->reference }}</strong><span>{{ $item['zone'] }}</span>@if($item['event']->note || $item['event']->public_note)<small>{{ $item['event']->note ?: $item['event']->public_note }}</small>@endif<small>Registrato da {{ $item['event']->user?->name ?? 'Operatore non disponibile' }}</small></div><x-ui.icon-button action="open" :label="'Apri ordine '.$item['order']->reference" :href="route('orders.show',$item['order'])"/></li>
        @empty<li>Nessun evento operativo attribuibile al Rider in questa giornata.</li>@endforelse
    </ol><p class="small text-secondary">I timestamp provengono dagli eventi registrati. Per gli eventi meno recenti, il Rider viene ricostruito dallo storico delle assegnazioni disponibile; le attività senza attribuzione verificabile non vengono assegnate arbitrariamente.</p></section>
    @include('rider-operations.orders', ['orders' => $orders, 'deadlines' => $deadlines, 'showPayments' => true])
    </div>
</x-app-layout>
