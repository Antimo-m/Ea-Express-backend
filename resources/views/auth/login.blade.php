<x-guest-layout title="Accedi">
    <header class="staff-login-heading"><span class="staff-login-mark" aria-hidden="true"><x-ui.icon name="shield-lock" /></span><span class="eyebrow">ACCESSO AL GESTIONALE</span><h1 class="auth-title">Il tuo spazio di lavoro.</h1><p>Accedi per organizzare la prossima consegna.</p></header>
    <form method="post" action="{{ route('login') }}" class="form-stack" data-auth-form>@csrf
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email')" autocomplete="username" required autofocus />
        <x-ui.field name="password" label="Password" type="password" autocomplete="current-password" required />
        <label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="remember" @checked(old('remember'))><span class="form-check-label">Ricordami</span></label>
        <button class="btn btn-primary auth-submit" type="submit">Accedi <x-ui.icon name="arrow-right" class="ms-2" /></button>
    </form>
    <p class="staff-login-help">Riservato ad amministratori e Rider EA-Express.<br>Per un account, contatta il responsabile.</p>
</x-guest-layout>
