@props(['points', 'kind' => 'orders', 'id', 'title', 'description'])
@php
    $cash = $kind === 'cash';
    $max = max(1, ...array_map(fn ($point) => $cash ? max($point['incoming'], $point['outgoing']) : $point['orders'], $points));
    $step = 840 / max(count($points), 1);
@endphp
<section class="surface workspace-section trend-panel" data-trend>
    <header class="section-heading"><div><span class="eyebrow">{{ $cash ? 'FLUSSI DI CASSA' : 'VOLUME DI LAVORO' }}</span><h2>{{ $title }}</h2></div><x-ui.icon :name="$cash ? 'cash-stack' : 'bar-chart-line'" /></header>
    <p class="small text-secondary">{{ $description }}</p>
    @if($cash)<div class="chart-legend" aria-label="Serie del grafico"><button type="button" data-chart-series="incoming" aria-pressed="true"><span class="legend-dot incoming"></span>Entrate</button><button type="button" data-chart-series="outgoing" aria-pressed="true"><span class="legend-dot outgoing"></span>Uscite e storni</button></div>@endif
    <div class="chart-canvas">
        <svg viewBox="0 0 920 260" role="group" aria-labelledby="{{ $id }}-title {{ $id }}-description">
            <title id="{{ $id }}-title">{{ $title }}</title><desc id="{{ $id }}-description">{{ $description }} Usa il cursore Giorno per esplorare tutti i valori.</desc>
            @foreach([0, 0.5, 1] as $fraction)
                <line x1="60" y1="{{ 210 - $fraction * 175 }}" x2="900" y2="{{ 210 - $fraction * 175 }}" class="chart-gridline" />
                <text x="50" y="{{ 214 - $fraction * 175 }}" text-anchor="end" class="chart-axis">{{ $cash ? number_format($max * $fraction / 100, 0, ',', '.') . ' €' : (string) (int) ceil($max * $fraction) }}</text>
            @endforeach
            @foreach($points as $index => $point)
                @php($x = 60 + $index * $step)
                <g class="chart-point" data-chart-point="{{ $index }}" data-label="{{ $point['label'] }}" data-orders="{{ $point['orders'] }}" data-incoming="{{ $point['incoming'] }}" data-outgoing="{{ $point['outgoing'] }}" tabindex="{{ $index === 0 ? 0 : -1 }}" role="button" aria-label="{{ $point['label'] }}: {{ $cash ? 'entrate '.\App\Support\Money::format($point['incoming']).', uscite '.\App\Support\Money::format($point['outgoing']) : $point['orders'].' richieste' }}">
                    <rect x="{{ $x }}" y="20" width="{{ $step }}" height="195" class="chart-hit" />
                    @if($cash)
                        <rect x="{{ $x + $step * .16 }}" y="{{ 210 - $point['incoming'] / $max * 175 }}" width="{{ $step * .3 }}" height="{{ $point['incoming'] / $max * 175 }}" rx="3" class="chart-bar incoming" data-series="incoming" />
                        <rect x="{{ $x + $step * .54 }}" y="{{ 210 - $point['outgoing'] / $max * 175 }}" width="{{ $step * .3 }}" height="{{ $point['outgoing'] / $max * 175 }}" rx="3" class="chart-bar outgoing" data-series="outgoing" />
                    @else
                        <rect x="{{ $x + $step * .2 }}" y="{{ 210 - $point['orders'] / $max * 175 }}" width="{{ $step * .6 }}" height="{{ $point['orders'] / $max * 175 }}" rx="3" class="chart-bar orders" />
                    @endif
                    @if($index % 5 === 0 || $loop->last)<text x="{{ $x + $step / 2 }}" y="240" text-anchor="middle" class="chart-axis">{{ $point['label'] }}</text>@endif
                </g>
            @endforeach
        </svg>
    </div>
    <div class="chart-inspector"><label for="{{ $id }}-day">Giorno</label><input id="{{ $id }}-day" type="range" min="0" max="{{ max(count($points)-1, 0) }}" value="0" step="1" data-chart-range><output for="{{ $id }}-day" data-chart-output data-kind="{{ $kind }}" aria-live="polite">Seleziona un giorno per leggere i valori.</output></div>
    @if($max === 1 && collect($points)->sum($cash ? 'incoming' : 'orders') === 0 && (!$cash || collect($points)->sum('outgoing') === 0))<p class="small text-secondary mb-0">Nessun dato nel periodo selezionato.</p>@endif
</section>
