<article class="surface rider-operational-card" data-rider-state="{{ $rider['state'] }}">
    <header><h2 class="h5">{{ $rider['name'] }}</h2><span class="rider-state">{{ $rider['label'] }}</span></header>
    <div class="rider-zone-chips">@foreach($rider['zones'] as $zone)<span class="status-badge">{{ $zone['name'] }} · {{ $zone['count'] }}</span>@endforeach</div>
    <p>{{ $rider['delivered_count'] }} / {{ $rider['total_count'] }} consegnate · {{ $rider['active_count'] }} in corso</p>
    <progress max="{{ max(1,$rider['total_count']) }}" value="{{ $rider['delivered_count'] }}" aria-label="Consegne completate"></progress>
    <dl><div><dt>Incassato netto</dt><dd>{{ \App\Support\Money::format($rider['cash_cents']) }}</dd></div><div><dt>Tariffe previste</dt><dd>{{ \App\Support\Money::format($rider['expected_cents']) }}</dd></div></dl>
    @if($rider['missing_prices'])<small>{{ $rider['missing_prices'] }} tariffe ancora da definire.</small>@endif
    <footer><small>{{ $rider['last_gps'] ? 'Ultimo GPS: '.\Illuminate\Support\Carbon::parse($rider['last_gps'])->timezone('Europe/Rome')->format('H:i:s') : 'GPS non disponibile' }}</small><div class="row-actions"><x-ui.icon-button icon="person" :label="'Attività di '.$rider['name']" :href="$rider['url']"/><x-ui.icon-button icon="geo-alt" :label="'Posizione di '.$rider['name']" :data-map-rider="$rider['id']" :disabled="$rider['state'] !== 'live'"/></div></footer>
</article>
