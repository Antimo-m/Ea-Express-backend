<x-app-layout title="Bilancio">
<div class="finance-workspace">
    <header class="page-heading"><div><span class="eyebrow">EA-EXPRESS · CONTABILITÀ OPERATIVA</span><h1>Bilancio</h1><p>{{ auth()->user()->role === \App\UserRole::Admin ? 'Incassi, spese e movimenti della tua attività.' : 'Spedizioni assegnate e spese registrate da te.' }}</p></div><x-ui.icon-button action="add" label="Aggiungi operazione contabile" class="finance-add" data-dialog-open="balance-add" aria-controls="balance-add" aria-haspopup="dialog" /></header>
    <x-ui.filters :reset="route('balance.index')" layout="analysis">
        <x-slot:period><x-ui.filter-period route="balance.index" :period="$period" balance /></x-slot:period>
        <input type="hidden" data-filter-context name="ledger" value="{{ $ledger }}">
        <x-ui.account-multiselect name="customer_ids" id="balance-customers" label="Account clienti" :options="$customers" :values="$selectedCustomerSlugs" />
    </x-ui.filters>
    <section class="finance-statement finance-statement-primary" aria-labelledby="statement-heading" aria-label="Riepilogo economico del periodo">
        <header class="section-heading"><div><span class="eyebrow">{{ count($selectedCustomerSlugs) ? count($selectedCustomerSlugs).' ACCOUNT SELEZIONATI' : 'TUTTI GLI ACCOUNT' }}</span><h2 id="statement-heading">Prospetto contabile</h2><p>{{ $period->start->format('d/m/Y') }} — {{ $period->end->format('d/m/Y') }}</p></div></header>
        <div class="finance-metrics">
            <x-ui.metric label="Totale entrate lorde" :value="\App\Support\Money::format($grossIncome)" note="Incassi effettivi, altre entrate e rettifiche" icon="arrow-down-left" tone="positive" />
            <x-ui.metric label="Storni" :value="\App\Support\Money::format(-$reversalTotal)" note="Rimborsi registrati nel periodo, sottratti una sola volta" icon="arrow-counterclockwise" tone="attention" />
            <x-ui.metric label="Totale spese" :value="\App\Support\Money::format($totalExpenses)" note="Spese e pagamenti effettivi dei debiti" icon="arrow-up-right" />
            <x-ui.metric label="Totale netto" :value="\App\Support\Money::format($operatingNet)" note="Entrate lorde − storni − spese" icon="wallet2" />
        </div>
        <p class="register-note statement-policy">Criterio di cassa. Il valore del pacco non è un ricavo. {{ count($selectedCustomerSlugs) ? 'Le spese generali senza account sono escluse dalla selezione.' : 'Gli importi includono tutti gli account e le spese generali.' }}</p>
        <details class="statement-forecast"><summary>Sospesi e previsione del risultato</summary><div class="finance-statement-content">
            <dl class="statement-lines">@foreach([['Sospesi in entrata', $pendingIncoming], ['Sospesi in uscita', -$pendingOutgoing], ['Conto finale dei sospesi', $pendingImpact], ['Contanti aggiuntivi', $cashAdditions], ['Risultato finale · previsione', $finalNet]] as [$label, $amount])<div><dt>{{ $label }}</dt><dd>{{ \App\Support\Money::format($amount) }}</dd></div>@endforeach</dl>
            <p>Previsione = totale netto + residui in entrata − residui in uscita + contanti aggiuntivi. I sospesi sono sorti nel periodo, con residuo aggiornato a oggi. La previsione non rappresenta disponibilità di cassa o un bilancio fiscale. I saldi già riscossi o pagati sono conteggiati una sola volta.</p>
            @if(auth()->user()->role === \App\UserRole::Admin)<a class="btn btn-outline-secondary" href="{{ route('pending.index', count($selectedCustomerSlugs) === 1 ? ['customer_id' => $selectedCustomerSlugs[0]] : []) }}">Gestisci sospesi</a>@endif
        </div></details>
        <p class="register-note statement-deliveries">Tariffe maturate: <strong>{{ \App\Support\Money::format($earned) }}</strong> · {{ $completedCount }} consegne valide, {{ $shippingEconomics['regional_count'] }} regionali e {{ $shippingEconomics['external_count'] }} nazionali. Il maturato non si somma agli incassi. Ordini stornati nel periodo: {{ $reversedCount }} · annullati: {{ $cancelledCount }}.</p>
    </section>
    <x-ui.data-panel id="delivery-details" title="Incassi da registrare" :count="$orders->total()" :description="\App\Support\Money::format($outstanding).' di residuo sulle consegne valide del periodo. I pagamenti parziali si gestiscono nei Sospesi.'">
        <x-slot:actions><form method="get" class="register-sort" aria-label="Ordinamento incassi">@foreach(request()->except(['receipt_sort', 'orders_page']) as $name => $value)@if(is_scalar($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@elseif($name === 'customer_ids')@foreach($value as $slug)<input type="hidden" name="customer_ids[]" value="{{ $slug }}">@endforeach @endif @endforeach<label for="receipt-sort">Ordina per</label><select name="receipt_sort" id="receipt-sort" class="form-select">@foreach(['recent' => 'Consegna più recente', 'oldest' => 'Consegna meno recente', 'amount_high' => 'Tariffa decrescente', 'amount_low' => 'Tariffa crescente'] as $value => $label)<option value="{{ $value }}" @selected($receiptSort === $value)>{{ $label }}</option>@endforeach</select><button type="submit" class="btn btn-outline-secondary btn-sm">Ordina</button></form></x-slot:actions>
        @include('balance.receipts', ['receipts' => $orders, 'caption' => 'Incassi da registrare', 'mode' => 'pending', 'empty' => 'Nessun incasso da registrare nel periodo.'])
    </x-ui.data-panel>
    <section class="finance-ledger" aria-labelledby="ledger-heading">
        <header class="section-heading"><div><span class="eyebrow">REGISTRO ECONOMICO</span><h2 id="ledger-heading">Movimenti e storico</h2></div><span class="register-note">{{ $period->start->format('d/m/Y') }} — {{ $period->end->format('d/m/Y') }}</span></header>
        <nav class="ledger-navigation" aria-label="Viste del registro economico">@foreach(['payments' => 'Incassi', 'expenses' => 'Spese', 'cash' => 'Contanti e altre voci', 'recorded' => 'Incassi già registrati', 'reversed' => 'Ordini stornati'] as $key => $label)@if($key !== 'cash' || auth()->user()->role === \App\UserRole::Admin)<a href="{{ route('balance.index', [...request()->except(['ledger', 'payments_page', 'expenses_page', 'cash_page', 'general_page', 'recorded_page', 'reversed_page', 'pending_page']), 'ledger' => $key]) }}#cash-details" @class(['btn', 'btn-outline-secondary', 'is-active' => $ledger === $key]) @if($ledger === $key) aria-current="page" @endif>{{ $label }}</a>@endif @endforeach</nav>
        <div id="cash-details">@include('balance.history')</div>
    </section>
    @include('balance.operations')
</div>
</x-app-layout>
