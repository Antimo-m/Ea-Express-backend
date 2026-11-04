@if($ledger === 'reversed')
    @include('balance.reversals')
    @if($legacyReversals->total())
        <x-ui.data-panel title="Rettifiche storiche da riconciliare" :count="$legacyReversals->total()" description="Questi movimenti precedono i collegamenti verificati. Non è possibile stabilire dal solo importo se il denaro sia stato rimborsato. Date, importi e storico restano conservati.">
            <div class="register-table-wrap"><table class="table register-table accounting-table" data-table-static><thead><tr><th scope="col">Ordine e cliente</th><th scope="col">Data e operatore</th><th scope="col" class="text-end">Rettifica storica</th><th scope="col">Verifica</th></tr></thead><tbody>
            @foreach($legacyReversals as $entry)<tr><td><a class="register-primary" href="{{ route('orders.show', $entry->order) }}">{{ $entry->order->displayName() }}</a><span class="register-reference">{{ $entry->order->reference }}</span><span class="register-note">{{ $entry->note }}</span></td><td>{{ $entry->created_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}<small class="register-note">{{ $entry->user->name }}</small></td><td class="register-amount text-end">{{ \App\Support\Money::format($entry->amount_cents) }}</td><td><span class="status-pill tone-orange">Storico da riconciliare</span><a class="register-note" href="{{ route('audits.index', ['type' => 'orders', 'slug' => $entry->order->slug]) }}">Verifica incasso, storno e ripristino</a></td></tr>@endforeach
            </tbody></table></div>
            {{ $legacyReversals->links('components.ui.pagination', ['descriptive' => true, 'numbered' => true]) }}
        </x-ui.data-panel>
    @endif
    @if($reversedOrders->total())
        <x-ui.data-panel title="Storni storici da riconciliare" description="Movimenti precedenti senza collegamenti verificati. Verifica incasso originale e rimborso effettivo prima di ripristinare.">
            @include('balance.receipts', ['receipts' => $reversedOrders, 'mode' => 'reversed', 'caption' => 'Storni storici', 'empty' => 'Nessuno storno storico.'])
        </x-ui.data-panel>
    @endif
@elseif($ledger === 'recorded')
    <x-ui.data-panel :title="$ledger === 'recorded' ? 'Incassi già registrati' : 'Ordini stornati'" :description="$ledger === 'recorded' ? 'Pagamenti acquisiti. Le rettifiche restano tracciate nello storico.' : 'Ordini con incasso annullato nel periodo, esclusi dalle consegne valide. Lo stato della consegna e la cronologia sono conservati.'">
        @include('balance.receipts', ['receipts' => $ledger === 'recorded' ? $recordedOrders : $reversedOrders, 'mode' => $ledger, 'caption' => $ledger === 'recorded' ? 'Incassi già registrati' : 'Ordini stornati', 'empty' => $ledger === 'recorded' ? 'Nessun incasso già registrato nel periodo.' : 'Nessuno storno nel periodo.'])
    </x-ui.data-panel>
@elseif($ledger === 'expenses')
    <x-ui.data-panel id="expense-details" title="Spese registrate" :count="$expenses->total()" description="Le spese annullate restano visibili. I saldi collegati ai Sospesi si gestiscono dal relativo registro.">
        <div class="register-table-wrap"><table class="table register-table accounting-table" data-table-static><thead><tr><th scope="col">Spesa e origine</th><th scope="col">Data e operatore</th><th scope="col" class="text-end">Uscita</th><th scope="col">Stato</th><th scope="col">Operazioni</th></tr></thead><tbody>
        @forelse($expenses as $expense)<tr><td><strong class="register-primary">{{ $expense->description }}</strong>@if($expense->pendingSettlement && auth()->user()->role === \App\UserRole::Admin)<a class="register-note" href="{{ route('pending.index', ['slug' => $expense->pendingSettlement->account->slug]) }}">Saldo sospeso</a>@endif</td><td>{{ $expense->spent_on->format('d/m/Y') }}<small class="register-note">{{ $expense->user->name }}</small></td><td class="register-amount text-end">− {{ \App\Support\Money::format($expense->amount_cents) }}</td><td><span class="status-pill {{ $expense->voided_at ? 'tone-neutral' : 'tone-green' }}">{{ $expense->voided_at ? 'Annullata' : 'Registrata' }}</span></td><td><div class="register-actions">
            @unless($expense->voided_at || $expense->pending_settlement_id)
            <x-ui.action-dialog :id="'expense-edit-'.$expense->slug" title="Modifica spesa" :description="$expense->description"><form method="post" action="{{ route('expenses.update', $expense) }}" data-financial-form class="form-stack">@csrf @method('patch')<input type="hidden" name="version" value="{{ $expense->version }}"><x-ui.field name="description" :id="'expense-description-'.$expense->slug" label="Descrizione" :value="$expense->description" required /><x-ui.field name="amount" :id="'expense-amount-'.$expense->slug" label="Importo (€)" :value="number_format($expense->amount_cents / 100, 2, '.', '')" inputmode="decimal" required /><x-ui.field name="spent_on" :id="'expense-date-'.$expense->slug" label="Data" type="date" :value="$expense->spent_on->toDateString()" required /><x-ui.icon-button action="receive" label="Salva modifica" type="submit" text /></form></x-ui.action-dialog>
            <form method="post" action="{{ route('expenses.destroy', $expense) }}" data-financial-form data-confirm="Annulla spesa" data-confirm-name="{{ $expense->description }}">@csrf @method('delete')<x-ui.icon-button action="delete" label="Annulla registrazione" type="submit" /></form>
            @endunless
            @if(auth()->user()->role === \App\UserRole::Admin)<x-ui.icon-button action="history" label="Storico modifiche" :href="route('audits.index', ['type' => 'expenses', 'slug' => $expense->slug])" />@endif
        </div></td></tr>@empty<tr><td colspan="5" class="register-empty">Nessuna spesa nel periodo.</td></tr>@endforelse</tbody></table></div>
        {{ $expenses->links('components.ui.pagination', ['descriptive' => true, 'numbered' => true]) }}
    </x-ui.data-panel>
@elseif($ledger === 'cash' && auth()->user()->role === \App\UserRole::Admin)
    <x-ui.data-panel id="cash-register" title="Contanti e altre categorie" :count="$manualEntries->total()" description="Categorie separate per origine del movimento. Gli incassi delle spedizioni, le spese e gli storni hanno registri dedicati.">
        @forelse(collect($manualSections)->filter(fn ($section) => $section['entries']->total()) as $kind => $category)
            <section class="movement-category" data-movement-category="{{ $kind }}" aria-label="{{ $category['label'] }}">
                <header class="register-subheading"><h3>{{ $category['label'] }}</h3><span class="count-pill">{{ $category['entries']->total() }}</span></header>
                @include('balance.manual-entries', ['entries' => $category['entries']])
            </section>
        @empty<p class="register-empty">Nessun movimento manuale nel periodo.</p>@endforelse
    </x-ui.data-panel>
@elseif($ledger === 'pending')
    <x-ui.data-panel title="Sospesi" :count="$pendingEntries->total()" description="Posizioni sorte nel periodo, con stato e residuo aggiornati a oggi. Sono impegni aperti, non movimenti di denaro. I pagamenti effettivi compaiono negli Incassi o nelle Spese.">
        <div class="register-table-wrap"><table class="table register-table accounting-table" data-table-static>
            <caption class="visually-hidden">Sospesi nel periodo e per i clienti selezionati</caption>
            <thead><tr><th scope="col">Posizione e cliente</th><th scope="col">Data e scadenza</th><th scope="col">Direzione e stato</th><th scope="col" class="text-end">Residuo aperto</th><th scope="col">Operazioni</th></tr></thead>
            <tbody>@forelse($pendingEntries as $account)<tr>
                <td><strong class="register-primary">{{ $account->subject }}</strong><span class="register-note">{{ $account->description }}</span>@if($account->customer)<small class="register-note">{{ $account->customer->name }}</small>@endif @if($account->order)<a class="register-reference" href="{{ route('orders.show', $account->order) }}">{{ $account->order->reference }}</a>@endif</td>
                <td>{{ $account->occurred_on->format('d/m/Y') }}@if($account->due_on)<small class="register-note">Scadenza {{ $account->due_on->format('d/m/Y') }}</small>@endif</td>
                <td><span class="status-pill {{ $account->direction === 'incoming' ? 'tone-green' : 'tone-orange' }}">{{ $account->direction === 'incoming' ? 'In entrata' : 'In uscita' }}</span><small class="register-note">{{ $account->statusLabel() }} · nessun effetto automatico sulla cassa</small></td>
                <td class="text-end register-amount">{{ \App\Support\Money::format(in_array($account->state, ['open', 'partially_paid']) ? ($account->amount_cents - $account->settled_cents) * ($account->direction === 'incoming' ? 1 : -1) : 0) }}<small>Totale {{ \App\Support\Money::format($account->amount_cents) }} · saldato {{ \App\Support\Money::format($account->settled_cents) }}</small></td>
                <td><x-ui.icon-button action="open" label="Gestisci sospeso" :href="route('pending.index', ['slug' => $account->slug, 'direction' => $account->direction])" /></td>
            </tr>@empty<tr><td colspan="5" class="register-empty">Nessuna posizione sospesa corrisponde ai filtri.</td></tr>@endforelse</tbody>
        </table></div>
        {{ $pendingEntries->links('components.ui.pagination', ['descriptive' => true, 'numbered' => true]) }}
    </x-ui.data-panel>
@else
    <x-ui.data-panel title="Incassi e rettifiche" :count="$payments->total()" description="Ogni movimento appartiene alla propria data di registrazione. Storni e ripristini contabili non muovono denaro; i rimborsi pagati sono separati.">
        @forelse(collect($paymentSections)->filter(fn ($section) => $section['entries']->total()) as $kind => $category)
            <section class="movement-category" data-payment-category="{{ $kind }}" aria-label="{{ $category['label'] }}">
                <header class="register-subheading"><h3>{{ $category['label'] }}</h3><span class="count-pill">{{ $category['entries']->total() }}</span></header>
                @include('balance.payment-entries', ['entries' => $category['entries']])
            </section>
        @empty<p class="register-empty">Nessun movimento delle spedizioni nel periodo.</p>@endforelse
        @if($generalEntries->total())<div class="register-subheading"><h3>Pagamenti ricevuti senza ordine</h3><p>Già inclusi nelle entrate registrate: {{ \App\Support\Money::format($generalReceipts) }}.</p></div><div class="register-table-wrap"><table class="table register-table" data-table-static><thead><tr><th scope="col">Origine del pagamento</th><th scope="col">Data e operatore</th><th scope="col" class="text-end">Importo</th></tr></thead><tbody>@foreach($generalEntries as $entry)<tr><td><strong class="register-primary">{{ $entry->account->subject }}</strong><span class="register-note">{{ $entry->account->description }}</span>@if(auth()->user()->role === \App\UserRole::Admin)<a class="register-note" href="{{ route('pending.index', ['slug' => $entry->account->slug]) }}">Apri sospeso</a>@endif</td><td>{{ $entry->created_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}<small class="register-note">{{ $entry->user?->name }}</small></td><td class="text-end"><x-ui.signed-money :amount="$entry->amount_cents" /></td></tr>@endforeach</tbody></table></div>{{ $generalEntries->links('components.ui.pagination', ['descriptive' => true, 'numbered' => true]) }}@endif
    </x-ui.data-panel>
@endif
