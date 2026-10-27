<x-ui.modal id="balance-add" title="Nuova operazione" description="Seleziona l’operazione che desideri effettuare." data-finance-wizard>
    <div class="modal-content-area">
        <div data-finance-step="choose" class="operation-choices">
            <button type="button" class="operation-choice" data-finance-go="expense"><x-ui.icon name="receipt" /><span><strong>Registra una spesa</strong><small>Descrizione, importo e data della spesa.</small></span><x-ui.icon name="arrow-right" /></button>
            @if(auth()->user()->role === \App\UserRole::Admin)<button type="button" class="operation-choice" data-finance-go="cash"><x-ui.icon name="cash-stack" /><span><strong>Registra un’entrata di contanti</strong><small>Contanti aggiuntivi, separati dagli incassi degli ordini.</small></span><x-ui.icon name="arrow-right" /></button>@endif
        </div>
        <div data-finance-step="expense" hidden><h3 class="operation-form-title">Registra spesa</h3>
            <form method="post" action="{{ route('expenses.store') }}" data-financial-form class="form-stack">
                @csrf<input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <x-ui.field name="description" id="expense-create-description" label="Descrizione" maxlength="200" required />
                <x-ui.field name="amount" id="expense-create-amount" label="Importo (€)" inputmode="decimal" required />
                <x-ui.field name="spent_on" id="expense-create-date" label="Data della spesa" type="date" :value="now('Europe/Rome')->toDateString()" :max="now('Europe/Rome')->toDateString()" required />
                <footer class="modal-actions"><button type="button" class="btn modal-back" data-finance-go="choose">Cambia operazione</button><x-ui.icon-button action="receive" icon="receipt" label="Registra spesa" type="submit" text /></footer>
            </form>
        </div>
        @if(auth()->user()->role === \App\UserRole::Admin)<div data-finance-step="cash" hidden><h3 class="operation-form-title">Registra contanti</h3><p>Questa voce non registra il pagamento di una spedizione.</p>
            <form method="post" action="{{ route('movements.store') }}" data-financial-form class="form-stack">
                @csrf<input type="hidden" name="kind" value="cash"><input type="hidden" name="submission_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <x-ui.field name="amount" id="cash-create-amount" label="Importo contanti (€)" inputmode="decimal" required />
                <footer class="modal-actions"><button type="button" class="btn modal-back" data-finance-go="choose">Cambia operazione</button><x-ui.icon-button action="receive" label="Registra contanti" type="submit" text /></footer>
            </form>
        </div>@endif
    </div>
</x-ui.modal>
