<x-guest-layout title="Attiva il tuo account">
    <header class="staff-login-heading"><span class="staff-login-mark" aria-hidden="true"><x-ui.icon name="person-check" /></span><span class="eyebrow">ATTIVAZIONE ACCOUNT RIDER</span><h1 class="auth-title">Crea la tua password.</h1><p>Scegli una password personale: servirà, insieme al codice di verifica via email, per accedere al gestionale.</p></header>
    <form method="post" action="{{ route('rider.activate') }}" class="form-stack" data-rider-activation>@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email', $email)" autocomplete="username" maxlength="255" required />
        <x-ui.field name="password" id="activation-password" label="Nuova password" type="password" autocomplete="new-password" :minlength="config('auth_security.password.min')" maxlength="72" :help="'Almeno '.config('auth_security.password.min').' caratteri.'" required autofocus />
        <x-ui.field name="password_confirmation" id="activation-password-confirmation" label="Conferma password" type="password" autocomplete="new-password" maxlength="72" required />
        <button class="btn btn-primary auth-submit" type="submit">Attiva l’account <x-ui.icon name="arrow-right" class="ms-2" /></button>
    </form>
</x-guest-layout>
