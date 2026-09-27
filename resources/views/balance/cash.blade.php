@if(auth()->user()->role === \App\UserRole::Admin)
<section class="surface p-4 my-4" id="cash-register">
    <h2 class="h5">Registra contanti</h2>
    <p class="small text-secondary">Aggiungi contanti ulteriori rispetto agli incassi già registrati nelle consegne.</p>
    <form method="post" action="{{ route('movements.store') }}" data-financial-form class="form-stack">
        @csrf
        <input type="hidden" name="kind" value="cash">
        <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <x-ui.field name="amount" id="cash-create-amount" label="Importo contanti (€)" :value="old('amount')" inputmode="decimal" required />
        <button class="btn btn-primary" type="submit">Registra contanti</button>
    </form>
    @foreach($cashEntries as $entry)
    <article class="d-flex justify-content-between flex-wrap gap-3 border-top pt-3 mt-3">
        <div><strong>{{ \App\Support\Money::format($entry->amount_cents) }}</strong><p class="small text-secondary mb-0">{{ $entry->occurred_on->format('d/m/Y') }} · {{ $entry->user->name }}{{ $entry->voided_at ? ' · Eliminata' : '' }}</p></div>
        @unless($entry->voided_at)
        <div class="row-actions">
            <x-ui.action-dialog :id="'cash-edit-'.$entry->id" title="Modifica contanti" action="edit">
                <form method="post" action="{{ route('movements.update', $entry) }}" data-financial-form class="form-stack">
                    @csrf @method('patch')
                    <input type="hidden" name="kind" value="cash">
                    <input type="hidden" name="version" value="{{ $entry->version }}">
                    <x-ui.field name="amount" :id="'cash-amount-'.$entry->id" label="Importo contanti (€)" :value="number_format($entry->amount_cents / 100, 2, '.', '')" inputmode="decimal" required />
                    <button class="btn btn-primary" type="submit">Salva modifica</button>
                </form>
            </x-ui.action-dialog>
            <form method="post" action="{{ route('movements.destroy', $entry) }}" data-confirm="Elimina registrazione contanti" data-confirm-name="{{ \App\Support\Money::format($entry->amount_cents) }}">
                @csrf @method('delete')
                <input type="hidden" name="version" value="{{ $entry->version }}">
                <x-ui.icon-button action="delete" label="Elimina contanti" type="submit" />
            </form>
        </div>
        @endunless
    </article>
    @endforeach
    {{ $cashEntries->links() }}
</section>
@endif
