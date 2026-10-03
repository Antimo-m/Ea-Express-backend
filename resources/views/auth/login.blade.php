<x-guest-layout title="Accedi">
    <span class="eyebrow">ACCESSO AL GESTIONALE</span><h1 class="auth-title">Bentornato in<br>EA-Express.</h1><p class="text-secondary mb-4">Ordini, ritiri e consegne nel tuo spazio di lavoro.</p>
    <form method="post" action="{{ route('login') }}" class="form-stack" data-auth-form>@csrf
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email')" autocomplete="username" required autofocus />
        <x-ui.field name="password" label="Password" type="password" autocomplete="current-password" required />
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="remember" @checked(old('remember'))><span class="form-check-label">Ricordami</span></label><a href="{{ route('password.request') }}">Password dimenticata?</a></div>
        <button class="btn btn-primary auth-submit" type="submit">Accedi <x-ui.icon name="arrow-right" class="ms-2" /></button>
    </form>
    <p class="text-center text-secondary mt-4 mb-0">Accesso riservato ad amministratori e Rider EA-Express. Per un account, contatta il responsabile.</p>
</x-guest-layout>
