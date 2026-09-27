<x-app-layout title="Checkout ritiro">
    <header class="mb-4"><span class="eyebrow">NUOVO RITIRO</span><h1>Checkout ritiro</h1><p class="text-secondary">Verifica i dati e la tariffa prima di confermare la richiesta.</p></header>
    <section class="surface p-4 mb-4">
        <h2 class="h5">Riepilogo spedizione</h2>
        <dl class="operation-facts">
            <div><dt>Mittente</dt><dd>{{ $review['data']['store_name'] }}</dd></div>
            <div><dt>Ritiro</dt><dd>{{ $review['data']['pickup_address'] }} {{ $review['data']['pickup_street_number'] }}, {{ $review['data']['pickup_postal_code'] }} {{ $review['data']['pickup_city'] }}</dd></div>
            <div><dt>Destinatario</dt><dd>{{ $review['data']['recipient_name'] }} · {{ $review['data']['recipient_phone'] }}</dd></div>
            <div><dt>Consegna</dt><dd>{{ $review['data']['delivery_address'] }} {{ $review['data']['delivery_street_number'] }}, {{ $review['data']['delivery_postal_code'] }} {{ $review['data']['delivery_city'] }}</dd></div>
            <div><dt>Data e fascia ritiro</dt><dd>{{ \Illuminate\Support\Carbon::parse($review['data']['pickup_date'])->format('d/m/Y') }} · {{ $review['data']['pickup_from'] }}–{{ $review['data']['pickup_to'] }}</dd></div>
            <div><dt>Pacchi</dt><dd>{{ $review['data']['parcel_count'] }} · {{ \App\Support\OrderContent::label($review['data']['category'], $review['data']['content_description'] ?? null) }}</dd></div>
            <div><dt>Valore dichiarato della merce</dt><dd>{{ \App\Support\Money::format($review['parcel_value_cents']) }}</dd></div>
            <div><dt>Tariffa spedizione</dt><dd>{{ \App\Support\Money::format($review['shipping_price_cents']) }}</dd></div>
            <div><dt>Merce e spedizione</dt><dd>{{ \App\Support\Money::format($review['total_cents']) }}</dd></div>
        </dl>
        <p class="small text-secondary">Il valore della merce è distinto dalla tariffa e non rappresenta un incasso del servizio.</p>
        @if($review['quote']['reason'])<p role="status">{{ $review['quote']['reason'] }}</p>@endif
    </section>
    <div class="d-flex flex-wrap gap-3">
        <form method="post" action="{{ route('orders.checkout.edit') }}">@csrf
            @foreach($review['data'] as $name => $value)
                @if(!is_array($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@else
                    @foreach($value as $index => $item)@foreach($item as $field => $number)<input type="hidden" name="{{ $name }}[{{ $index }}][{{ $field }}]" value="{{ $number }}">@endforeach @endforeach
                @endif
            @endforeach
            <button class="btn btn-outline-secondary" type="submit">Modifica dati</button>
        </form>
        @if($review['checkout_token'])
        <form method="post" action="{{ route('orders.store') }}">@csrf
            @foreach($review['data'] as $name => $value)
                @if(!is_array($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@else
                    @foreach($value as $index => $item)@foreach($item as $field => $number)<input type="hidden" name="{{ $name }}[{{ $index }}][{{ $field }}]" value="{{ $number }}">@endforeach @endforeach
                @endif
            @endforeach
            <input type="hidden" name="checkout_token" value="{{ $review['checkout_token'] }}">
            <button class="btn btn-primary" type="submit">Conferma e crea ritiro</button>
        </form>
        @endif
    </div>
</x-app-layout>
