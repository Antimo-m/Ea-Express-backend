<x-ui.modal id="pending-create" title="Registra sospeso" description="Registra un credito o un debito e i riferimenti utili per seguirlo."><div class="modal-content-area"><form method="post" action="{{ route('pending.store') }}" class="form-stack mt-3" data-financial-form>@csrf<div class="field-grid"><x-ui.field name="subject" label="Soggetto" required maxlength="150"/><div><label for="direction" class="form-label">Direzione</label><select class="form-select" name="direction" id="direction"><option value="incoming">Entrata · ci devono denaro</option><option value="outgoing">Uscita · dobbiamo denaro</option></select></div><x-ui.field name="amount" label="Importo (€)" inputmode="decimal" required/><x-ui.field name="description" label="Descrizione" required maxlength="500"/><x-ui.field name="occurred_on" label="Data" type="date" :value="now('Europe/Rome')->toDateString()" required/><x-ui.field name="due_on" label="Scadenza" type="date"/><div><label class="form-label" for="customer_id">Cliente collegato</label><select class="form-select" id="customer_id" name="customer_id"><option value="">Nessuno</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }}</option>@endforeach</select></div><x-ui.field name="order_id" label="ID ordine (facoltativo)" type="number" min="1" help="Per un ordine consegnato, inserisci il residuo esatto. Il saldo verrà registrato anche nei suoi incassi."/></div><x-ui.field name="notes" label="Note" maxlength="4000"/><x-ui.icon-button action="add" label="Registra sospeso" type="submit" /></form></div></x-ui.modal>
@foreach($accounts as $account)
    @if(in_array($account->state, ['open', 'partially_paid']))
        <x-ui.modal :id="'pending-settle-'.$account->id" title="Registra pagamento" :description="$account->subject"><div class="modal-content-area">
            <p class="amount-caption">Residuo <strong>{{ \App\Support\Money::format($account->amount_cents - $account->settled_cents) }}</strong></p>
            <form method="post" action="{{ route('pending.settle', $account) }}" data-financial-form class="form-stack">@csrf
                <input type="hidden" name="version" value="{{ $account->version }}"><input type="hidden" name="submission_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                <x-ui.field :id="'settle-amount-'.$account->id" name="amount" label="Importo da saldare (€)" inputmode="decimal" :value="number_format(($account->amount_cents-$account->settled_cents)/100,2,'.','')" required />
                <x-ui.icon-button action="receive" label="Registra pagamento" type="submit" text />
            </form>
        </div></x-ui.modal>
        <x-ui.modal :id="'pending-edit-'.$account->id" title="Modifica sospeso" :description="$account->subject"><div class="modal-content-area">
            <form method="post" action="{{ route('pending.update', $account) }}" data-financial-form class="form-stack">@csrf @method('patch')
                <input type="hidden" name="version" value="{{ $account->version }}">
                <x-ui.field :id="'edit-subject-'.$account->id" name="subject" label="Soggetto" :value="$account->subject" maxlength="150" required />
                @unless($account->order_id)<x-ui.field :id="'edit-amount-'.$account->id" name="amount" label="Importo totale (€)" inputmode="decimal" :value="number_format($account->amount_cents/100,2,'.','')" required />@endunless
                <x-ui.field :id="'edit-description-'.$account->id" name="description" label="Descrizione" :value="$account->description" maxlength="500" required />
                <x-ui.field :id="'edit-due-'.$account->id" name="due_on" label="Scadenza" type="date" :value="$account->due_on?->toDateString()" />
                <x-ui.field :id="'edit-notes-'.$account->id" name="notes" label="Note" :value="$account->notes" maxlength="4000" />
                <div class="row-actions"><x-ui.icon-button action="edit" label="Salva dati" type="submit" name="action" value="update" text />@unless($account->settled_cents)<x-ui.icon-button action="delete" label="Annulla sospeso" type="submit" name="action" value="cancel" data-confirm="Annulla sospeso" :data-confirm-name="$account->subject" />@endunless</div>
            </form>
        </div></x-ui.modal>
    @endif
    <x-ui.modal :id="'pending-history-'.$account->id" class="ea-modal-wide" title="Storico pagamenti" :description="$account->subject">
        <div class="modal-content-area">
            <div class="history-summary"><x-ui.pending-status :account="$account" /><span>Pagato <strong>{{ \App\Support\Money::format($account->settled_cents) }}</strong></span><span>Residuo <strong>{{ \App\Support\Money::format($account->amount_cents-$account->settled_cents) }}</strong></span></div>
            <p class="small text-secondary">Le correzioni aggiornano il bilancio alla data del pagamento originale. Il motivo e gli importi precedenti restano nello storico modifiche.</p>
            <div class="table-responsive"><table class="table workspace-table" data-table-static><thead><tr><th>Pagamento</th><th>Operatore</th><th>Importo</th><th>Azioni</th></tr></thead><tbody>
                @forelse($account->settlements as $settlement)<tr>
                    <td data-label="Pagamento"><strong>{{ $settlement->created_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</strong><small class="d-block text-secondary">Riferimento #{{ $settlement->id }}</small>@if($settlement->note)<small>{{ $settlement->note }}</small>@endif @if($settlement->updated_at->gt($settlement->created_at))<span class="small d-block text-secondary">Corretto il {{ $settlement->updated_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</span>@endif</td>
                    <td data-label="Operatore"><small class="d-block text-secondary">{{ $settlement->user?->name }}</small></td>
                    <td data-label="Importo"><x-ui.signed-money :amount="$settlement->amount_cents * ($account->direction === 'incoming' ? 1 : -1)" /></td>
                    <td data-label="Azioni"><div class="row-actions">@if($account->state !== 'cancelled')<x-ui.icon-button action="edit" label="Correggi pagamento" :data-dialog-open="'settlement-edit-'.$settlement->id" :aria-controls="'settlement-edit-'.$settlement->id" aria-haspopup="dialog" />@endif<x-ui.icon-button action="history" label="Storico modifiche pagamento" :href="route('audits.index', ['type'=>'pending_settlements','id'=>$settlement->id])" /></div></td>
                </tr>@empty<tr><td colspan="4">Nessun pagamento registrato.</td></tr>@endforelse
            </tbody></table></div>
        </div>
    </x-ui.modal>
    @foreach($account->settlements as $settlement)
        @if($account->state !== 'cancelled')
        <x-ui.modal :id="'settlement-edit-'.$settlement->id" title="Correggi pagamento" :description="$account->subject.' · pagamento #'.$settlement->id"><div class="modal-content-area">
            <form method="post" action="{{ route('pending.settlements.update', [$account, $settlement]) }}" data-financial-form class="form-stack">@csrf @method('patch')<input type="hidden" name="version" value="{{ $account->version }}">
                <x-ui.field :id="'correction-amount-'.$settlement->id" name="amount" label="Importo corretto (€)" :value="number_format($settlement->amount_cents/100,2,'.','')" inputmode="decimal" required />
                <x-ui.field :id="'correction-reason-'.$settlement->id" name="reason" label="Motivo della correzione" maxlength="500" required />
                <p class="small text-secondary">Verranno aggiornati totale pagato, residuo, stato e movimento contabile collegato.</p>
                <x-ui.icon-button action="edit" label="Conferma correzione" type="submit" text />
            </form>
        </div></x-ui.modal>
        @endif
    @endforeach
@endforeach
