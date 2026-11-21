@props(['counts', 'personal' => false, 'date' => null])
{{-- Avanzamento della giornata: si riempie a ogni aggiornamento in tempo reale della regione. --}}
<section {{ $attributes->class(['surface', 'day-progress']) }} data-live-region="day-progress" aria-labelledby="day-progress-title">
    <div class="day-progress-heading">
        <div><span class="day-progress-kicker"><span class="eyebrow">{{ $personal ? 'LA TUA GIORNATA' : 'FINE GIORNATA' }}</span>@if($date)<time class="day-progress-date" datetime="{{ now('Europe/Rome')->toDateString() }}"><x-ui.icon name="calendar3" />{{ $date }}</time>@endif</span><h2 id="day-progress-title">Oggi <strong>{{ $counts['completed'] }}</strong> {{ $counts['completed'] === 1 ? 'consegna completata' : 'consegne completate' }}</h2></div>
        <span class="day-progress-percent">@if($counts['valid']){{ $counts['progress'] }}<small>%</small>@else<small>Nessun ritiro in programma</small>@endif</span>
    </div>
    <div class="day-progress-track" role="progressbar" aria-label="Consegne completate tra i ritiri programmati oggi" aria-valuemin="0" aria-valuemax="{{ max(1, $counts['valid']) }}" aria-valuenow="{{ $counts['completed'] }}"><span class="day-progress-fill" data-progress-key="day" style="--value: {{ $counts['progress'] }}%"></span></div>
    <dl class="day-progress-facts">
        <div><dt>In programma oggi</dt><dd>{{ $counts['valid'] }}</dd></div>
        <div><dt>Da effettuare</dt><dd>{{ $counts['todo'] }}</dd></div>
        <div><dt>In corso</dt><dd>{{ $counts['active'] }}</dd></div>
        <div><dt>Da verificare</dt><dd>{{ $counts['issues'] }}</dd></div>
    </dl>
    <p class="day-progress-note">{{ $personal ? 'Ritiri programmati per oggi e affidati a te' : 'Ritiri programmati per oggi' }}, esclusi annullati e rifiutati. Una consegna conclusa oggi per un ritiro di un altro giorno resta nella sua giornata.</p>
</section>
