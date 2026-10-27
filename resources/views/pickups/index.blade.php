<x-app-layout title="Agenda ritiri">
    <header class="page-heading">
        <div><span class="eyebrow">EA-EXPRESS · AGENDA OPERATIVA</span><h1>Agenda ritiri</h1><p>Consulta i ritiri ancora da effettuare e apri il resoconto di una giornata.</p></div>
        <div class="row-actions"><a class="btn btn-outline-secondary" href="{{ route('pickups.grouped') }}">Vista raggruppata</a>@if(auth()->user()->role === \App\UserRole::Admin)<x-ui.icon-button action="add" href="{{ route('orders.create') }}" label="Prenota un ritiro" text />@endif</div>
    </header>
    <section class="pickup-calendar surface" data-live-region="pickup-calendar" data-pickup-calendar aria-labelledby="calendar-month">
        <header class="pickup-calendar-heading">
            <div><span class="eyebrow">PROGRAMMAZIONE MENSILE</span><h2 id="calendar-month">{{ ucfirst($month->translatedFormat('F Y')) }}</h2><p role="status">{{ $dailyCounts->sum() }} {{ $dailyCounts->sum() === 1 ? 'ritiro' : 'ritiri' }} da effettuare nel mese</p></div>
            <nav class="pickup-calendar-navigation" aria-label="Navigazione mensile">
                <x-ui.icon-button icon="chevron-left" :href="$previousMonth ? route('pickups.index', ['month' => $previousMonth]) : null" :disabled="! $previousMonth" label="Mese precedente" />
                <a class="btn btn-outline-secondary" href="{{ route('pickups.index', ['month' => $today->format('Y-m')]) }}">Mese corrente</a>
                <x-ui.icon-button icon="chevron-right" :href="$nextMonth ? route('pickups.index', ['month' => $nextMonth]) : null" :disabled="! $nextMonth" label="Mese successivo" />
            </nav>
        </header>
        <table class="pickup-calendar-table" data-table-native>
            <caption class="visually-hidden">{{ $month->translatedFormat('F Y') }}: seleziona un giorno per consultare i ritiri. I badge contano i singoli ordini ancora da ritirare.</caption>
            <thead><tr>@foreach(['Lunedì' => 'Lun', 'Martedì' => 'Mar', 'Mercoledì' => 'Mer', 'Giovedì' => 'Gio', 'Venerdì' => 'Ven', 'Sabato' => 'Sab', 'Domenica' => 'Dom'] as $name => $short)<th scope="col"><abbr title="{{ $name }}">{{ $short }}</abbr></th>@endforeach</tr></thead>
            <tbody>@foreach($weeks as $week)<tr>@foreach($week as $day)<td>
                @if($day)
                    @php($count = $dailyCounts->get($day->toDateString(), 0))
                    <a id="pickup-day-{{ $day->toDateString() }}" href="{{ route('pickups.day', ['date' => $day->toDateString()]) }}" @class(['pickup-calendar-day', 'has-pickups' => $count > 0, 'is-today' => $day->isSameDay($today)]) data-pickup-date="{{ $day->toDateString() }}" data-pickup-count="{{ $count }}" aria-label="{{ $day->translatedFormat('l j F Y') }}: {{ $count }} {{ $count === 1 ? 'ritiro' : 'ritiri' }} da effettuare" @if($day->isSameDay($today)) aria-current="date" @endif>
                        <time datetime="{{ $day->toDateString() }}">{{ $day->day }}</time>
                        @if($count > 0)<span class="pickup-calendar-count" aria-hidden="true">{{ $count }}<small> {{ $count === 1 ? 'ritiro' : 'ritiri' }}</small></span>@endif
                        @if($day->isSameDay($today))<span class="pickup-calendar-today" aria-hidden="true">Oggi</span>@endif
                    </a>
                @else<span class="pickup-calendar-outside" aria-hidden="true"></span>@endif
            </td>@endforeach</tr>@endforeach</tbody>
        </table>
        <footer class="pickup-calendar-foot"><p>I conteggi includono le richieste ricevute con data di ritiro. Sono esclusi annullati, rifiutati e pacchi già ritirati. Orari e date: Europe/Rome.</p><a class="btn btn-outline-secondary" href="{{ route('pickups.grouped', ['view' => 'overdue']) }}">Arretrati <span class="count-badge">{{ $overdueCount }}</span></a></footer>
    </section>
</x-app-layout>
