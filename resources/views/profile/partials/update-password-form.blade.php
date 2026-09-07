<section aria-labelledby="password-title">
    <h2 id="password-title" class="h5"><x-ui.icon name="shield-lock" class="me-2" />Sicurezza</h2><p class="text-secondary">Scegli una password unica per il tuo account.</p>
    <form method="post" action="{{ route('password.update') }}" class="form-stack">@csrf @method('put')
        <x-ui.field name="current_password" id="current-password" bag="updatePassword" label="Password attuale" type="password" autocomplete="current-password" required />
        <x-ui.field name="password" id="new-password" bag="updatePassword" label="Nuova password" type="password" autocomplete="new-password" minlength="8" help="Almeno 8 caratteri." required />
        <x-ui.field name="password_confirmation" id="confirm-password" bag="updatePassword" label="Conferma nuova password" type="password" autocomplete="new-password" required />
        <div><button class="btn btn-primary" type="submit">Aggiorna password</button></div>
    </form>
</section>
