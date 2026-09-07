<section aria-labelledby="delete-title">
    <h2 id="delete-title" class="h5">Elimina account</h2><p class="text-secondary">L'eliminazione è definitiva. Per confermare dovrai inserire la tua password.</p>
    <details @if ($errors->userDeletion->isNotEmpty()) open @endif>
        <summary class="text-danger py-2">Voglio eliminare il mio account</summary>
        <form method="post" action="{{ route('profile.destroy') }}" class="form-stack mt-3">@csrf @method('delete')
            <x-ui.field name="password" id="delete-password" bag="userDeletion" label="Conferma con la password attuale" type="password" autocomplete="current-password" required />
            <div><button class="btn btn-danger" type="submit"><x-ui.icon name="trash3" class="me-2" />Elimina definitivamente</button></div>
        </form>
    </details>
</section>
