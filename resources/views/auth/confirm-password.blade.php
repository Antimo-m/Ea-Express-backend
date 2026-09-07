<x-guest-layout title="Conferma password">
    <h2 class="auth-title">Conferma che sei tu.</h2><p class="text-secondary mb-4">Per proseguire, inserisci la password del tuo account.</p>
    <form method="post" action="{{ route('password.confirm') }}" class="form-stack">@csrf
        <x-ui.field name="password" label="Password" type="password" autocomplete="current-password" required autofocus />
        <button class="btn btn-primary" type="submit">Conferma</button>
    </form>
</x-guest-layout>
