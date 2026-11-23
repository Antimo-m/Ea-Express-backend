@php
    $data = $review['data'];
    $quote = $review['quote'] ?? [];
    $street = fn (?string $address, ?string $number): string => trim(($address ?? '').' '.($number ?? ''));
    $senderType = ['private' => 'Privato', 'business' => 'Attività commerciale', 'online_shop' => 'Shop online'][$data['sender_type'] ?? ''] ?? null;
    $packageLabel = match ($data['package_type'] ?? 'standard') { 'fragile' => 'Fragile', 'other' => $data['package_description'] ?? 'Altro', default => 'Standard' };
    $deliveryEstimate = ($quote['delivery_time'] ?? null) ?: (! empty($quote['delivery_days_min']) ? $quote['delivery_days_min'].'–'.$quote['delivery_days_max'].' giorni lavorativi dal ritiro' : null);
    $notes = $data['notes'] ?? null;
@endphp
<x-app-layout title="Checkout ritiro">
    <header class="page-heading mb-4"><div><span class="eyebrow">NUOVO RITIRO</span><h1>Checkout ritiro</h1><p class="text-secondary">Verifica i dati e la tariffa prima di confermare la richiesta.</p></div></header>
    <section class="checkout-review checkout-v2" aria-labelledby="checkout-heading">
        <header class="checkout-heading">
            <span class="checkout-seal" aria-hidden="true"><x-ui.icon name="box-seam" /></span>
            <div><p class="eyebrow">ULTIMO PASSAGGIO</p><h2 id="checkout-heading">Tutto pronto per partire.</h2><p>Controlla i dettagli e conferma il ritiro.</p></div>
            <span class="checkout-verified"><x-ui.icon name="shield-check" /> Riepilogo verificato</span>
        </header>
        <div class="checkout-layout">
            <div class="checkout-details">
                <section class="checkout-section" aria-labelledby="checkout-route-heading">
                    <div class="checkout-section-title"><span>01</span><h3 id="checkout-route-heading">Mittente e destinatario</h3></div>
                    <div class="checkout-addresses">
                        <article class="checkout-address" aria-labelledby="checkout-sender-heading">
                            <h4 class="checkout-caption" id="checkout-sender-heading"><x-ui.icon name="box-arrow-up" /> Mittente</h4>
                            <x-orders.key-values :items="['Nome mittente' => $data['store_name'] ?? null, 'Tipologia' => $senderType, 'Indirizzo mittente' => $street($data['pickup_address'] ?? null, $data['pickup_street_number'] ?? null), 'CAP' => $data['pickup_postal_code'] ?? null, 'Città' => $data['pickup_city'] ?? null]" />
                        </article>
                        <span class="checkout-route-arrow" aria-hidden="true"><x-ui.icon name="arrow-right" /></span>
                        <article class="checkout-address" aria-labelledby="checkout-recipient-heading">
                            <h4 class="checkout-caption" id="checkout-recipient-heading"><x-ui.icon name="geo-alt" /> Destinatario</h4>
                            <x-orders.key-values :items="['Nome destinatario' => $data['recipient_name'] ?? null, 'Indirizzo destinatario' => $street($data['delivery_address'] ?? null, $data['delivery_street_number'] ?? null), 'CAP' => $data['delivery_postal_code'] ?? null, 'Città' => $data['delivery_city'] ?? null, 'Zona' => $data['delivery_zone'] ?? null, 'Provincia' => $data['delivery_province'] ?? null, 'Regione' => $data['delivery_region'] ?? null, 'Telefono destinatario' => $data['recipient_phone'] ?? null]" />
                        </article>
                    </div>
                </section>
                <section class="checkout-section" aria-labelledby="checkout-schedule-heading">
                    <div class="checkout-section-title"><span>02</span><h3 id="checkout-schedule-heading">Il ritiro</h3></div>
                    <x-orders.key-values class="checkout-facts" :items="['Data del ritiro' => \Illuminate\Support\Carbon::parse($data['pickup_date'])->format('d/m/Y'), 'Fascia oraria' => 'Dalle '.$data['pickup_from'].' alle '.$data['pickup_to'], 'Servizio' => (($data['shipping_type'] ?? 'regional') === 'external' ? 'Fuori regione' : 'Regionale').' · EA-Express', 'Consegna prevista' => $deliveryEstimate]" />
                </section>
                <section class="checkout-section" aria-labelledby="checkout-package-heading">
                    <div class="checkout-section-title"><span>03</span><h3 id="checkout-package-heading">Cosa spediamo</h3></div>
                    <x-orders.key-values class="checkout-facts" :items="['Numero colli' => $data['parcel_count'], 'Caratteristiche' => $packageLabel, 'Contenuto' => \App\Support\OrderContent::label($data['category'], $data['content_description'] ?? null), 'Priorità' => ($data['urgency'] ?? 'standard') === 'urgent' ? 'Urgente' : 'Standard']" />
                    @if($notes)<div class="checkout-instructions"><x-ui.icon name="chat-left-text" /><div><strong>Indicazioni per il rider</strong><p>{{ $notes }}</p></div></div>@endif
                </section>
            </div>
            <aside class="checkout-summary" aria-labelledby="checkout-summary-heading">
                <p class="eyebrow">LA RICHIESTA</p><h3 id="checkout-summary-heading">Riepilogo economico</h3>
                <dl class="checkout-amounts">
                    <div><dt>Valore dichiarato del pacco</dt><dd>{{ \App\Support\Money::format($review['parcel_value_cents']) }}</dd></div>
                    <div><dt>Tariffa di spedizione</dt><dd>{{ \App\Support\Money::format($review['shipping_price_cents']) }}</dd></div>
                    <div class="checkout-total"><dt>Totale finale</dt><dd>{{ \App\Support\Money::format($review['total_cents']) }}</dd></div>
                </dl>
                <p class="checkout-total-note">Il totale comprende valore dichiarato e spedizione. Il valore della merce è distinto dalla tariffa e non rappresenta un incasso del servizio.</p>
                <div class="checkout-assurance"><x-ui.icon name="shield-check" /><p>La tariffa viene verificata nuovamente alla conferma. Nessun importo può essere modificato da questa pagina.</p></div>
                @if($quote['reason'] ?? null)<p class="checkout-rate-note" role="status">{{ $quote['reason'] }}</p>@endif
                @unless($review['checkout_token'])<p class="alert alert-danger checkout-unavailable" role="alert">La tariffa non è disponibile. Torna ai dati e controlla la destinazione oppure il listino.</p>@endunless
                <footer class="checkout-actions">
                    <form method="post" action="{{ route('orders.checkout.edit') }}">@csrf
                        <x-orders.checkout-fields :data="$data" />
                        <button class="btn button-back modal-back-icon icon-button action-back" type="submit" aria-label="Torna indietro" data-tooltip="Torna indietro"><x-ui.icon name="arrow-left" /></button>
                    </form>
                    @if($review['checkout_token'])
                    <form method="post" action="{{ route('orders.store') }}" data-prevent-repeat>@csrf
                        <x-orders.checkout-fields :data="$data" />
                        <input type="hidden" name="checkout_token" value="{{ $review['checkout_token'] }}">
                        <button type="submit" class="btn checkout-continue" aria-label="Conferma e crea ritiro" title="Conferma e crea ritiro" data-tooltip="Conferma e crea ritiro"><x-ui.icon name="check2" /></button>
                    </form>
                    @endif
                </footer>
            </aside>
        </div>
    </section>
</x-app-layout>
