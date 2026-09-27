<x-app-layout title="Listini">
    <header class="page-heading"><div><span class="eyebrow">RETE DI CONSEGNA</span><h1>Listini</h1><p>Località, tempi e costi. Un riferimento chiaro per ogni spedizione.</p></div>@if(auth()->user()->role === \App\UserRole::Admin)<x-ui.icon-button icon="plus-lg" label="Aggiungi tariffa" variant="primary" data-rate-create/>@endif</header>
    <x-ui.filters :reset="route('rates.index')" data-rates-filter>
        <x-ui.field name="q" label="Cerca località" :value="request('q')" placeholder="Città, CAP o zona"/>
        <div><label class="form-label" for="area">Area</label><select id="area" name="area" class="form-select"><option value="">Tutte le aree</option>@foreach($areas as $area)<option @selected(request('area')===$area)>{{ $area }}</option>@endforeach</select></div>
        @if(auth()->user()->role === \App\UserRole::Admin)<div><label class="form-label" for="state">Stato</label><select id="state" name="state" class="form-select">@foreach(['active'=>'Attive','inactive'=>'Disattivate','all'=>'Tutte le correnti','archived'=>'Archiviate'] as $value=>$label)<option value="{{ $value }}" @selected(request('state','active')===$value)>{{ $label }}</option>@endforeach</select></div>@endif

    </x-ui.filters>
    <p class="operation-feedback" role="status" data-rate-feedback></p>
    <div id="rates-results">
        <p class="data-caption">{{ $rates->total() }} tariffe · {{ $rates->firstItem() ?? 0 }}–{{ $rates->lastItem() ?? 0 }} visualizzate</p>
        <div class="rate-grid">
        @forelse($rates as $rate)
            <article @class(['surface','rate-card','is-inactive'=>!$rate->active]) data-rate="{{ json_encode($rate) }}">
                <header><span class="eyebrow">{{ $rate->area ?: 'Località' }}</span><span class="status-badge">{{ $rate->archived_at ? 'Archiviata' : ($rate->active ? 'Attiva' : 'Disattivata') }}</span></header>
                <h2>{{ $rate->city }}</h2><p class="small">{{ $rate->shipping_type === 'external' ? 'Fuori regione'.($rate->carrier_name ? ' · '.$rate->carrier_name : '') : 'Regionale' }}</p>@if($rate->shipping_type === 'external')<p class="small">Costo vettore: {{ \App\Support\Money::format($rate->carrier_cost_cents) }} · Margine: {{ \App\Support\Money::format($rate->price_cents - $rate->carrier_cost_cents) }}</p>@endif<p class="rate-location">{{ collect([$rate->zone, $rate->postal_code ? 'CAP '.$rate->postal_code : null, $rate->street])->filter()->join(' · ') ?: 'Intera località' }}</p>
                <div class="rate-price"><span>Costo spedizione</span><strong>{{ \App\Support\Money::format($rate->price_cents) }}</strong></div>
                <p class="rate-time"><x-ui.icon name="clock"/> {{ $rate->delivery_time ?: 'Tempi da confermare' }}</p>
                @if(auth()->user()->role === \App\UserRole::Admin)
                <footer class="card-toolbar">
                    @if(!$rate->archived_at)
                    <button type="button" class="state-switch" role="switch" aria-checked="{{ $rate->active ? 'true' : 'false' }}" aria-label="Disponibilità tariffa {{ $rate->city }}" data-rate-toggle data-tooltip="{{ $rate->active ? 'Disattiva tariffa' : 'Attiva tariffa' }}"><span></span></button>
                    <div class="actions"><x-ui.icon-button icon="pencil" label="Modifica tariffa" data-rate-edit/><x-ui.icon-button icon="trash" label="Archivia tariffa" variant="danger" data-rate-archive/></div>
                    @endif
                    <x-ui.icon-button icon="clock-history" label="Storico tariffa" data-rate-history/>
                </footer>
                @endif
            </article>
        @empty<div class="surface empty-state"><x-ui.icon name="geo-alt"/><h2>Nessuna tariffa trovata</h2><p>Modifica la ricerca o aggiungi una nuova località.</p></div>@endforelse
        </div>
        {{ $rates->links() }}
    </div>
    @if(auth()->user()->role === \App\UserRole::Admin)
    <x-ui.modal id="rate-editor" title="Nuova tariffa" description="Definisci dove consegniamo e il costo della spedizione.">
        <form data-rate-form class="form-stack">
            <div class="modal-content-area field-grid">
                <div><label class="form-label" for="rate-type">Servizio</label><select class="form-select" id="rate-type" name="shipping_type"><option value="regional">Regionale</option><option value="external">Fuori regione</option></select></div>
                <x-ui.field name="carrier_name" label="Vettore (fuori regione)" maxlength="100"/>
                <x-ui.field name="carrier_cost" label="Costo vettore previsto (€, facoltativo)" inputmode="decimal" pattern="[0-9]{1,6}([.,][0-9]{1,2})?"/>
                <x-ui.field name="max_weight_kg" label="Peso totale massimo (kg, vuoto = nessun limite)" type="number" min="0.01" max="10000" step="0.01"/>
                <x-ui.field name="max_dimension_cm" label="Lato massimo per collo (cm, vuoto = nessun limite)" type="number" min="1" max="500" step="0.01"/>
                <x-ui.field name="delivery_days_min" label="Giorni lavorativi minimi dal ritiro" type="number" min="1" max="365"/>
                <x-ui.field name="delivery_days_max" label="Giorni lavorativi massimi dal ritiro" type="number" min="1" max="365"/>
                <x-ui.field name="city" id="rate-city" label="Località" maxlength="100" required/>
                <x-ui.field name="area" id="rate-area" label="Area" maxlength="100"/>
                <x-ui.field name="postal_code" id="rate-postal" label="CAP (facoltativo)" pattern="[0-9]{5}" maxlength="5" inputmode="numeric"/>
                <x-ui.field name="zone" id="rate-zone" label="Zona (facoltativa)" maxlength="100"/>
                <x-ui.field name="street" id="rate-street" label="Via (facoltativa)" maxlength="255"/>
                <x-ui.field name="price" id="rate-price" label="Costo spedizione (€)" inputmode="decimal" pattern="[0-9]{1,6}([.,][0-9]{1,2})?" required/>
                <x-ui.field name="delivery_time" id="rate-time" label="Tempi di consegna" maxlength="100"/>
                <x-ui.field name="source_reference" id="rate-source" label="Riferimento della tariffa" maxlength="255" required/>
                <label class="switch-label"><input type="checkbox" name="active" checked/> Disponibile per nuove spedizioni</label>
            </div>
            <p class="modal-feedback" role="alert" data-modal-error></p>
            <footer class="modal-actions"><button type="button" class="btn modal-back" data-dialog-close><x-ui.icon name="arrow-left"/> Torna indietro</button><x-ui.icon-button action="add" label="Aggiungi tariffa" type="submit" data-rate-save text/></footer>
        </form>
    </x-ui.modal>
    <x-ui.modal id="rate-history" title="Storico tariffa" description="Versioni conservate per ricostruire le variazioni."><div class="modal-content-area" data-history-content></div><footer class="modal-actions"><button type="button" class="btn modal-back" data-dialog-close><x-ui.icon name="arrow-left"/> Torna indietro</button></footer></x-ui.modal>
    @endif
</x-app-layout>
