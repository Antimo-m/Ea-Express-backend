<x-guest-layout title="Nuova password">
    <header class="staff-login-heading"><span class="staff-login-mark" aria-hidden="true"><x-ui.icon name="shield-lock" /></span><span class="eyebrow">RIPRISTINO ACCESSO RIDER</span><h1 class="auth-title">Scegli una nuova password.</h1><p>Dopo il salvataggio tutte le sessioni verranno chiuse e al prossimo accesso ti chiederemo il codice di verifica.</p></header>
    <form method="post" action="{{ route('password.store') }}" class="form-stack" data-password-reset>@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email', $email)" autocomplete="username" maxlength="255" required />
        <x-ui.field name="password" id="reset-password" label="Nuova password" type="password" autocomplete="new-password" :minlength="config('auth_security.password.min')" maxlength="72" :help="'Almeno '.config('auth_security.password.min').' caratteri.'" required autofocus />
        <x-ui.field name="password_confirmation" id="reset-password-confirmation" label="Conferma nuova password" type="password" autocomplete="new-password" maxlength="72" required />
        <button class="btn btn-primary auth-submit" type="submit">Salva la nuova password <x-ui.icon name="arrow-right" class="ms-2" /></button>
    </form>
    <p class="mt-3 mb-0"><a href="{{ route('password.request') }}">Richiedi un nuovo link</a></p>
</x-guest-layout>
