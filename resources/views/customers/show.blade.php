<x-app-layout :title="$customer->name">
    <nav class="page-back" aria-label="Navigazione pagina"><x-ui.icon-button action="back" :href="route('customers.index')" label="Torna ai clienti" /></nav>
    @php($initials = collect(preg_split('/\s+/', trim($customer->name)))->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->join(''))
    <section class="customer-hero" aria-label="Profilo cliente">
        <div class="customer-hero-main">
            <span class="customer-hero-avatar" aria-hidden="true">{{ $initials }}</span>
            <div class="customer-hero-title">
                <span class="eyebrow">PROFILO CLIENTE</span>
                <h1>{{ $customer->name }}</h1>
                <p class="customer-hero-tags">
                    <span class="customer-tag"><x-ui.icon :name="['business'=>'shop','private'=>'person','online_shop'=>'bag'][$customer->sender_type] ?? 'person'" />{{ ['business'=>'Attività commerciale','private'=>'Privato','online_shop'=>'Shop online'][$customer->sender_type] ?? 'Cliente' }}</span>
                    <span @class(['customer-tag', 'customer-tag--active' => $customer->is_active, 'customer-tag--off' => ! $customer->is_active])><span class="customer-tag-dot" aria-hidden="true"></span>{{ $customer->is_active ? 'Account attivo' : 'Account disabilitato' }}</span>
                </p>
            </div>
            @can('update', $customer)<form method="post" action="{{ route('users.update', $customer) }}" class="account-state-actions customer-account-toggle">@csrf @method('patch')<x-ui.icon-button type="submit" :action="$customer->is_active ? 'delete' : 'edit'" :icon="$customer->is_active ? 'toggle-off' : 'toggle-on'" :label="$customer->is_active ? 'Disattiva account cliente' : 'Riattiva account cliente'" name="action" :value="$customer->is_active ? 'deactivate' : 'activate'" :data-confirm="$customer->is_active ? 'Disattiva account cliente' : null" :data-confirm-name="$customer->name" /></form>@endcan
        </div>
        <dl class="customer-identity" aria-label="Identità e contatti">
            <div><dt><x-ui.icon name="envelope" />Email</dt><dd><a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a></dd></div>
            <div><dt><x-ui.icon name="telephone" />Telefono</dt><dd>@if($customer->phone)<a href="tel:{{ $customer->phone }}">{{ $customer->phone }}</a>@else Non indicato @endif</dd></div>
            <div><dt><x-ui.icon name="briefcase" />Attività</dt><dd>{{ $customer->business_type ?: 'Non indicata' }}@if($customer->business_description)<small>{{ $customer->business_description }}</small>@endif</dd></div>
            <div><dt><x-ui.icon name="calendar-check" />Cliente dal</dt><dd>{{ $customer->created_at->timezone('Europe/Rome')->format('d/m/Y') }}</dd></div>
        </dl>
        @if(!$customer->is_active && $customer->deactivated_at)<p class="account-retention-note" role="note"><x-ui.icon name="hourglass-split" /> Disattivato il {{ $customer->deactivated_at->timezone('Europe/Rome')->format('d/m/Y') }}. Senza riattivazione entra nella revisione di conservazione dal {{ \App\Support\DeactivatedAccounts::reviewDate($customer)->timezone('Europe/Rome')->format('d/m/Y') }}; ordini e contabilità restano conservati.</p>@endif
    </section>
    <x-ui.filters :reset="route('customers.show', $customer)" label="Search" layout="analysis">
        <x-slot:period><x-ui.filter-period route="customers.show" :period="$period" /></x-slot:period>
        <x-ui.filter-select name="status" label="Stato" :options="[''=>'Tutti', ...collect(\App\OrderStatus::cases())->mapWithKeys(fn($state)=>[$state->value=>$state->label()])->all()]" />
        @if(request()->filled('tariff'))<input type="hidden" name="tariff" value="{{ request('tariff') }}">@endif
    </x-ui.filters>
    <section class="report-kpis" aria-label="Attività nel periodo">
        <x-ui.metric label="Richieste nel periodo" :value="$summary['total']" :note="$lifetime['total'].' richieste complessive · '.$comparison['total'].' nel periodo precedente'" />
        <x-ui.metric label="Spedizioni completate" :value="$summary['delivered']" tone="positive" :note="$summary['completion_percent'].'% delle richieste'" />
        <x-ui.metric label="In corso" :value="$summary['in_progress']" tone="attention" note="Richieste ancora aperte" />
        <x-ui.metric label="Annullate / rifiutate" :value="$summary['cancelled']" note="Escluse dal valore delle spedizioni" />
    </section>
    <section class="customer-value-grid" aria-label="Valore per EA-Express">
        <article class="surface customer-value customer-value--brand"><span class="eyebrow"><x-ui.icon name="box-seam" />VALORE SPEDIZIONI</span><strong>{{ \App\Support\Money::format($summary['shipping_spend_cents']) }}</strong><p>Tariffe finali nel periodo · {{ $summary['unpriced'] }} da verificare</p></article>
        <article class="surface customer-value customer-value--green"><span class="eyebrow"><x-ui.icon name="cash-coin" />INCASSATO EA-Express</span><strong>{{ \App\Support\Money::format($receivedCents) }}</strong><p>Quota EA registrata nel periodo per il cliente, al netto delle rettifiche.</p>@if($receipts['unallocated'])<small>{{ $receipts['unallocated'] }} incassi fuori regione con quota EA non ripartita.</small>@endif</article>
        <article class="surface customer-value customer-value--blue"><span class="eyebrow"><x-ui.icon name="tag" />TARIFFA MEDIA</span><strong>{{ \App\Support\Money::format($averageCents) }}</strong><p>Calcolata sulle tariffe finali disponibili</p></article>
        <article class="surface customer-value customer-value--orange"><span class="eyebrow"><x-ui.icon name="hourglass-split" />SOSPESI ATTUALI</span><strong>{{ \App\Support\Money::format($pendingCents) }}</strong><p>Residuo da incassare · tutti i periodi</p><a href="{{ route('pending.index', ['customer_id'=>$customer->slug]) }}">Apri sospesi <x-ui.icon name="arrow-right" /></a></article>
    </section>
    <div class="customer-analysis-grid">
        <section class="surface section-panel"><h2 class="h5">Distribuzione tariffe</h2><p class="data-caption">Prezzi finali · annullati e rifiutati esclusi</p><ul class="account-tariffs">
            @forelse($summary['prices'] as $price)<li><a href="{{ route('customers.show', ['customer'=>$customer, ...request()->except('page'), 'tariff'=>$price->price_cents]) }}#customer-orders"><span>{{ \App\Support\Money::format($price->price_cents) }} × {{ $price->shipments }}</span><strong>{{ \App\Support\Money::format((int)$price->total_cents) }}</strong></a></li>@empty<li>Nessuna tariffa finale nel periodo.</li>@endforelse
        </ul></section>
        <x-reports.trend id="customer-trend" title="Andamento richieste" description="Richieste create nel periodo, in ora italiana. Per l’anno, aggregazione mensile." :points="$trend" compact />
    </div>
    <section class="surface section-panel" id="customer-orders"><header class="page-heading customer-orders-heading"><div><h2 class="h4">Storico ordini <span class="count-badge">{{ $orders->total() }}</span></h2><p>Richieste del periodo selezionato</p></div>@if(request()->filled('tariff'))<a href="{{ route('customers.show',['customer'=>$customer, ...request()->except(['tariff','page'])]) }}#customer-orders">Tutte le tariffe</a>@endif</header>
        <ul class="account-order-list">@forelse($orders as $order)<li data-order-state="{{ $order->status->value }}" data-tone="{{ $order->status->tone() }}"><span class="customer-order-icon" aria-hidden="true"><x-ui.icon name="box-seam" /></span><div><a href="{{ route('orders.show',$order) }}">{{ $order->reference }}</a><small>{{ $order->recipient_name }} · {{ $order->delivery_city }} · {{ $order->created_at->timezone('Europe/Rome')->format('d/m/Y') }}</small></div><x-ui.status-badge :status="$order->status" /><strong>{{ $order->price_cents === null ? 'Da concordare' : \App\Support\Money::format($order->price_cents) }}</strong></li>@empty<li>Nessun ordine per questi filtri.</li>@endforelse</ul>
        {{ $orders->links('components.ui.pagination') }}
    </section>
</x-app-layout>
