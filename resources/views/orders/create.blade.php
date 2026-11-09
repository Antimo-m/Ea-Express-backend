<x-app-layout title="Nuova richiesta">
    <header class="mb-4"><span class="eyebrow">ORDINI</span><h1>Nuova richiesta</h1><p class="text-secondary">Inserisci la spedizione per conto del negozio o del cliente.</p></header>
    <nav class="form-progress mb-3" aria-label="Avanzamento prenotazione"><strong>1. Dati del ritiro</strong><span aria-hidden="true"> → </span><span>2. Riepilogo e conferma</span></nav>
    <form data-booking-rules="{{ json_encode(app(\App\Support\BookingRules::class)->payload()) }}" data-content-categories="{{ json_encode(\App\Support\OrderContent::Categories) }}" method="post" action="{{ route('orders.store') }}" class="form-stack staff-booking-form booking-form">@csrf
        <section class="surface form-section booking-step">
            <h2><span class="booking-step-number">01</span> Punto di ritiro</h2>
            <fieldset class="sender-choice">
                <legend>Tipo mittente <span class="required-indicator" aria-hidden="true">*</span></legend>
                <label><input type="radio" name="sender_type" value="private" required @checked(old('sender_type') === 'private')> Privato</label>
                <label><input type="radio" name="sender_type" value="business" required @checked(old('sender_type', 'business') === 'business')> Attività commerciale</label>
                <label><input type="radio" name="sender_type" value="online_shop" required @checked(old('sender_type') === 'online_shop')> Shop online</label>
            </fieldset>
            <div class="field field-account">
                <label for="customer_id" class="form-label">Account cliente (facoltativo)</label>
                <select class="form-select" name="customer_id" id="customer_id">
                    <option value="">Nessun account collegato</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->slug }}" data-identity="{{ json_encode($customer->only(['name', 'email', 'sender_type', 'business_type', 'business_description'])) }}" @selected(old('customer_id') == $customer->slug)>{{ $customer->name }} · {{ $customer->email }}</option>
                    @endforeach
                </select>
            </div>
            <div class="booking-grid booking-sender-contact">
                <x-ui.field width="name" name="store_name" :label="old('sender_type') === 'private' ? 'Nome e cognome del mittente' : 'Nome attività'" :value="old('store_name')" maxlength="150" required />
                <x-ui.field width="email" name="contact_email" label="Email mittente (facoltativa)" type="email" :value="old('contact_email')" />
            </div>
            <div class="booking-grid booking-business identity-business" data-business-fields @if(old('sender_type') === 'private') hidden @endif>
                <x-ui.field width="business" name="business_type" label="Tipologia attività" :value="old('business_type')" maxlength="100" list="business-types" placeholder="Scegli o scrivi liberamente"/>
                <datalist id="business-types"><option value="Abbigliamento"><option value="Fiorista"><option value="Artigianato"><option value="E-commerce"><option value="Alimentari"></datalist>
                <x-ui.field width="description" name="business_description" label="Di cosa si occupa? (facoltativo)" :value="old('business_description')" maxlength="500" />
            </div>
            <div class="booking-grid booking-address">
                <x-ui.field width="address" name="pickup_address" label="Indirizzo di ritiro" :value="old('pickup_address')" required/>
                <x-ui.field width="civic" name="pickup_street_number" label="Civico" :value="old('pickup_street_number')" maxlength="20" required/>
                <x-ui.field width="city" name="pickup_city" label="Comune di ritiro" :value="old('pickup_city')" required/>
                <x-ui.field width="postal" name="pickup_postal_code" label="CAP ritiro" :value="old('pickup_postal_code')" pattern="[0-9]{5}" maxlength="5" inputmode="numeric" required/>
            </div>
        </section>
        <section class="surface form-section booking-step">
            <h2><span class="booking-step-number">02</span> Punto di consegna</h2>
            <div class="field field-service booking-service">
                <label class="form-label" for="shipping_type">Tipo di spedizione</label>
                <select class="form-select" id="shipping_type" name="shipping_type" required>
                    <option value="regional" @selected(old('shipping_type', 'regional') === 'regional')>Regionale · rete EA-Express</option>
                    <option value="external" @selected(old('shipping_type') === 'external')>Fuori regione · vettore esterno</option>
                </select>
            </div>
            <div class="booking-grid booking-contact">
                <x-ui.field width="name" name="recipient_name" label="Nome destinatario" :value="old('recipient_name')" maxlength="150" autocomplete="section-recipient shipping name" required/>
                <x-ui.field name="recipient_phone" label="Telefono destinatario" type="tel" :value="old('recipient_phone')" minlength="6" maxlength="30" autocomplete="section-recipient shipping tel" required/>
            </div>
            <div class="booking-grid booking-address">
                <x-ui.field width="address" name="delivery_address" label="Indirizzo di consegna" :value="old('delivery_address')" required/>
                <x-ui.field width="civic" name="delivery_street_number" label="Civico" :value="old('delivery_street_number')" maxlength="20" required/>
                <x-ui.field width="city" name="delivery_city" label="Comune di consegna" :value="old('delivery_city')" required/>
                <x-ui.field width="postal" name="delivery_postal_code" label="CAP consegna" :value="old('delivery_postal_code')" pattern="[0-9]{5}" maxlength="5" inputmode="numeric" help="Automatico se il comune ha un solo CAP."/>
            </div>
            <div class="booking-grid booking-location-details">
                <x-ui.field width="zone" name="delivery_zone" label="Zona / quartiere (facoltativo)" :value="old('delivery_zone')" maxlength="100"/>
                <x-ui.field width="province" name="delivery_province" label="Provincia" :value="old('delivery_province')" maxlength="100"/>
                <x-ui.field width="region" name="delivery_region" label="Regione" :value="old('delivery_region')" maxlength="100"/>
            </div>
            <p class="territory-feedback small" data-territory-feedback role="status"></p>
        </section>
        <section class="surface form-section booking-step">
            <h2><span class="booking-step-number">03</span> Data e orario</h2>
            <div class="booking-grid booking-schedule">
                <x-ui.field name="pickup_date" label="Data del ritiro" type="date" :value="old('pickup_date', now('Europe/Rome')->toDateString())" :min="now('Europe/Rome')->toDateString()" required/>
                <x-ui.field name="pickup_from" label="Dalle" type="time" :value="old('pickup_from')" help="Orari nell’ora italiana. Il primo orario disponibile viene proposto automaticamente." required/>
                <x-ui.field name="pickup_to" label="Alle" type="time" :value="old('pickup_to', '12:00')" required/>
            </div>
        </section>
        <section class="surface form-section booking-step">
            <h2><span class="booking-step-number">04</span> Pacco e indicazioni</h2>
            <div class="booking-grid booking-parcel">
                <x-ui.field name="parcel_count" label="Numero colli" type="number" min="1" max="100" :value="old('parcel_count', 1)" required/>
                <x-ui.field name="parcel_value" label="Valore del pacco (€)" required pattern="[0-9]{1,6}([.,][0-9]{1,2})?" inputmode="decimal" :value="old('parcel_value')" placeholder="0,00" help="Non è il costo della spedizione."/>
                <div class="field field-package">
                    <label for="package_type" class="form-label">Caratteristiche del pacco</label>
                    <select class="form-select" name="package_type" id="package_type" required>
                        <option value="standard" @selected(old('package_type', 'standard') === 'standard')>Standard</option>
                        <option value="fragile" @selected(old('package_type') === 'fragile')>Fragile</option>
                        <option value="other" @selected(old('package_type') === 'other')>Altro</option>
                    </select>
                </div>
            </div>
            <div data-package-description @if(old('package_type', 'standard') !== 'other') hidden @endif>
                <x-ui.field width="description" name="package_description" label="Descrizione caratteristiche" :value="old('package_description')" maxlength="255"/>
            </div>
            <div class="booking-grid booking-content">
                @php
                    $contentCategory = old('category', 'custom');
                    $contentValue = old('content_description');
                    if (!is_string($contentValue)) {
                        $contentValue = is_string($contentCategory) && $contentCategory !== 'custom' ? (\App\Support\OrderContent::Categories[$contentCategory] ?? '') : '';
                    }
                @endphp
                <x-ui.field width="category" name="content_description" label="Contenuto" maxlength="255" :value="$contentValue" list="parcel-content-suggestions" required placeholder="Scegli o scrivi liberamente" help="Scegli un suggerimento oppure descrivi il contenuto del pacco."/>
                <datalist id="parcel-content-suggestions">@foreach(\App\Support\OrderContent::Categories as $value => $label)@if($value !== 'custom')<option value="{{ $label }}">@endif @endforeach</datalist>
                <input type="hidden" name="category" value="{{ is_string($contentCategory) ? $contentCategory : 'custom' }}">
                <div class="field field-priority">
                    <label for="urgency" class="form-label">Priorità</label>
                    <select id="urgency" class="form-select" name="urgency" required><option value="standard">Standard</option><option value="urgent" @selected(old('urgency') === 'urgent')>Urgente</option></select>
                </div>
            </div>
            <div class="field field-notes">
                <label for="notes" class="form-label">Note riservate al rider</label>
                <textarea class="form-control" name="notes" id="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
            </div>
        </section>
        <section class="surface form-section booking-step"><h2><span class="booking-step-number">05</span> Tariffa</h2><div class="shipping-quote-summary" data-shipping-quote role="status" aria-live="polite">Inserisci il comune di consegna. Il CAP viene risolto se univoco.</div></section>
        <div class="order-form-actions"><x-ui.icon-button action="send" label="Prosegui al riepilogo" type="submit" data-booking-review text/><button type="button" class="btn btn-outline-danger" data-dialog-open="cancel-order"><x-ui.icon name="trash"/> Annulla</button></div>
    </form>
    <x-ui.modal id="cancel-order" title="Annullare la creazione dell’ordine?" danger>
        <p>I dati inseriti andranno persi.</p>
        <footer class="modal-actions"><button type="button" class="btn modal-back" data-dialog-close><x-ui.icon name="arrow-left"/> Continua modifica</button><x-ui.icon-button action="confirm" href="{{ route('orders.incoming') }}" label="Conferma annullamento" /></footer>
    </x-ui.modal>
</x-app-layout>
