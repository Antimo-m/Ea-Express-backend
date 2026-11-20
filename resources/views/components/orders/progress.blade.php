@props(['progress', 'from' => null, 'to' => null, 'fallbackNote' => 'Consulta le azioni operative e la cronologia per i dettagli.'])
@php
    $steps = $progress['steps'];
    $stopCount = count($steps);
    $exception = $progress['exception'];
    $position = $progress['position'];
    $journeyState = $progress['state'];
    $terminal = $journeyState === 'stopped';
    $headline = $exception['label'] ?? $steps[$position]['label'];
@endphp
{{-- Il camion si posiziona solo in base allo stato registrato: nessun avanzamento a tempo, nessuna posizione geografica. --}}
<section {{ $attributes->merge(['class' => 'surface shipment-progress journey']) }} aria-labelledby="order-progress-title" aria-live="polite"
    data-journey data-journey-position="{{ $position }}" data-journey-state="{{ $journeyState }}" style="--journey-stops: {{ $stopCount }}; --journey-at: {{ $position }};">
    <header class="journey-heading">
        <div><span class="eyebrow">AVANZAMENTO SPEDIZIONE</span><h2 id="order-progress-title">Il percorso dell’ordine</h2></div>
        <p class="journey-now"><span class="journey-now-label">{{ $exception ? 'Spedizione interrotta' : ($journeyState === 'arrived' ? 'Spedizione completata' : 'Fase attuale') }}</span><strong>{{ $headline }}</strong><span class="journey-now-count">Tappa {{ $position + 1 }} di {{ $stopCount }} · solo passaggi registrati</span></p>
    </header>
    <div class="journey-scene" aria-hidden="true">
        <div class="journey-road">
            <span class="journey-road-fill"></span>
            @if($from)<span class="journey-place journey-place--from"><x-ui.icon name="circle-fill" /> {{ $from }}</span>@endif
            @if($to)<span class="journey-place journey-place--to">{{ $to }} <x-ui.icon name="geo-alt-fill" /></span>@endif
            @foreach($steps as $step)
                <span @class(['journey-stop', 'is-'.$step['state'], 'is-destination' => $loop->last]) style="--stop: {{ $loop->index }};">@if($loop->last)<x-ui.icon name="flag-fill" />@endif</span>
            @endforeach
            <span class="journey-vehicle">
                @if($exception)<span @class(['journey-signal', 'is-stopped' => $terminal])><x-ui.icon :name="$terminal ? 'x-lg' : 'exclamation-lg'" /></span>@endif
                <svg class="journey-truck" viewBox="0 0 112 60" focusable="false">
                    <ellipse class="journey-truck-shadow" cx="56" cy="55" rx="46" ry="3.6" />
                    <rect class="journey-truck-box" x="4" y="6" width="66" height="36" rx="6" />
                    <rect class="journey-truck-stripe" x="4" y="30" width="66" height="5" />
                    <text class="journey-truck-mark" x="37" y="24" text-anchor="middle">EA</text>
                    <path class="journey-truck-cab" d="M72 16h17.5a6 6 0 0 1 5.2 3l6.6 11.4a6 6 0 0 1 .8 3V42H72z" />
                    <path class="journey-truck-glass" d="M77 20h11.4a3 3 0 0 1 2.6 1.5l4.4 7.5H77z" />
                    <rect class="journey-truck-light" x="98" y="34" width="4" height="3.4" rx="1.2" />
                    <rect class="journey-truck-chassis" x="2" y="41" width="102" height="4.5" rx="2.2" />
                    <g class="journey-wheel" style="transform-origin: 24px 46px;"><circle cx="24" cy="46" r="8.5" class="journey-tyre" /><circle cx="24" cy="46" r="4" class="journey-rim" /><path d="M24 39.5v13M17.5 46h13" class="journey-spoke" /></g>
                    <g class="journey-wheel" style="transform-origin: 84px 46px;"><circle cx="84" cy="46" r="8.5" class="journey-tyre" /><circle cx="84" cy="46" r="4" class="journey-rim" /><path d="M84 39.5v13M77.5 46h13" class="journey-spoke" /></g>
                </svg>
            </span>
        </div>
    </div>
    @if($progress['pricing'])<p class="progress-pricing"><x-ui.icon name="currency-euro" /> {{ $progress['pricing'] }} · la proposta economica non conferma la presa in carico.</p>@endif
    @if($exception)<div @class(['progress-exception', 'is-stopped' => $terminal]) role="status"><strong>{{ $exception['label'] }}</strong>@if($exception['at'])<time datetime="{{ $exception['at']->toIso8601String() }}">{{ $exception['at']->timezone('Europe/Rome')->format('d/m/Y H:i') }}</time>@endif<p>{{ $exception['note'] ?: $fallbackNote }}</p>@if($exception['last'])<small>Ultimo passaggio registrato: {{ $exception['last'] }}</small>@endif</div>@endif
    <ol class="progress-stages">
        @foreach($steps as $step)
            <li class="progress-stage" data-progress-status="{{ $step['status'] }}" data-progress-state="{{ $step['state'] }}" @if($step['state']==='current') aria-current="step" @endif>
                <span class="progress-dot" aria-hidden="true">@if($step['state']==='complete')<x-ui.icon name="check-lg" />@else{{ $loop->iteration }}@endif</span>
                <div><strong>{{ $step['label'] }}</strong><span>{{ $step['state']==='current' ? 'Fase corrente' : ($step['state']==='complete' ? 'Registrato' : ($step['optional'] ? 'Se previsto dal percorso' : 'Da registrare')) }}</span>@if($step['at'])<time datetime="{{ $step['at']->toIso8601String() }}">{{ $step['at']->timezone('Europe/Rome')->format('d/m H:i') }}</time>@endif @if($step['state']==='current')<small>{{ $step['description'] }}</small>@endif</div>
            </li>
        @endforeach
    </ol>
</section>
