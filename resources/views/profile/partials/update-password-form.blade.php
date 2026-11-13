<section aria-labelledby="password-title">
    <h2 id="password-title" class="h5"><x-ui.icon name="shield-lock" class="me-2" />Sicurezza</h2>
    @if(auth()->user()->role === \App\UserRole::Admin)
    <p class="text-secondary mb-0" data-admin-password-locked>Per proteggere l’account Admin, la password non si può modificare né recuperare dal gestionale: viene gestita soltanto dal terminale del server.</p>
    @else
    <p class="text-secondary">Scegli una password unica per il tuo account.</p>
    <form method="post" action="{{ route('password.update') }}" class="form-stack">@csrf @method('put')
        <x-ui.field name="current_password" id="current-password" bag="updatePassword" label="Password attuale" type="password" autocomplete="current-password" maxlength="72" required />
        <x-ui.field name="password" id="new-password" bag="updatePassword" label="Nuova password" type="password" autocomplete="new-password" :minlength="config('auth_security.password.min')" maxlength="72" :help="'Almeno '.config('auth_security.password.min').' caratteri.'" required />
        <x-ui.field name="password_confirmation" id="confirm-password" bag="updatePassword" label="Conferma nuova password" type="password" autocomplete="new-password" maxlength="72" required />
        <div><x-ui.icon-button action="confirm" label="Aggiorna password" type="submit" /></div>
    </form>
    @endif
</section>
