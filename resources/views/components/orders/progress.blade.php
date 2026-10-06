@props(['progress'])
<section {{ $attributes->merge(['class' => 'surface shipment-progress']) }} aria-labelledby="order-progress-title" aria-live="polite">
    <header class="section-heading"><div><span class="eyebrow">AVANZAMENTO SPEDIZIONE</span><h2 id="order-progress-title">Il percorso dell’ordine</h2></div><span class="progress-caption">Solo passaggi registrati</span></header>
    @if($progress['pricing'])<p class="progress-pricing"><x-ui.icon name="currency-euro" /> {{ $progress['pricing'] }} · la proposta economica non conferma la presa in carico.</p>@endif
    @if($progress['exception'])<div class="progress-exception" role="status"><strong>{{ $progress['exception']['label'] }}</strong>@if($progress['exception']['at'])<time datetime="{{ $progress['exception']['at']->toIso8601String() }}">{{ $progress['exception']['at']->timezone('Europe/Rome')->format('d/m/Y H:i') }}</time>@endif<p>{{ $progress['exception']['note'] ?: 'Consulta le azioni operative e la cronologia per i dettagli.' }}</p>@if($progress['exception']['last'])<small>Ultimo passaggio registrato: {{ $progress['exception']['last'] }}</small>@endif</div>@endif
    <ol class="progress-stages">
        @foreach($progress['steps'] as $step)
            <li class="progress-stage" data-progress-status="{{ $step['status'] }}" data-progress-state="{{ $step['state'] }}" @if($step['state']==='current') aria-current="step" @endif>
                <span class="progress-dot" aria-hidden="true">@if($step['state']==='complete')<x-ui.icon name="check-lg" />@else{{ $loop->iteration }}@endif</span>
                <div><strong>{{ $step['label'] }}</strong><span>{{ $step['state']==='current' ? 'Fase corrente' : ($step['state']==='complete' ? 'Registrato' : ($step['optional'] ? 'Se previsto dal percorso' : 'Da registrare')) }}</span>@if($step['at'])<time datetime="{{ $step['at']->toIso8601String() }}">{{ $step['at']->timezone('Europe/Rome')->format('d/m H:i') }}</time>@endif @if($step['state']==='current')<small>{{ $step['description'] }}</small>@endif</div>
            </li>
        @endforeach
    </ol>
</section>
