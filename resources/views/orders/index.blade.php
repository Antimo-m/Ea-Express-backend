<x-app-layout :title="$title">
<div @class(['order-workspace-revision', 'order-archive' => $section === 'orders.history'])>
    <header class="dashboard-heading mb-4"><div><span class="eyebrow">{{ $section === 'orders.history' ? 'ARCHIVIO SPEDIZIONI' : 'CENTRO OPERATIVO' }}</span><h1>{{ $title }} @if($section==='orders.in-progress')<span data-live-region="current-count"><span class="shipment-count" aria-label="Spedizioni in corso">{{ $currentCount }}</span></span>@endif</h1><p class="text-secondary mb-0">{{ $section === 'orders.history' ? 'Ritrova una spedizione conclusa, consulta il suo percorso e apri lo storico delle operazioni.' : ($section === 'orders.incoming' ? 'Valuta le richieste, verifica la tariffa e assegna il prossimo ritiro.' : 'Segui il percorso delle spedizioni e intervieni dove serve.') }}</p></div><div class="row-actions">@if($section === 'orders.in-progress')<x-ui.icon-button action="print-multiple" data-label-availability :aria-disabled="$printableCount ? 'false' : 'true'" target="_blank" rel="noopener" href="{{ route('labels.index',[...request()->only(['q','zone','status','customer_id','rider_id','shipping_type','urgency','sender_type','from','to']),'scope'=>'filtered']) }}" label="Stampa etichette dei risultati filtrati" />@endif @if(auth()->user()->role === \App\UserRole::Admin)<x-ui.icon-button action="add" href="{{ route('orders.create') }}" label="Nuova richiesta" />@endif</div></header>
    <x-ui.filters :reset="route($section)">
        <x-slot:search><x-ui.field name="q" label="Cerca ordine" :value="request('q')" placeholder="Cerca ordine, cliente, destinatario…" /></x-slot:search>
        @if($section === 'orders.history')
            <x-ui.field name="day" label="Vai al giorno" type="date" :value="request('day')" />
            <div class="filter-range" data-filter-range>
                <button type="button" class="btn btn-outline-secondary" data-custom-period aria-controls="filter-custom-dates" aria-expanded="{{ request('from') || request('to') ? 'true' : 'false' }}">Periodo <x-ui.icon name="calendar3" /></button>
                <div class="filter-range-dates" id="filter-custom-dates" data-custom-dates @if(!request('from') && !request('to')) hidden @endif>
                    <x-ui.field name="from" label="Dal" type="date" :value="request('from')" />
                    <x-ui.field name="to" label="Al" type="date" :value="request('to')" />
                </div>
            </div>
        @endif
        @if($section !== 'orders.incoming')
            <x-ui.filter-select name="status" label="Stato" :options="[''=>'Tutti', ...collect(\App\OrderStatus::cases())->filter(fn($state)=>$section === 'orders.history' ? in_array($state->value, \App\OrderStatus::closed()) : !in_array($state->value, [...\App\OrderStatus::closed(), 'received']))->mapWithKeys(fn($state)=>[$state->value=>$state->label()])->all()]" />
            @if($section === 'orders.history')<x-ui.searchable-select name="customer_id" label="Cliente" :options="$customers" :value="request('customer_id')" />
            @else<x-ui.searchable-select name="rider_id" label="Rider" :options="$riders" :value="request('rider_id')" />@endif
        @else
            <div class="filter-control filter-md"><x-ui.field name="zone" label="Zona" :value="request('zone')" placeholder="Zona / comune…" /></div>
            <x-ui.filter-select name="urgency" label="Urgenza" :options="[''=>'Tutte','standard'=>'Standard','urgent'=>'Urgente']" />
        @endif
        <x-slot:secondary>
            @if($section === 'orders.history')<x-ui.searchable-select name="rider_id" label="Rider" :options="$riders" :value="request('rider_id')" />@endif
            @if($section !== 'orders.incoming')
                <div class="filter-control filter-md"><x-ui.field name="zone" label="Zona" :value="request('zone')" placeholder="Zona / comune…" /></div>
            @endif
            <x-ui.filter-select name="shipping_type" label="Servizio" :options="[''=>'Tutti','regional'=>'Regionale','external'=>'Fuori regione']" size="md" />
            @if($section !== 'orders.incoming')<x-ui.filter-select name="urgency" label="Urgenza" :options="[''=>'Tutte','standard'=>'Standard','urgent'=>'Urgente']" />@endif
            <x-ui.filter-select name="sender_type" label="Tipo mittente" :options="[''=>'Tutti','business'=>'Attività commerciale','private'=>'Privato','online_shop'=>'Shop online']" size="md" />
        </x-slot:secondary>
    </x-ui.filters>
    @if($section === 'orders.history')
    <section class="surface history-print-toolbar" data-history-print-toolbar data-label-url="{{ route('labels.index') }}" aria-label="Selezione etichette">
        <div class="history-print-start"><x-ui.icon-button action="print-multiple" label="Stampa etichette" data-history-print-start :aria-disabled="$archivePrintableTotal ? 'false' : 'true'" text /></div>
        <div class="history-print-controls" hidden>
            <p>Scegli una giornata intera oppure una singola spedizione. I filtri attivi vengono mantenuti.</p>
            <p role="status" data-history-print-status>Nessuna etichetta selezionata</p>
            <div class="action-pair"><button type="button" class="btn btn-outline-secondary" data-history-print-cancel>Annulla selezione</button><x-ui.icon-button action="print" label="Conferma stampa" data-history-print-confirm aria-disabled="true" text /></div>
        </div>
        <p role="status" data-label-status></p>
    </section>
    @endif
    <div data-live-region="orders">
    @if($section === 'orders.history')
        <section class="order-archive-results" aria-label="Archivio spedizioni">
            <header class="archive-results-heading"><div><h2>{{ $archiveTotal }} {{ $archiveTotal === 1 ? 'spedizione trovata' : 'spedizioni trovate' }}</h2><p>{{ $archiveDays->total() }} giornate · Richieste degli ultimi 12 mesi · Apri direttamente il giorno che desideri.</p></div></header>
            @if($selectedDate && !$archiveDays->contains(fn ($day) => $day['date'] === $selectedDate))<p class="archive-empty-date" role="status">Nessuna spedizione per il {{ \Illuminate\Support\Carbon::parse($selectedDate)->format('d/m/Y') }} con i filtri selezionati.</p>@endif
            @forelse($archiveDays as $day)
                @php($date = $day['date'])
                <details class="archive-day" id="archive-day-{{ $date }}" name="order-history-days" data-order-history-day="{{ $date }}" data-history-url="{{ route('orders.history', [...request()->except('day_page'), 'day' => $date, 'page' => $archiveDays->currentPage(), 'day_page' => $date === $selectedDate ? $orders->currentPage() : 1]) }}" @if($date === $selectedDate) open @endif>
                    <summary class="archive-day-heading"><time datetime="{{ $date }}">{{ \Illuminate\Support\Carbon::parse($date, 'Europe/Rome')->locale('it')->translatedFormat('l j F Y') }}</time><span>{{ $day['total'] }} {{ $day['total'] === 1 ? 'ordine' : 'ordini' }}</span><label class="history-print-choice"><input type="radio" name="history-label-choice" value="day:{{ $date }}" data-history-label-day="{{ $date }}" data-label-count="{{ $day['printable'] }}" @disabled(!$day['printable'])> Stampa giornata · {{ $day['printable'] }} etichette</label><x-ui.icon name="chevron-down" /></summary>
                    @if($date === $selectedDate)
                        @include('orders.history-day')
                    @else
                        <div data-history-day-content data-history-date="{{ $date }}"><p>Gli ordini di questa giornata vengono caricati quando la apri.</p><a class="btn btn-outline-secondary" data-history-load href="{{ route('orders.history', [...request()->except('day_page'), 'day' => $date, 'page' => $archiveDays->currentPage(), 'day_page' => $date === $selectedDate ? $orders->currentPage() : 1]) }}">Apri la giornata</a></div>
                    @endif
                </details>
            @empty
                <div class="surface empty-state"><x-ui.icon name="archive" /><h2>Nessuna spedizione nello storico</h2><p>Modifica i filtri per cercare altre richieste concluse negli ultimi 12 mesi.</p></div>
            @endforelse
            <div class="archive-days-pagination" aria-label="Navigazione tra giornate">{{ $archiveDays->links('components.ui.pagination') }}</div>
        </section>
    @else
@if($section==='orders.in-progress')<p class="data-caption">{{ $orders->total() }} risultati filtrati · La stampa nell’intestazione include tutte le pagine dei risultati, un’etichetta A6 per spedizione.</p>@endif
<section class="operational-order-list" aria-label="Elenco spedizioni">

@forelse($orders as $order)
<x-orders.operational-row :order="$order" :incoming="$section === 'orders.incoming'" :recipient-risk="$recipientRisks[$order->id] ?? null" />
@empty<div class="surface p-5 text-center"><x-ui.icon name="inbox" class="display-6 text-secondary" /><h2 class="h5 mt-3">Nessun ordine trovato</h2><p class="text-secondary mb-0">{{ auth()->user()->role === \App\UserRole::Rider ? 'Qui trovi le spedizioni assegnate al tuo account. Prova a modificare i filtri.' : 'Le richieste compariranno qui. Puoi crearne una o modificare i filtri.' }}</p></div>@endforelse</section>
    @endif
    @if($section !== 'orders.history')<div class="mt-4">{{ $orders->links('components.ui.pagination') }}</div>@endif
</div></div></x-app-layout>
