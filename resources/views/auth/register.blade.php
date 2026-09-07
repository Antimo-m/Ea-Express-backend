<x-guest-layout title="Registrati">
    <span class="eyebrow">IL TUO SPAZIO EA-EXPRESS</span><h1 class="auth-title">Iniziamo, insieme.</h1><p class="text-secondary mb-4">Crea il tuo account per accedere alla piattaforma.</p>
    <form method="post" action="{{ route('register') }}" class="form-stack">@csrf
        <x-ui.field name="name" label="Nome e cognome" :value="old('name')" autocomplete="name" maxlength="255" required autofocus />
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email')" autocomplete="username" maxlength="255" required />
        <x-ui.field name="password" label="Password" type="password" autocomplete="new-password" help="Usa almeno 8 caratteri e una password che non utilizzi altrove." minlength="8" required />
        <x-ui.field name="password_confirmation" label="Conferma password" type="password" autocomplete="new-password" required />
        <button class="btn btn-primary w-100" type="submit">Crea account <x-ui.icon name="arrow-right" class="ms-2" /></button>
    </form>
    <p class="text-center text-secondary mt-4 mb-0">Hai già un account? <a href="{{ route('login') }}">Accedi</a></p>
</x-guest-layout>
