<x-app-layout title="Storico operazioni">
    <nav class="page-back" aria-label="Navigazione pagina"><x-ui.icon-button action="back" :href="route('balance.index')" label="Torna indietro" text /></nav><header class="page-heading"><div><span class="eyebrow d-block mt-3">TRACCIABILITÀ CONTABILE</span><h1>Storico operazioni</h1><p>{{ $entityLabel }} #{{ request('id') }} · {{ $entries->total() }} eventi registrati</p></div></header>
    <p class="data-caption">Eventi salvati nel database, dal più recente. Le operazioni storiche prive di audit non vengono ricostruite artificialmente.</p>
    <ol class="audit-timeline">
        @forelse($entries as $entry)
            @php($event = $presenter->present($entry))
            <li><span class="audit-marker"><x-ui.icon :name="$event['icon']" /></span><article class="surface audit-event">
                <header class="section-heading"><div><h2>{{ $event['title'] }}</h2><p class="small text-secondary mb-0">{{ $entry->user?->name ?? 'Sistema' }} · <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->timezone('Europe/Rome')->format('d/m/Y H:i:s') }}</time></p></div><span class="count-badge">Evento #{{ $entry->id }}</span></header>
                <dl class="audit-changes">@foreach($event['changes'] as $change)<div><dt>{{ $change['label'] }}</dt><dd>@if($change['before'] !== null)<span class="audit-before">{{ $change['before'] }}</span><x-ui.icon name="arrow-right" /><span class="visually-hidden">diventa</span>@endif<strong>{{ $change['after'] }}</strong></dd></div>@endforeach</dl>
                @if(!$event['changes'])<p class="small text-secondary mb-0">Operazione registrata senza ulteriori variazioni nei campi visualizzati.</p>@endif
            </article></li>
        @empty<li class="surface p-4">Nessun evento disponibile per questa registrazione.</li>@endforelse
    </ol>
    {{ $entries->links() }}
</x-app-layout>
