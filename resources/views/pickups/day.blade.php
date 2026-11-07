<x-app-layout title="Ritiri del {{ $date->format('d/m/Y') }}">
<div data-pickup-agenda data-pickup-view="day">
    <nav class="page-back" aria-label="Navigazione agenda"><x-ui.icon-button action="back" :href="route('pickups.index', ['month' => $date->format('Y-m')]).'#pickup-day-'.$date->toDateString()" label="Torna al calendario" text /></nav>
    <header class="page-heading"><div><span class="eyebrow">EA-Express · RESOCONTO GIORNALIERO</span><h1>{{ ucfirst($date->translatedFormat('l j F Y')) }}</h1><p>Apri un account per consultare i ritiri della giornata, 20 ordini per pagina.</p></div><button type="button" class="btn btn-outline-secondary" data-pickup-refresh><x-ui.icon name="arrow-clockwise" /> Aggiorna agenda</button></header>
    @include('pickups.accounts')
</div>
</x-app-layout>
