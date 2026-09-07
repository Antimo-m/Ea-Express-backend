<x-guest-layout title="Accedi">
    <span class="eyebrow">BENTORNATO</span><h1 class="auth-title">La tua giornata<br>parte da qui.</h1><p class="text-secondary mb-4">Accedi al tuo spazio EA-Express.</p>
    <form method="post" action="{{ route('login') }}" class="form-stack">@csrf
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email')" autocomplete="username" required autofocus />
        <x-ui.field name="password" label="Password" type="password" autocomplete="current-password" required />
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="remember" @checked(old('remember'))><span class="form-check-label">Ricordami</span></label><a href="{{ route('password.request') }}">Password dimenticata?</a></div>
        <button class="btn btn-primary w-100" type="submit">Accedi <x-ui.icon name="arrow-right" class="ms-2" /></button>
    </form>
    <p class="text-center text-secondary mt-4 mb-0">Non hai un account? <a href="{{ route('register') }}">Registrati</a></p>
</x-guest-layout>
