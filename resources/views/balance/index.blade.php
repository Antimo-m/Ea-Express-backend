<x-app-layout title="Bilancio">
<div class="finance-workspace">
    <header class="page-heading"><div><span class="eyebrow">EA-Express · CONTABILITÀ OPERATIVA</span><h1>Bilancio</h1><p>{{ auth()->user()->role === \App\UserRole::Admin ? 'Incassi, spese e movimenti della tua attività.' : 'Spedizioni assegnate e spese registrate da te.' }}</p></div><x-ui.icon-button action="add" label="Aggiungi operazione contabile" class="finance-add" data-dialog-open="balance-add" aria-controls="balance-add" aria-haspopup="dialog" /></header>
    <x-ui.filters :reset="route('balance.index')" layout="analysis">
        <x-slot:search><input class="form-control" type="search" name="q" value="{{ $search }}" placeholder="Cerca cliente, ordine o causale" aria-label="Cerca movimenti" maxlength="150"></x-slot:search>
        <x-slot:period><x-ui.filter-period route="balance.index" :period="$period" balance /></x-slot:period>
        <input type="hidden" data-filter-context name="ledger" value="{{ $ledger }}">
        <x-ui.account-multiselect name="customer_ids" id="balance-customers" label="Account clienti" :options="$customers" :values="$selectedCustomerSlugs" />
    </x-ui.filters>
    <section class="finance-statement finance-statement-primary" data-workspace-region="finance-statement" aria-labelledby="statement-heading" aria-label="Riepilogo economico del periodo">
        <header class="section-heading"><div><span class="eyebrow">{{ count($selectedCustomerSlugs) ? count($selectedCustomerSlugs).' ACCOUNT SELEZIONATI' : 'TUTTI GLI ACCOUNT' }}</span><h2 id="statement-heading">Prospetto contabile</h2><p>{{ $period->start->format('d/m/Y') }} — {{ $period->end->format('d/m/Y') }}</p></div></header>
        <section class="finance-statement-section" aria-labelledby="operating-heading">
            <h3 id="operating-heading">Risultato operativo</h3>
            <div class="finance-metrics">
                <x-ui.metric label="Totale lordo" :value="\App\Support\Money::format($grossIncome)" note="Incassi registrati, altre entrate e rettifiche; gli incassi stornati sono già esclusi" icon="arrow-down-left" tone="positive" />
                <x-ui.metric label="Totale spese" :value="\App\Support\Money::format($totalExpenses)" note="Spese e pagamenti effettivi dei debiti" icon="arrow-up-right" />
                <x-ui.metric label="Totale netto operativo" :value="\App\Support\Money::format($operatingResult)" note="Totale lordo − totale spese" icon="wallet2" />
            </div>
        </section>
        <section class="finance-statement-section" aria-labelledby="pending-heading">
            <h3 id="pending-heading">Sospesi</h3>
            <dl class="statement-lines">
                <div><dt>Sospesi in entrata <small>Residui da incassare</small></dt><dd>{{ \App\Support\Money::format($pendingIncoming) }}</dd></div>
                <div><dt>Sospesi in uscita <small>Residui da corrispondere</small></dt><dd>{{ \App\Support\Money::format($pendingOutgoing) }}</dd></div>
                <div><dt>Differenza sospesi <small>Entrata − uscita</small></dt><dd>{{ \App\Support\Money::format($pendingImpact) }}</dd></div>
            </dl>
            <p class="register-note">Posizioni sorte nel periodo, con residuo aggiornato a oggi. Solo la differenza ancora da regolare si somma al risultato finale: gli importi già pagati sono esclusi dai residui e compaiono una sola volta negli incassi o nelle spese.</p>
        </section>
        <section class="finance-statement-section" aria-labelledby="cash-heading">
            <h3 id="cash-heading">Contanti</h3>
            <dl class="statement-lines"><div><dt>Contanti disponibili</dt><dd>{{ \App\Support\Money::format($cashMovementBalance) }}</dd></div></dl>
            <p class="register-note">Movimenti classificati come Contanti, aggiunti una sola volta al totale finale. Gli incassi delle spedizioni sono già nel risultato operativo e non aumentano questa voce.</p>
        </section>
        <section class="finance-statement-section" aria-labelledby="final-heading">
            <h3 id="final-heading">Risultato finale</h3>
            <article class="metric-tile statement-net">
                <header><span>Totale netto finale</span><x-ui.icon name="wallet2" /></header>
                <div class="finance-final-value"><strong class="metric-tile-value">{{ \App\Support\Money::format($finalNet) }}</strong><span class="delivery-count-badge" aria-label="{{ $deliveredCount }} consegne effettuate nel periodo" title="Consegne effettuate nel periodo">{{ $deliveredCount }}</span></div>
                <p>Totale netto operativo + differenza sospesi + contanti disponibili. Il badge conta le spedizioni effettivamente consegnate, anche quando il relativo incasso è stato stornato.</p>
            </article>
            <dl class="statement-lines finance-corrections">
                <div><dt>Storni <small>Attivi e non ancora ripristinati, aggiornati a oggi</small></dt><dd>{{ \App\Support\Money::format($reversalTotal) }}</dd></div>
            </dl>
            <p class="register-note">Gli storni sono informativi: gli incassi annullati sono già esclusi dal lordo e non vengono sottratti di nuovo. Il ripristino riporta l’incasso Da registrare, senza aumentare le entrate.</p>
        </section>
        <p class="register-note statement-policy">Ogni movimento incide nel proprio periodo di registrazione. Il valore del pacco non è un ricavo. {{ count($selectedCustomerSlugs) ? 'Le spese generali senza account sono escluse dalla selezione.' : 'Gli importi includono tutti gli account e le spese generali.' }}</p>
        <p class="register-note statement-policy">Flusso effettivo degli incassi: <strong>{{ \App\Support\Money::format($cash) }}</strong> · rimborsi pagati nel periodo: {{ \App\Support\Money::format($refundTotal) }}. Lo storno rettifica il netto e registra anche il rimborso già effettuato; il denaro non viene sottratto due volte. I movimenti storici privi di classificazione conservano il saldo precedente e richiedono riconciliazione.</p>
        <p class="register-note statement-deliveries">Tariffe maturate: <strong>{{ \App\Support\Money::format($earned) }}</strong> · {{ $completedCount }} consegne valide, {{ $shippingEconomics['regional_count'] }} regionali e {{ $shippingEconomics['external_count'] }} nazionali. Il maturato non si somma agli incassi. Storni attivi nel periodo: {{ $reversedCount }} · annullati: {{ $cancelledCount }}.</p>
    </section>
    <x-ui.data-panel data-workspace-region="receipts" id="delivery-details" title="Incassi da registrare" :count="$orders->total()" :description="\App\Support\Money::format($outstanding).' di residuo sulle consegne valide del periodo. I pagamenti parziali si gestiscono nei Sospesi.'">
        <x-slot:actions><form method="get" class="register-sort" aria-label="Ordinamento incassi">@foreach(request()->except(['receipt_sort', 'orders_page']) as $name => $value)@if(is_scalar($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@elseif($name === 'customer_ids')@foreach($value as $slug)<input type="hidden" name="customer_ids[]" value="{{ $slug }}">@endforeach @endif @endforeach<label for="receipt-sort">Ordina per</label><select name="receipt_sort" id="receipt-sort" class="form-select">@foreach(['recent' => 'Consegna più recente', 'oldest' => 'Consegna meno recente', 'amount_high' => 'Tariffa decrescente', 'amount_low' => 'Tariffa crescente'] as $value => $label)<option value="{{ $value }}" @selected($receiptSort === $value)>{{ $label }}</option>@endforeach</select><button type="submit" class="btn btn-outline-secondary btn-sm">Ordina</button></form></x-slot:actions>
        @include('balance.receipts', ['receipts' => $orders, 'caption' => 'Incassi da registrare', 'mode' => 'pending', 'empty' => 'Nessun incasso da registrare nel periodo.'])
    </x-ui.data-panel>
    <section class="finance-ledger" data-workspace-region="finance-ledger" aria-labelledby="ledger-heading">
        <header class="section-heading"><div><span class="eyebrow">REGISTRO ECONOMICO</span><h2 id="ledger-heading">Movimenti di cassa</h2></div><span class="register-note">{{ $period->start->format('d/m/Y') }} — {{ $period->end->format('d/m/Y') }}</span></header>
        <nav class="ledger-navigation" aria-label="Viste del registro economico">@foreach(['payments' => 'Incassi', 'expenses' => 'Spese', 'cash' => 'Contanti e altre categorie', 'recorded' => 'Incassi già registrati', 'reversed' => 'Ordini stornati', 'pending' => 'Sospesi'] as $key => $label)@if($key !== 'cash' || auth()->user()->role === \App\UserRole::Admin)<a href="{{ route('balance.index', [...request()->except(['ledger', 'payments_page', 'expenses_page', 'cash_page', 'general_page', 'recorded_page', 'reversed_page', 'reversals_page', 'legacy_reversals_page', 'pending_page']), 'ledger' => $key]) }}#cash-details" @class(['btn', 'btn-outline-secondary', 'is-active' => $ledger === $key]) @if($ledger === $key) aria-current="page" @endif>{{ $label }}</a>@endif @endforeach</nav>
        <div id="cash-details">@include('balance.history')</div>
    </section>
    @include('balance.operations')
</div>
</x-app-layout>
