<section aria-labelledby="delete-title">
    <h2 id="delete-title" class="h5">Elimina account</h2><p class="text-secondary">L'eliminazione è definitiva. Per confermare dovrai inserire la tua password.</p>
    <details @if ($errors->userDeletion->isNotEmpty()) open @endif>
        <x-ui.icon-button as="summary" action="delete" label="Elimina il mio account" />
        <form method="post" action="{{ route('profile.destroy') }}" data-confirm-name="{{ auth()->user()->name }}" class="form-stack mt-3">@csrf @method('delete')
            <x-ui.field name="password" id="delete-password" bag="userDeletion" label="Conferma con la password attuale" type="password" autocomplete="current-password" required />
            <div><x-ui.icon-button action="delete" label="Elimina account" type="submit" /></div>
        </form>
    </details>
</section>
