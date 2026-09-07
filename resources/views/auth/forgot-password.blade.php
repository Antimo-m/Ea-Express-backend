<x-guest-layout title="Recupera password">
    <a class="back-link" href="{{ route('login') }}"><x-ui.icon name="arrow-left" /> Torna al login</a>
    <h1 class="auth-title mt-4">Ritrova l'accesso.</h1><p class="text-secondary mb-4">Inserisci la tua email. Ti invieremo un link per scegliere una nuova password.</p>
    <form method="post" action="{{ route('password.email') }}" class="form-stack">@csrf
        <x-ui.field name="email" label="Indirizzo email" type="email" :value="old('email')" autocomplete="email" required autofocus />
        <button class="btn btn-primary w-100" type="submit">Invia link di recupero <x-ui.icon name="envelope" class="ms-2" /></button>
    </form>
</x-guest-layout>
